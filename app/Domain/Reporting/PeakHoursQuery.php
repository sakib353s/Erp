<?php

namespace App\Domain\Reporting;

use App\Domain\Sales\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PeakHoursQuery (02-118): hour-of-day histogram of invoice creation
 * timestamps for a date window. The result always carries the period and
 * the sample size (BI honesty rule): every hour 00–23 appears — zeros are
 * real gaps in the sample, not synthetic rows — and the peak is a fact of
 * that sample, deterministic on ties (earliest hour wins).
 */
class PeakHoursQuery
{
    /**
     * @param  array{date_from?: ?string, date_to?: ?string}  $filters
     * @return array{
     *   buckets: Collection<int, array{hour: int, label: string, count: int, share: float}>,
     *   totals: array{invoices: int},
     *   peak: array{hour: int, label: string, count: int}|null,
     *   filters: array{date_from: string, date_to: string, timezone: string},
     * }
     */
    public function forCompany(int $companyId, array $filters = []): array
    {
        $dateFrom = ($filters['date_from'] ?? null) ?: now()->startOfMonth()->toDateString();
        $dateTo = ($filters['date_to'] ?? null) ?: now()->toDateString();
        $timezone = (string) (config('app.timezone') ?: 'Asia/Dhaka');

        $timestamps = Invoice::query()
            ->select('created_at')
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereNotIn('invoice_type', ['layaway']) // deposits are liabilities, not revenue
            ->where('created_at', '>=', $dateFrom.' 00:00:00')
            ->where('created_at', '<=', $dateTo.' 23:59:59')
            ->pluck('created_at');

        $counts = array_fill(0, 24, 0);

        foreach ($timestamps as $timestamp) {
            $hour = (int) Carbon::parse((string) $timestamp, $timezone)->format('G');
            $counts[$hour]++;
        }

        $total = array_sum($counts);

        $peak = null;
        if ($total > 0) {
            $peakCount = max($counts);
            $peakHour = (int) array_search($peakCount, $counts, true);
            $peak = [
                'hour' => $peakHour,
                'label' => $this->hourLabel($peakHour),
                'count' => $peakCount,
            ];
        }

        $buckets = collect(range(0, 23))->map(fn (int $hour): array => [
            'hour' => $hour,
            'label' => $this->hourLabel($hour),
            'count' => $counts[$hour],
            'share' => $total > 0 ? round($counts[$hour] / $total * 100, 2) : 0.0,
        ]);

        return [
            'buckets' => $buckets,
            'totals' => ['invoices' => $total],
            'peak' => $peak,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'timezone' => $timezone,
            ],
        ];
    }

    protected function hourLabel(int $hour): string
    {
        return sprintf('%02d:00–%02d:00', $hour, $hour === 23 ? 24 : $hour + 1);
    }
}
