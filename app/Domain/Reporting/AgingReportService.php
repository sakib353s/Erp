<?php

namespace App\Domain\Reporting;

use App\Domain\Sales\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * AgingReportService (02-64): open accounts-receivable buckets as of a date.
 *
 * Buckets are derived from the invoices themselves (status + due_amount +
 * due_date) — every bucket total is a partition of the source balance, so
 * the sum of the buckets always equals total open AR.
 */
class AgingReportService
{
    public const BUCKETS = ['current', '1-30', '31-60', '61-90', '91+'];

    /**
     * @param  int|null  $branchId  null = every branch in the company
     * @return array{
     *   as_of: string,
     *   rows: Collection<int, array<string, mixed>>,
     *   buckets: array<string, float>,
     *   totals: array{invoices: int, amount: float},
     *   filters: array{branch_id: ?int},
     * }
     */
    public function forCompany(int $companyId, ?string $asOf = null, ?int $branchId = null): array
    {
        $asOfDate = CarbonImmutable::parse($asOf ?? now()->toDateString());

        $query = Invoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'partial'])
            ->where('due_amount', '>', 0);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $invoices = $query->with('customer')->orderBy('due_date')->orderBy('id')->get();

        $rows = $invoices->map(function (Invoice $invoice) use ($asOfDate): array {
            $due = $invoice->due_date;
            $overdue = $due !== null && $due->lessThan($asOfDate);
            $days = $overdue ? (int) $due->diffInDays($asOfDate) : 0;

            return [
                'invoice_id' => (int) $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'customer' => $invoice->customer?->name,
                'status' => $invoice->status,
                'due_date' => $due?->toDateString(),
                'days_overdue' => $days,
                'bucket' => $overdue ? self::bucketFor($days) : 'current',
                'amount' => round((float) $invoice->due_amount, 4),
            ];
        });

        $buckets = array_fill_keys(self::BUCKETS, 0.0);
        foreach ($rows as $row) {
            $buckets[$row['bucket']] = round($buckets[$row['bucket']] + $row['amount'], 4);
        }

        return [
            'as_of' => $asOfDate->toDateString(),
            'rows' => $rows,
            'buckets' => $buckets,
            'totals' => [
                'invoices' => $rows->count(),
                'amount' => round((float) $rows->sum('amount'), 4),
            ],
            'filters' => ['branch_id' => $branchId],
        ];
    }

    public static function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 30 => '1-30',
            $daysOverdue <= 60 => '31-60',
            $daysOverdue <= 90 => '61-90',
            default => '91+',
        };
    }
}
