<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AssignRider (02-93): link a roster rider to a shipment with the
 * response workflow — the assignment starts `pending` until the
 * rider accepts or declines (or a sales.delivery.riders holder
 * responds on their behalf). No stock/GL effect at assignment
 * (handover happens at dispatch, 02-89). The rider's linked user
 * gets a truthful in-app request.
 */
class AssignRider
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected NotificationCenter $notifications,
    ) {}

    public function handle(Shipment $shipment, RiderProfile $profile, Request $request): RiderAssignment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $shipment->company_id !== $companyId) {
            throw new RuntimeException('Shipment not found for this company.');
        }

        if ((int) $profile->company_id !== $companyId) {
            throw new RuntimeException('Rider not found for this company.');
        }

        if (! $profile->is_active) {
            throw new RuntimeException('Rider is inactive.');
        }

        $employee = $profile->employee()->first();
        if ($employee === null || $employee->status !== 'active') {
            throw new RuntimeException('Rider employee is not active.');
        }

        return DB::transaction(function () use ($shipment, $employee, $request) {
            $active = RiderAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', [RiderAssignment::STATUS_PENDING, RiderAssignment::STATUS_ACCEPTED])
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw new RuntimeException('Shipment already has an active rider assignment.');
            }

            $assignment = RiderAssignment::query()->create([
                'company_id' => (int) $shipment->company_id,
                'shipment_id' => $shipment->id,
                'rider_name' => $employee->full_name,
                'rider_employee_id' => $employee->id,
                'status' => RiderAssignment::STATUS_PENDING,
                'assigned_by' => $request->user()?->id,
            ]);

            $this->audit->record([
                'action' => 'sales.rider_assigned',
                'entity_type' => 'rider_assignment',
                'entity_id' => $assignment->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'shipment_id' => $shipment->id,
                    'rider_employee_id' => $employee->id,
                    'rider_name' => $employee->full_name,
                    'status' => $assignment->status,
                ],
            ]);

            $this->notifyRider($assignment, $employee->full_name);

            return $assignment;
        });
    }

    /** In-app assignment request to the rider's linked user (retry-safe). */
    protected function notifyRider(RiderAssignment $assignment, string $riderName): void
    {
        $employee = $assignment->rider()->first();
        $userId = $employee?->user_id;
        if ($userId === null) {
            return;
        }

        $riderUser = User::query()->find($userId);
        if ($riderUser === null || ! $riderUser->isActive()) {
            return;
        }

        $this->notifications->notify(
            $riderUser,
            'sales.rider_assignment',
            'Rider assignment awaiting your response — Shipment #'.$assignment->shipment_id,
            'You have been assigned as rider. Accept or decline the assignment.',
            [
                'action_url' => '/app/sales/delivery/rider-assignments',
                'dedupe_key' => 'rider.assign.'.$assignment->id,
                'data' => [
                    'rider_assignment_id' => $assignment->id,
                    'shipment_id' => $assignment->shipment_id,
                    'rider_name' => $riderName,
                ],
            ],
        );
    }
}
