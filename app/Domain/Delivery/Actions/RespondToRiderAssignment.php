<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RespondToRiderAssignment (02-93): the rider accepts or declines a
 * pending assignment. Authorized for the rider's own linked user or
 * any holder of sales.delivery.riders (responding on their behalf).
 * Terminal: an already-responded assignment is refused. No stock/GL
 * effect — handover remains the dispatch step (02-89).
 */
class RespondToRiderAssignment
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected PermissionCatalog $permissions,
    ) {}

    public function handle(RiderAssignment $assignment, bool $accept, Request $request): RiderAssignment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $assignment->company_id !== $companyId) {
            throw new RuntimeException('Assignment not found for this company.');
        }

        $user = $request->user();

        if (! $this->canRespond($assignment, $user)) {
            abort(403, 'You can only respond to your own rider assignments.');
        }

        return DB::transaction(function () use ($assignment, $accept, $user) {
            $fresh = RiderAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== RiderAssignment::STATUS_PENDING) {
                throw new RuntimeException(sprintf(
                    'Assignment already responded (%s); only pending assignments can be answered.',
                    $fresh->status,
                ));
            }

            $fresh->status = $accept
                ? RiderAssignment::STATUS_ACCEPTED
                : RiderAssignment::STATUS_DECLINED;
            $fresh->responded_at = now();
            $fresh->responded_by = $user->id;
            $fresh->save();

            $this->audit->record([
                'action' => $accept ? 'sales.rider_assignment_accepted' : 'sales.rider_assignment_declined',
                'entity_type' => 'rider_assignment',
                'entity_id' => $fresh->id,
                'actor_id' => $user->id,
                'before' => ['status' => RiderAssignment::STATUS_PENDING],
                'after' => [
                    'status' => $fresh->status,
                    'rider_employee_id' => $fresh->rider_employee_id,
                    'shipment_id' => $fresh->shipment_id,
                ],
            ]);

            return $fresh;
        });
    }

    /** Rider-self (linked user) or sales.delivery.riders holder. */
    protected function canRespond(RiderAssignment $assignment, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->permissions->allows($user, 'sales.delivery.riders')) {
            return true;
        }

        if ($assignment->rider_employee_id === null) {
            return false; // legacy name-only row: no rider identity to match
        }

        $employee = $assignment->rider()->first();

        return $employee !== null && (int) $employee->user_id === (int) $user->id;
    }
}
