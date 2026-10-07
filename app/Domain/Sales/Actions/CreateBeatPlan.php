<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Customer;
use App\Domain\People\Employee;
use App\Domain\Sales\BeatPlan;
use App\Domain\Sales\BeatPlanStop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateBeatPlan (02-86). Plan a rep route with ordered stops.
 * DOC only — no GL/stock.
 */
class CreateBeatPlan
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   employee_id: int,
     *   name: string,
     *   plan_date?: string|null,
     *   notes?: string|null,
     *   stops?: array<int, array{customer_id?: int|null, label?: string|null, notes?: string|null}>,
     * } $payload
     */
    public function handle(array $payload, Request $request): BeatPlan
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);
        if ($employee === null) {
            throw new RuntimeException('Employee not found for this company.');
        }

        $stops = $payload['stops'] ?? [];
        if (! is_array($stops) || $stops === []) {
            throw new RuntimeException('Beat plan requires at least one stop.');
        }
        if (count($stops) > 100) {
            throw new RuntimeException('Beat plan cannot exceed 100 stops.');
        }

        return DB::transaction(function () use ($payload, $companyId, $employee, $stops, $request) {
            $plan = BeatPlan::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'employee_id' => $employee->id,
                'name' => (string) $payload['name'],
                'plan_date' => $payload['plan_date'] ?? now()->toDateString(),
                'status' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $sequence = 1;
            foreach ($stops as $stop) {
                $customerId = $stop['customer_id'] ?? null;
                if ($customerId !== null) {
                    $customer = Customer::query()
                        ->where('company_id', $companyId)
                        ->find((int) $customerId);
                    if ($customer === null) {
                        throw new RuntimeException('Customer not found for this company.');
                    }
                }

                BeatPlanStop::create([
                    'beat_plan_id' => $plan->id,
                    'sequence_no' => $sequence++,
                    'customer_id' => $customerId,
                    'territory_id' => $stop['territory_id'] ?? null,
                    'label' => $stop['label'] ?? null,
                    'status' => 'pending',
                    'notes' => $stop['notes'] ?? null,
                ]);
            }

            $this->audit->record([
                'action' => 'sales.beat_plan_created',
                'entity_type' => 'beat_plan',
                'entity_id' => $plan->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'employee_id' => $employee->id,
                    'name' => $plan->name,
                    'plan_date' => $plan->plan_date?->toDateString(),
                    'stop_count' => $sequence - 1,
                ],
            ]);

            return $plan->load('stops');
        });
    }
}
