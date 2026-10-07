<?php

namespace App\Domain\Sales\Queries;

use App\Domain\People\Employee;
use App\Domain\Sales\FieldVisit;
use App\Domain\Sales\SalesCallLog;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesTarget;
use Illuminate\Support\Collection;

/**
 * FieldSalesQuery (02-85). Aggregation of field visits + attributed
 * orders + calls per salesperson for a period window. Counts come from
 * real rows only — no synthetic activity.
 */
class FieldSalesQuery
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   totals: array{visits: int, orders: int, revenue: float, calls: int},
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

        $visitAgg = FieldVisit::query()
            ->where('company_id', $companyId)
            ->whereDate('visit_date', '>=', $bounds['period_start'])
            ->whereDate('visit_date', '<=', $bounds['period_end'])
            ->selectRaw('employee_id, COUNT(*) as visit_count, SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) as completed_count')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $orderAgg = SalesOrder::query()
            ->where('company_id', $companyId)
            ->whereNotNull('sales_person_id')
            ->whereDate('order_date', '>=', $bounds['period_start'])
            ->whereDate('order_date', '<=', $bounds['period_end'])
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->selectRaw('sales_person_id, COUNT(*) as order_count, SUM(grand_total) as revenue')
            ->groupBy('sales_person_id')
            ->get()
            ->keyBy('sales_person_id');

        $callAgg = SalesCallLog::query()
            ->where('company_id', $companyId)
            ->whereDate('call_date', '>=', $bounds['period_start'])
            ->whereDate('call_date', '<=', $bounds['period_end'])
            ->selectRaw('employee_id, COUNT(*) as call_count')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $employeeIds = $visitAgg->keys()
            ->merge($orderAgg->keys())
            ->merge($callAgg->keys())
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

        $rows = $roster->map(function (Employee $employee) use ($visitAgg, $orderAgg, $callAgg) {
            $visits = $visitAgg->get($employee->id);
            $orders = $orderAgg->get($employee->id);
            $calls = $callAgg->get($employee->id);

            $visitCount = $visits !== null ? (int) $visits->visit_count : 0;
            $completed = $visits !== null ? (int) $visits->completed_count : 0;
            $orderCount = $orders !== null ? (int) $orders->order_count : 0;
            $revenue = $orders !== null ? (float) $orders->revenue : 0.0;
            $callCount = $calls !== null ? (int) $calls->call_count : 0;

            return [
                'employee' => $employee,
                'visit_count' => $visitCount,
                'completed_visits' => $completed,
                'order_count' => $orderCount,
                'revenue' => round($revenue, 4),
                'call_count' => $callCount,
                'visits_per_order' => $orderCount > 0 ? round($visitCount / $orderCount, 2) : null,
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
                'visits' => (int) $rows->sum('visit_count'),
                'orders' => (int) $rows->sum('order_count'),
                'revenue' => round((float) $rows->sum('revenue'), 4),
                'calls' => (int) $rows->sum('call_count'),
            ],
            'bounds' => $bounds,
            'period_type' => $periodType,
            'method' => 'visits from field_visits.visit_date in window; orders from sales_orders.order_date where sales_person_id set and status not cancelled|draft; calls from sales_call_logs.call_date in window; revenue = SUM(sales_orders.grand_total)',
            'sample_size' => $rows->count(),
        ];
    }
}
