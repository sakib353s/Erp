<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\Customer;
use App\Domain\People\Employee;
use App\Domain\Sales\SalesCallLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LogSalesCall (02-84). Persist an outbound/inbound CRM call against a
 * salesperson (+ optional customer). DOC only — no GL/stock.
 */
class LogSalesCall
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   employee_id: int,
     *   customer_id?: int|null,
     *   call_date?: string|null,
     *   direction?: string,
     *   outcome?: string,
     *   subject?: string|null,
     *   notes?: string|null,
     *   duration_minutes?: int,
     * } $payload
     */
    public function handle(array $payload, Request $request): SalesCallLog
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);
        if ($employee === null) {
            throw new RuntimeException('Employee not found for this company.');
        }

        $customerId = $payload['customer_id'] ?? null;
        if ($customerId !== null) {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->find((int) $customerId);
            if ($customer === null) {
                throw new RuntimeException('Customer not found for this company.');
            }
        }

        $direction = (string) ($payload['direction'] ?? 'outbound');
        if (! in_array($direction, SalesCallLog::DIRECTIONS, true)) {
            throw new RuntimeException('Call direction must be inbound or outbound.');
        }

        $outcome = (string) ($payload['outcome'] ?? 'connected');
        if (! in_array($outcome, SalesCallLog::OUTCOMES, true)) {
            throw new RuntimeException('Invalid call outcome.');
        }

        $duration = max(0, (int) ($payload['duration_minutes'] ?? 0));
        if ($duration > 1440) {
            throw new RuntimeException('Call duration cannot exceed 1440 minutes.');
        }

        return DB::transaction(function () use ($payload, $companyId, $employee, $customerId, $direction, $outcome, $duration, $request) {
            $call = SalesCallLog::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'employee_id' => $employee->id,
                'customer_id' => $customerId,
                'call_date' => $payload['call_date'] ?? now()->toDateString(),
                'direction' => $direction,
                'outcome' => $outcome,
                'subject' => $payload['subject'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'duration_minutes' => $duration,
                'created_by' => $request->user()->id,
            ]);

            $this->audit->record([
                'action' => 'sales.call_logged',
                'entity_type' => 'sales_call_log',
                'entity_id' => $call->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'employee_id' => $employee->id,
                    'customer_id' => $customerId,
                    'direction' => $direction,
                    'outcome' => $outcome,
                    'call_date' => $call->call_date?->toDateString(),
                ],
            ]);

            return $call;
        });
    }
}
