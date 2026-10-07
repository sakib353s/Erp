<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RecordRiderCodCollection (02-93): cash a rider says they collected
 * against an accepted assignment. Only accepted assignments qualify
 * (the rider confirmed the handoff); amounts are positive; no
 * accounting posting happens here — reconciliation lands with 02-97.
 */
class RecordRiderCodCollection
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{amount: float|int|string, collected_at?: string|null, notes?: ?string}  $payload
     */
    public function handle(RiderAssignment $assignment, array $payload, Request $request): RiderCodCollection
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $assignment->company_id !== $companyId) {
            throw new RuntimeException('Assignment not found for this company.');
        }

        if ($assignment->rider_employee_id === null) {
            throw new RuntimeException('Assignment has no roster rider; COD cannot be recorded against a name-only row.');
        }

        if ($assignment->status !== RiderAssignment::STATUS_ACCEPTED) {
            throw new RuntimeException(sprintf(
                'COD can only be collected against an accepted assignment (current status: %s).',
                $assignment->status,
            ));
        }

        $amount = round((float) $payload['amount'], 2);
        if ($amount <= 0) {
            throw new RuntimeException('COD amount must be greater than zero.');
        }

        return DB::transaction(function () use ($assignment, $payload, $amount, $companyId, $request) {
            $collectedAt = $payload['collected_at'] ?? null;

            $collection = RiderCodCollection::query()->create([
                'company_id' => $companyId,
                'rider_assignment_id' => $assignment->id,
                'rider_employee_id' => $assignment->rider_employee_id,
                'amount' => $amount,
                'collected_at' => $collectedAt !== null
                    ? date(DATE_ATOM, strtotime((string) $collectedAt))
                    : now(),
                'notes' => $payload['notes'] ?? null,
                'recorded_by' => $request->user()?->id,
            ]);

            $this->audit->record([
                'action' => 'sales.rider_cod_collected',
                'entity_type' => 'rider_cod_collection',
                'entity_id' => $collection->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'rider_assignment_id' => $assignment->id,
                    'rider_employee_id' => $assignment->rider_employee_id,
                    'shipment_id' => $assignment->shipment_id,
                    'amount' => $amount,
                    'collected_at' => $collection->collected_at->toIso8601String(),
                ],
            ]);

            return $collection;
        });
    }
}
