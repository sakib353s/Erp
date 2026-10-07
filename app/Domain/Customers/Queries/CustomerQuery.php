<?php

namespace App\Domain\Customers\Queries;

use App\Domain\Masters\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer reads used by the CRM screens (§05).
 *
 * Everything here is a query over the ledgers — invoices and posted payment
 * allocations — rather than a cached "due" column, so the numbers on the
 * screen and the numbers in the trial balance cannot drift apart.
 */
class CustomerQuery
{
    /** Ageing buckets in the order the UI shows them (05-08). */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    /** @param array{q?:string|null,group?:int|null,status?:string|null,district?:int|null,sort?:string|null} $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Customer::query()
            ->with(['group:id,name', 'district:id,name'])
            ->search($filters['q'] ?? null);

        if (! empty($filters['group'])) {
            $query->where('customer_group_id', (int) $filters['group']);
        }

        if (! empty($filters['district'])) {
            $query->where('district_id', (int) $filters['district']);
        }

        match ($filters['status'] ?? null) {
            'blacklisted' => $query->where('is_blacklisted', true),
            'inactive' => $query->where('is_active', false),
            'over_limit' => $query->whereIn('id', $this->overLimitIds()),
            default => null,
        };

        match ($filters['sort'] ?? null) {
            'name_desc' => $query->orderByDesc('name'),
            'newest' => $query->orderByDesc('created_at'),
            'code' => $query->orderBy('code'),
            default => $query->orderBy('name'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Receivable per customer, derived from issued invoices and posted
     * allocations. One grouped query — the customer list must not N+1.
     *
     * @param  Collection<int, int>|array<int, int>  $customerIds
     * @return array<int, array{invoiced:float, paid:float, due:float, overdue:float, oldest_due:?string}>
     */
    public function receivableByCustomer($customerIds): array
    {
        $ids = collect($customerIds)->filter()->values()->all();

        if ($ids === []) {
            return [];
        }

        $invoices = DB::table('invoices')
            ->whereIn('customer_id', $ids)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(grand_total) AS invoiced, SUM(paid_amount) AS paid')
            ->get()
            ->keyBy('customer_id');

        $open = DB::table('invoices')
            ->whereIn('customer_id', $ids)
            ->whereIn('status', ['issued', 'partial'])
            ->whereRaw('grand_total > paid_amount')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(grand_total - paid_amount) AS due, MIN(due_date) AS oldest_due')
            ->get()
            ->keyBy('customer_id');

        $overdue = DB::table('invoices')
            ->whereIn('customer_id', $ids)
            ->whereIn('status', ['issued', 'partial'])
            ->whereRaw('grand_total > paid_amount')
            ->where('due_date', '<', now()->toDateString())
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(grand_total - paid_amount) AS overdue')
            ->get()
            ->keyBy('customer_id');

        $out = [];

        foreach ($ids as $id) {
            $inv = $invoices->get($id);
            $openRow = $open->get($id);
            $overdueRow = $overdue->get($id);

            $out[$id] = [
                'invoiced' => round((float) ($inv->invoiced ?? 0), 2),
                'paid' => round((float) ($inv->paid ?? 0), 2),
                'due' => round((float) ($openRow->due ?? 0), 2),
                'overdue' => round((float) ($overdueRow->overdue ?? 0), 2),
                'oldest_due' => $openRow->oldest_due ?? null,
            ];
        }

        return $out;
    }

    /**
     * Open invoices for one customer, with the bucket they fall in (05-08/05-10b).
     *
     * @return Collection<int, object>
     */
    public function openInvoices(Customer $customer): Collection
    {
        return DB::table('invoices')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['issued', 'partial'])
            ->whereRaw('grand_total > paid_amount')
            ->orderBy('due_date')
            ->get([
                'id', 'invoice_no', 'invoice_date', 'due_date', 'grand_total', 'paid_amount',
            ])
            ->map(function ($row) {
                $row->due = round((float) $row->grand_total - (float) $row->paid_amount, 2);
                $row->bucket = self::bucketFor($row->due_date);

                return $row;
            });
    }

    /**
     * Due-by-bucket totals for the whole book (05-08). Boundaries are exact:
     * 0-30 / 31-60 / 61-90 / 90+, with not-yet-due counted as `current`.
     *
     * @return array<string, array{label:string, count:int, amount:float}>
     */
    public function dueBuckets(?int $companyId = null): array
    {
        $rows = DB::table('invoices')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('status', ['issued', 'partial'])
            ->whereRaw('grand_total > paid_amount')
            ->get(['due_date', 'grand_total', 'paid_amount']);

        $totals = array_fill_keys(self::BUCKETS, ['count' => 0, 'amount' => 0.0]);

        foreach ($rows as $row) {
            $bucket = self::bucketFor($row->due_date);
            $totals[$bucket]['count']++;
            $totals[$bucket]['amount'] += (float) $row->grand_total - (float) $row->paid_amount;
        }

        $labels = [
            'current' => 'Not yet due',
            '1_30' => 'Overdue 1-30 days',
            '31_60' => 'Overdue 31-60 days',
            '61_90' => 'Overdue 61-90 days',
            '90_plus' => 'Overdue 90+ days',
        ];

        $out = [];

        foreach (self::BUCKETS as $bucket) {
            $out[$bucket] = [
                'label' => $labels[$bucket],
                'count' => $totals[$bucket]['count'],
                'amount' => round($totals[$bucket]['amount'], 2),
            ];
        }

        return $out;
    }

    /** Customers with exposure beyond their credit limit (05-16 warning list). */
    public function overLimitIds(): array
    {
        $exposure = DB::table('invoices')
            ->whereIn('status', ['issued', 'partial'])
            ->whereRaw('grand_total > paid_amount')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(grand_total - paid_amount) AS due')
            ->pluck('due', 'customer_id');

        if ($exposure->isEmpty()) {
            return [];
        }

        return Customer::query()
            ->whereIn('id', $exposure->keys())
            ->where('credit_limit', '>', 0)
            ->get(['id', 'credit_limit'])
            ->filter(fn (Customer $c) => (float) $exposure[$c->id] > (float) $c->credit_limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Customer ledger: opening, chronological movements, running balance (05-07).
     * Movements are invoices (debit) and posted receipts (credit).
     *
     * @param  array{from?:string|null,to?:string|null}  $range
     * @return array{opening:float,lines:array<int, array<string, mixed>>,closing:float,totals:array{debit:float,credit:float}}
     */
    public function ledger(Customer $customer, array $range = []): array
    {
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;

        $openingRow = ($customer->opening_balance_type ?? 'due') === 'due'
            ? (float) $customer->opening_balance
            : -(float) $customer->opening_balance;

        $invoiceBase = DB::table('invoices')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['issued', 'partial', 'paid']);

        $paymentBase = DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('invoices', function ($join) {
                $join->on('invoices.id', '=', 'payment_allocations.allocatable_id')
                    ->where('payment_allocations.allocatable_type', 'like', '%Invoice');
            })
            ->where('payments.company_id', $customer->company_id)
            ->where('payments.customer_id', $customer->id)
            ->where('payments.status', 'posted');

        $opening = $openingRow;

        if ($from !== null) {
            $opening += (float) (clone $invoiceBase)->where('invoice_date', '<', $from)->sum('grand_total');
            $opening -= (float) (clone $paymentBase)->where('payments.paid_at', '<', $from)->sum('payment_allocations.amount');
        }

        $debits = (clone $invoiceBase)
            ->when($from !== null, fn ($q) => $q->where('invoice_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('invoice_date', '<=', $to))
            ->get(['invoice_no', 'invoice_date', 'grand_total', 'status'])
            ->map(fn ($r) => [
                'date' => $r->invoice_date,
                'reference' => $r->invoice_no,
                'description' => 'Sales invoice',
                'debit' => round((float) $r->grand_total, 2),
                'credit' => 0.0,
                'sort' => $r->invoice_date.' 00:00:00',
            ]);

        $credits = (clone $paymentBase)
            ->when($from !== null, fn ($q) => $q->where('payments.paid_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('payments.paid_at', '<=', $to))
            ->get(['payments.receipt_no', 'payments.paid_at', 'payments.method', 'payment_allocations.amount'])
            ->map(fn ($r) => [
                'date' => substr((string) $r->paid_at, 0, 10),
                'reference' => $r->receipt_no,
                'description' => 'Receipt ('.ucfirst((string) $r->method).')',
                'debit' => 0.0,
                'credit' => round((float) $r->amount, 2),
                'sort' => $r->paid_at.' 23:59:59',
            ]);

        $lines = $debits->concat($credits)
            ->sortBy('sort')
            ->values()
            ->all();

        $running = $opening;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $index => $line) {
            $running += $line['debit'] - $line['credit'];
            $lines[$index]['balance'] = round($running, 2);
            $totalDebit += $line['debit'];
            $totalCredit += $line['credit'];
        }

        return [
            'opening' => round($opening, 2),
            'lines' => $lines,
            'closing' => round($running, 2),
            'totals' => ['debit' => round($totalDebit, 2), 'credit' => round($totalCredit, 2)],
        ];
    }

    /** Recent documents for the profile screen (orders + invoices), newest first. */
    public function recentDocuments(Customer $customer, int $limit = 8): Collection
    {
        $invoices = DB::table('invoices')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get(['id', 'invoice_no', 'invoice_date', 'status', 'grand_total', 'paid_amount', 'currency']);

        return $invoices->map(fn ($r) => [
            'no' => $r->invoice_no,
            'date' => $r->invoice_date,
            'status' => $r->status,
            'total' => round((float) $r->grand_total, 2),
            'due' => round((float) $r->grand_total - (float) $r->paid_amount, 2),
            'currency' => $r->currency,
        ]);
    }

    /**
     * Static bucket calculation — separated so tests can pin the boundaries.
     */
    public static function bucketFor(?string $dueDate): string
    {
        if ($dueDate === null || $dueDate === '') {
            return 'current';
        }

        $days = (int) now()->startOfDay()->diffInDays(
            \Illuminate\Support\Carbon::parse($dueDate)->startOfDay(),
            false,
        );

        if ($days >= 0) {
            return 'current';
        }

        $overdue = abs($days);

        return match (true) {
            $overdue <= 30 => '1_30',
            $overdue <= 60 => '31_60',
            $overdue <= 90 => '61_90',
            default => '90_plus',
        };
    }
}
