<?php

namespace App\Domain\Reporting;

use App\Domain\Intelligence\Services\TrendSlope;
use App\Domain\Sales\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TrendQuery (02-119): daily sales series for a window.
 *
 * Source of truth is `invoices`; every observation day is materialized
 * into `bi_metrics_daily` (refresh-on-read) and the series is served back
 * from that table, so the report always equals its source. The trend
 * direction comes from TrendSlope (least squares) behind a min-sample
 * gate — the method, sample and gate travel with every response, and an
 * insufficient sample yields a "not enough data" state instead of a
 * claimed direction.
 */
class TrendQuery
{
    public function __construct(protected TrendSlope $slope) {}

    /**
     * @param  array{date_from?: ?string, date_to?: ?string}  $filters
     * @return array{
     *   series: Collection<int, array{date: string, invoices: int, revenue: float}>,
     *   totals: array{days: int, observation_days: int, invoices: int, revenue: float},
     *   trend: array<string, mixed>,
     *   filters: array{date_from: string, date_to: string},
     * }
     */
    public function forCompany(int $companyId, array $filters = []): array
    {
        $dateFrom = ($filters['date_from'] ?? null) ?: now()->subDays(29)->toDateString();
        $dateTo = ($filters['date_to'] ?? null) ?: now()->toDateString();

        // 1) Source: daily aggregates of issued/partial/paid invoices.
        $daily = Invoice::query()
            ->selectRaw('invoice_date, count(*) as invoices, coalesce(sum(grand_total), 0) as revenue')
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereNotIn('invoice_type', ['layaway']) // deposits are liabilities, not revenue
            ->whereDate('invoice_date', '>=', $dateFrom)
            ->whereDate('invoice_date', '<=', $dateTo)
            ->groupBy('invoice_date')
            ->orderBy('invoice_date')
            ->get();

        $counts = [];
        foreach ($daily as $row) {
            $day = $this->dayKey($row->invoice_date);
            $counts[$day] = (int) $row->invoices;
            $revenue = round((float) $row->revenue, 4);

            $this->put($companyId, $day, 'invoice_revenue', $revenue);
            $this->put($companyId, $day, 'invoice_count', (float) $row->invoices);
        }

        // 2) Serve the series back from the materialized table.
        $stored = DB::table('bi_metrics_daily')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('metric', 'invoice_revenue')
            ->whereBetween('metric_date', [$dateFrom, $dateTo])
            ->pluck('value', 'metric_date');

        $series = collect();
        $cursor = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $series->push([
                'date' => $day,
                'invoices' => $counts[$day] ?? 0,
                'revenue' => round((float) ($stored[$day] ?? 0), 4),
            ]);
            $cursor = $cursor->addDay();
        }

        $trend = $this->slope->of(
            $series->pluck('revenue')->map(fn ($value): float => (float) $value)->all(),
            count($counts),
        );

        return [
            'series' => $series,
            'totals' => [
                'days' => $series->count(),
                'observation_days' => count($counts),
                'invoices' => array_sum($counts),
                'revenue' => round((float) $series->sum('revenue'), 4),
            ],
            'trend' => $trend,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ];
    }

    protected function put(int $companyId, string $day, string $metric, float $value): void
    {
        $now = now();

        $existing = DB::table('bi_metrics_daily')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('metric', $metric)
            ->where('metric_date', $day)
            ->first();

        if ($existing !== null) {
            DB::table('bi_metrics_daily')->where('id', $existing->id)->update([
                'value' => $value,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('bi_metrics_daily')->insert([
            'company_id' => $companyId,
            'branch_id' => null,
            'metric_date' => $day,
            'metric' => $metric,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function dayKey(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toDateString()
            : (string) $value;
    }
}
