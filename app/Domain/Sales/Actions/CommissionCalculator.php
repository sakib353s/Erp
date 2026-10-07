<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\People\Employee;
use App\Domain\Sales\CommissionCalculation;
use App\Domain\Sales\CommissionRule;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CommissionCalculator (02-81 rules + calculations). Computes earned
 * commission from real attributed invoice revenue for a period window
 * and upserts a commission_calculations row. Optional accrual GL via
 * commission_accrued posting rule (Dr expense, Cr payable).
 */
class CommissionCalculator
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
    ) {}

    /**
     * Create or update a commission rule (config only — no GL).
     *
     * @param array{
     *   employee_id?: int|null,
     *   name: string,
     *   rule_type: string,
     *   rate?: float,
     *   fixed_amount?: float,
     *   period_type?: string,
     *   is_active?: bool,
     *   notes?: string|null,
     * } $payload
     */
    public function createRule(array $payload, Request $request): CommissionRule
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $ruleType = (string) ($payload['rule_type'] ?? '');
        if (! in_array($ruleType, CommissionRule::TYPES, true)) {
            throw new RuntimeException('Rule type must be percent_of_revenue or fixed_per_period.');
        }

        $periodType = (string) ($payload['period_type'] ?? 'monthly');
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            throw new RuntimeException('Period type must be daily, monthly, or yearly.');
        }

        $rate = (float) ($payload['rate'] ?? 0);
        $fixed = (float) ($payload['fixed_amount'] ?? 0);
        if ($ruleType === 'percent_of_revenue' && ($rate < 0 || $rate > 100)) {
            throw new RuntimeException('Percent rate must be between 0 and 100.');
        }
        if ($ruleType === 'fixed_per_period' && $fixed < 0) {
            throw new RuntimeException('Fixed amount cannot be negative.');
        }

        $employeeId = $payload['employee_id'] ?? null;
        if ($employeeId !== null) {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->find((int) $employeeId);
            if ($employee === null) {
                throw new RuntimeException('Employee not found for this company.');
            }
        }

        return DB::transaction(function () use ($payload, $companyId, $ruleType, $periodType, $rate, $fixed, $employeeId, $request) {
            $rule = CommissionRule::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'employee_id' => $employeeId,
                'name' => (string) $payload['name'],
                'rule_type' => $ruleType,
                'rate' => number_format($rate, 4, '.', ''),
                'fixed_amount' => number_format($fixed, 4, '.', ''),
                'period_type' => $periodType,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->audit->record([
                'action' => 'sales.commission_rule_created',
                'entity_type' => 'commission_rule',
                'entity_id' => $rule->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'name' => $rule->name,
                    'rule_type' => $rule->rule_type,
                    'rate' => (float) $rule->rate,
                    'fixed_amount' => (float) $rule->fixed_amount,
                    'period_type' => $rule->period_type,
                ],
            ]);

            return $rule;
        });
    }

    /**
     * Calculate commission for one employee/rule for a period.
     * Upserts commission_calculations; accrues GL when commission_accrued
     * posting rule exists (Dr expense, Cr commission payable).
     *
     * @param array{
     *   employee_id: int,
     *   commission_rule_id?: int|null,
     *   period_type?: string,
     *   at?: string|null,
     * } $payload
     */
    public function calculate(array $payload, Request $request): CommissionCalculation
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);
        if ($employee === null) {
            throw new RuntimeException('Employee not found for this company.');
        }

        $periodType = (string) ($payload['period_type'] ?? 'monthly');
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            throw new RuntimeException('Period type must be daily, monthly, or yearly.');
        }

        $ruleId = $payload['commission_rule_id'] ?? null;
        $rule = CommissionRule::query()
            ->where('company_id', $companyId)
            ->when($ruleId !== null, fn ($q) => $q->whereKey((int) $ruleId))
            ->where('is_active', true)
            ->where('period_type', $periodType)
            ->where(function ($q) use ($employeeId) {
                $q->whereNull('employee_id')->orWhere('employee_id', $employeeId);
            })
            ->orderByRaw('employee_id IS NULL') // prefer employee-specific
            ->orderByDesc('id')
            ->first();

        if ($rule === null) {
            throw new RuntimeException('No active commission rule for this employee/period.');
        }

        $bounds = SalesTarget::boundsFor($periodType, $payload['at'] ?? null);

        $base = (float) Invoice::query()
            ->where('company_id', $companyId)
            ->where('sales_person_id', $employeeId)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereDate('invoice_date', '>=', $bounds['period_start'])
            ->whereDate('invoice_date', '<=', $bounds['period_end'])
            ->sum('grand_total');

        $rate = (float) $rule->rate;
        $commission = $rule->rule_type === 'percent_of_revenue'
            ? round($base * $rate / 100, 4)
            : round((float) $rule->fixed_amount, 4);

        if ($commission < 0) {
            throw new RuntimeException('Commission amount cannot be negative.');
        }

        return DB::transaction(function () use (
            $payload, $companyId, $employee, $rule, $periodType, $bounds, $base, $rate, $commission, $request
        ) {
            $existing = CommissionCalculation::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('commission_rule_id', $rule->id)
                ->where('period_type', $periodType)
                ->whereDate('period_start', $bounds['period_start'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null && in_array($existing->status, ['paid', 'pending_approval'], true)) {
                throw new RuntimeException("Commission calculation is {$existing->status} and cannot be recalculated.");
            }

            $calc = $existing ?? new CommissionCalculation([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'commission_rule_id' => $rule->id,
                'period_type' => $periodType,
                'period_start' => $bounds['period_start'],
                'period_end' => $bounds['period_end'],
            ]);

            $calc->fill([
                'company_id' => $companyId,
                'branch_id' => $calc->branch_id ?? $request->user()->default_branch_id,
                'employee_id' => $employee->id,
                'commission_rule_id' => $rule->id,
                'period_type' => $periodType,
                'period_start' => $bounds['period_start'],
                'period_end' => $bounds['period_end'],
                'base_amount' => number_format($base, 4, '.', ''),
                'rate' => number_format($rule->rule_type === 'percent_of_revenue' ? $rate : 0, 4, '.', ''),
                'commission_amount' => number_format($commission, 4, '.', ''),
                'status' => 'accrued',
                'notes' => $payload['notes'] ?? $calc->notes,
                'created_by' => $calc->created_by ?? $request->user()->id,
            ]);

            // Accrue when posting rule configured (Dr expense, Cr payable)
            try {
                $resolved = $this->rules->resolve('commission_accrued');
                $byRole = [];
                foreach ($resolved as $r) {
                    $byRole[$r['role']] = $r;
                }
                if (isset($byRole['commission_expense'], $byRole['commission_payable']) && $commission > 0) {
                    if ($calc->journal_entry_id === null) {
                        $entry = $this->posting->post([
                            'entry_date' => $bounds['period_end'],
                            'description' => "Commission accrual {$employee->code} {$periodType} {$bounds['period_start']}",
                            'journal_type' => 'commission',
                            'source_type' => 'commission_calculation',
                            'source_id' => $calc->getKey() ?: null,
                            'source_event' => 'commission_accrued',
                            'branch_id' => $calc->branch_id,
                            'lines' => [
                                [
                                    'account_id' => $byRole['commission_expense']['account']->id,
                                    'dc' => 'debit',
                                    'amount' => $commission,
                                ],
                                [
                                    'account_id' => $byRole['commission_payable']['account']->id,
                                    'dc' => 'credit',
                                    'amount' => $commission,
                                ],
                            ],
                        ], $request->user());
                        $calc->journal_entry_id = $entry->id;
                    }
                }
            } catch (RuntimeException) {
                // No commission_accrued rule — calculation only, no GL
            }

            $calc->save();

            $this->audit->record([
                'action' => 'sales.commission_calculated',
                'entity_type' => 'commission_calculation',
                'entity_id' => $calc->id,
                'actor_id' => $request->user()->id,
                'before' => $existing !== null ? ['commission_amount' => (float) $existing->commission_amount] : null,
                'after' => [
                    'employee_id' => $employee->id,
                    'base_amount' => $base,
                    'commission_amount' => $commission,
                    'period_type' => $periodType,
                    'period_start' => $bounds['period_start'],
                    'period_end' => $bounds['period_end'],
                    'rule_id' => $rule->id,
                ],
            ]);

            return $calc;
        });
    }
}
