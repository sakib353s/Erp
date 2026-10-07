<?php

namespace App\Domain\Sales\Queries;

use App\Domain\People\Employee;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesTarget;
use Illuminate\Support\Collection;

/**
 * LeaderboardQuery (02-82). Ranks sales persons by real attributed
 * invoice revenue in a period window. No fake data: only invoices with
 * sales_person_id set and status ∈ issued|partial|paid.
 */
class LeaderboardQuery
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{revenue: float, invoice_count: int, salespersons: int},
     *   bounds: array{period_start: string, period_end: string},
     *   period_type: string,
     *   method: string,
     *   sample_size: int,
     * }
     */
    public function forPeriod(int $companyId, string $periodType, ?string $at = null): array
    {
        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            $periodType = 'monthly';
        }

        $bounds = SalesTarget::boundsFor($periodType, $at);

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

        $roster = Employee::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($aggregates) {
                $q->where('is_salesperson', true)
                    ->orWhereIn('id', $aggregates->keys()->all());
            })
            ->orderBy('full_name')
            ->get();

        $rows = $roster->map(function (Employee $employee) use ($aggregates) {
            $agg = $aggregates->get($employee->id);
            $revenue = $agg !== null ? (float) $agg->revenue : 0.0;
            $count = $agg !== null ? (int) $agg->invoice_count : 0;

            return [
                'employee' => $employee,
                'revenue' => round($revenue, 4),
                'invoice_count' => $count,
                'avg_invoice' => $count > 0 ? round($revenue / $count, 4) : 0.0,
                'paid' => $agg !== null ? round((float) $agg->paid, 4) : 0.0,
                'due' => $agg !== null ? round((float) $agg->due, 4) : 0.0,
                'rank' => 0,
            ];
        })
            ->sortBy([
                ['revenue', 'desc'],
                ['invoice_count', 'desc'],
                ['employee.full_name', 'asc'],
            ])
            ->values();

        $ranked = [];
        $rank = 0;
        $lastRevenue = null;
        $position = 0;
        foreach ($rows as $row) {
            $position++;
            if ($lastRevenue === null || $row['revenue'] !== $lastRevenue) {
                $rank = $position;
                $lastRevenue = $row['revenue'];
            }
            $row['rank'] = $rank;
            $ranked[] = $row;
        }

        $rows = collect($ranked);
        $withSales = $rows->filter(fn (array $r) => $r['invoice_count'] > 0)->values();

        return [
            'rows' => $rows,
            'totals' => [
                'revenue' => round((float) $rows->sum('revenue'), 4),
                'invoice_count' => (int) $rows->sum('invoice_count'),
                'salespersons' => $rows->count(),
            ],
            'bounds' => $bounds,
            'period_type' => $periodType,
            'method' => 'SUM(invoices.grand_total) by sales_person_id where status ∈ issued|partial|paid and invoice_date in period window; roster = employees.is_salesperson ∪ attributed ids',
            'sample_size' => $withSales->count(),
        ];
    }
}
