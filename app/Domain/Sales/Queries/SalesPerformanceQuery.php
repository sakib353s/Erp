<?php

namespace App\Domain\Sales\Queries;

use App\Domain\People\Employee;
use App\Domain\Sales\FieldVisit;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * SalesPerformanceQuery (02-83). Per sales-person performance for a
 * period: target vs actual, invoice stats, due exposure. Field-visit
 * dimension uses field_visits when the table exists — never faked.
 */
class SalesPerformanceQuery
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{target: float, revenue: float, variance: float, invoice_count: int},
     *   bounds: array{period_start: string, period_end: string},
     *   period_type: string,
     *   method: string,
     *   sample_size: int,
     *   field_visits_available: bool,
     *   field_visits_note: string,
     * }
     */
    public function forPeriod(int $companyId, string $periodType, ?string $at = null): array
    {
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            $periodType = 'monthly';
        }

        $bounds = SalesTarget::boundsFor($periodType, $at);

        $fieldVisitsAvailable = Schema::hasTable('field_visits');

        $visitAgg = collect();
        if ($fieldVisitsAvailable) {
            $visitAgg = FieldVisit::query()
                ->where('company_id', $companyId)
                ->whereDate('visit_date', '>=', $bounds['period_start'])
                ->whereDate('visit_date', '<=', $bounds['period_end'])
                ->selectRaw('employee_id, COUNT(*) as visit_count')
                ->groupBy('employee_id')
                ->get()
                ->keyBy('employee_id');
        }

        $aggregates = Invoice::query()
            ->where('company_id', $companyId)
            ->whereNotNull('sales_person_id')
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereDate('invoice_date', '>=', $bounds['period_start'])
            ->whereDate('invoice_date', '<=', $bounds['period_end'])
            ->selectRaw('sales_person_id, COUNT(*) as invoice_count, SUM(grand_total) as revenue, SUM(due_amount) as due, SUM(paid_amount) as paid')
            ->groupBy('sales_person_id')
            ->get()
            ->keyBy('sales_person_id');

        $targets = SalesTarget::query()
            ->where('company_id', $companyId)
            ->where('period_type', $periodType)
            ->whereDate('period_start', $bounds['period_start'])
            ->get()
            ->keyBy('employee_id');

        $employeeIds = $aggregates->keys()
            ->merge($targets->keys())
            ->unique()
            ->values()
            ->all();

        $roster = Employee::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($employeeIds) {
                $q->where('is_salesperson', true);
                if ($employeeIds !== []) {
                    $q->orWhereIn('id', $employeeIds);
                }
            })
            ->orderBy('full_name')
            ->get();

        $rows = $roster->map(function (Employee $employee) use ($aggregates, $targets, $visitAgg, $fieldVisitsAvailable) {
            $agg = $aggregates->get($employee->id);
            $target = $targets->get($employee->id);
            $visits = $fieldVisitsAvailable ? $visitAgg->get($employee->id) : null;

            $revenue = $agg !== null ? (float) $agg->revenue : 0.0;
            $targetAmount = $target !== null ? (float) $target->target_amount : 0.0;
            $count = $agg !== null ? (int) $agg->invoice_count : 0;
            $due = $agg !== null ? (float) $agg->due : 0.0;
            $visitCount = $visits !== null ? (int) $visits->visit_count : 0;

            return [
                'employee' => $employee,
                'target' => $target,
                'target_amount' => round($targetAmount, 4),
                'revenue' => round($revenue, 4),
                'variance' => round($revenue - $targetAmount, 4),
                'pct' => $targetAmount > 0 ? round(($revenue / $targetAmount) * 100, 2) : null,
                'invoice_count' => $count,
                'avg_invoice' => $count > 0 ? round($revenue / $count, 4) : 0.0,
                'due' => round($due, 4),
                'field_visit_count' => $visitCount,
                'has_target' => $target !== null,
            ];
        })
            ->sortBy([
                ['revenue', 'desc'],
                ['employee.full_name', 'asc'],
            ])
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'target' => round((float) $rows->sum('target_amount'), 4),
                'revenue' => round((float) $rows->sum('revenue'), 4),
                'variance' => round((float) $rows->sum('revenue') - (float) $rows->sum('target_amount'), 4),
                'invoice_count' => (int) $rows->sum('invoice_count'),
            ],
            'bounds' => $bounds,
            'period_type' => $periodType,
            'method' => 'target from sales_targets (period window); actual = SUM(invoices.grand_total) by sales_person_id where status ∈ issued|partial|paid and invoice_date in window; dues from SUM(due_amount); visits from field_visits.visit_date in window when table exists',
            'sample_size' => $rows->count(),
            'field_visits_available' => $fieldVisitsAvailable,
            'field_visits_note' => $fieldVisitsAvailable
                ? 'Field visit counts from field_visits table (visit_date in period window).'
                : 'field_visits table is not implemented yet — visit dimension omitted (no fake data).',
        ];
    }
}
