<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\People\Employee;
use App\Domain\Sales\SalesTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SetSalesTarget (02-79). Upserts a daily|monthly|yearly target for an
 * employee for the resolved period window. DOC/config only — no GL.
 */
class SetSalesTarget
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   employee_id: int,
     *   period_type: string,
     *   target_amount: float,
     *   at?: string|null,
     *   notes?: string|null,
     * } $payload
     */
    public function handle(array $payload, Request $request): SalesTarget
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $periodType = (string) ($payload['period_type'] ?? '');
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            throw new RuntimeException('Period type must be daily, monthly, or yearly.');
        }

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);

        if ($employee === null) {
            throw new RuntimeException('Employee not found for this company.');
        }

        if (! $employee->is_salesperson && ! $employee->is_technician) {
            // Allow targets on any active employee; still require active employment
            if ($employee->employment_status !== 'active') {
                throw new RuntimeException("Employee {$employee->code} is not active.");
            }
        }

        $amount = (float) ($payload['target_amount'] ?? 0);
        if ($amount < 0) {
            throw new RuntimeException('Target amount cannot be negative.');
        }

        $bounds = SalesTarget::boundsFor($periodType, $payload['at'] ?? null);

        return DB::transaction(function () use ($payload, $companyId, $employee, $periodType, $amount, $bounds, $request) {
            $existing = SalesTarget::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('period_type', $periodType)
                ->whereDate('period_start', $bounds['period_start'])
                ->lockForUpdate()
                ->first();

            $before = $existing?->toArray();

            $target = $existing ?? new SalesTarget([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'period_type' => $periodType,
                'period_start' => $bounds['period_start'],
                'period_end' => $bounds['period_end'],
            ]);

            $target->fill([
                'company_id' => $companyId,
                'branch_id' => $target->branch_id ?? $request->user()->default_branch_id,
                'employee_id' => $employee->id,
                'period_type' => $periodType,
                'period_start' => $bounds['period_start'],
                'period_end' => $bounds['period_end'],
                'target_amount' => number_format($amount, 4, '.', ''),
                'notes' => $payload['notes'] ?? $target->notes,
                'created_by' => $target->created_by ?? $request->user()->id,
            ]);
            $target->save();

            $this->audit->record([
                'action' => $existing !== null ? 'sales.target_updated' : 'sales.target_created',
                'entity_type' => 'sales_target',
                'entity_id' => $target->id,
                'actor_id' => $request->user()->id,
                'before' => $before,
                'after' => [
                    'employee_id' => $employee->id,
                    'period_type' => $periodType,
                    'period_start' => $bounds['period_start'],
                    'period_end' => $bounds['period_end'],
                    'target_amount' => $amount,
                ],
            ]);

            return $target;
        });
    }
}
