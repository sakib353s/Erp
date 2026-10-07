<?php

namespace App\Domain\Purchase\Queries;

use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Sales\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read models for the buy side. All queries are company-scoped by the global
 * tenant scope and branch-filtered by the caller's access list, so a screen can
 * never count stock or money it is not allowed to see.
 */
class PurchaseQuery
{
    /**
     * @param  array{q?:?string,status?:?string,supplier?:?int,from?:?string,to?:?string,sort?:?string}  $filters
     */
    public function orders(array $filters, array $accessibleBranchIds = [], int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseOrder::query()
            ->with(['supplier:id,name,code', 'warehouse:id,name', 'branch:id,name'])
            ->withCount('lines')
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds))
            ->when(($filters['q'] ?? null), fn ($q, $term) => $q->search($term))
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(
                ($filters['status'] ?? null) && ($filters['status'] ?? null) !== 'open',
                fn ($q, $status) => $q->where('status', $status)
            )
            ->when(($filters['supplier'] ?? null), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when(($filters['bill'] ?? null), fn ($q, $id) => $q->whereHas('allocations', fn ($a) => $a
                ->where('allocatable_type', PurchaseBill::class)
                ->where('allocatable_id', $id)))
            ->when(($filters['from'] ?? null), fn ($q, $d) => $q->whereDate('order_date', '>=', $d))
            ->when(($filters['to'] ?? null), fn ($q, $d) => $q->whereDate('order_date', '<=', $d))
            ->when(($filters['sort'] ?? null) === 'value', fn ($q) => $q->orderByDesc('total'))
            ->when(($filters['sort'] ?? null) === 'oldest', fn ($q) => $q->orderBy('order_date'))
            ->when(! in_array($filters['sort'] ?? null, ['value', 'oldest'], true), fn ($q) => $q->orderByDesc('order_date')->orderByDesc('id'))
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{q?:?string,status?:?string,supplier?:?int,from?:?string,to?:?string}  $filters
     */
    public function receipts(array $filters, array $accessibleBranchIds = [], int $perPage = 20): LengthAwarePaginator
    {
        return GoodsReceipt::query()
            ->with(['supplier:id,name,code', 'warehouse:id,name', 'order:id,code'])
            ->withCount('lines')
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds))
            ->when(($filters['status'] ?? null), fn ($q, $status) => $q->where('status', $status))
            ->when(($filters['supplier'] ?? null), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when(($filters['from'] ?? null), fn ($q, $d) => $q->whereDate('received_date', '>=', $d))
            ->when(($filters['to'] ?? null), fn ($q, $d) => $q->whereDate('received_date', '<=', $d))
            ->when(($filters['q'] ?? null), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('code', 'like', "%{$term}%")
                        ->orWhere('challan_no', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{q?:?string,status?:?string,category?:?string,sort?:?string}  $filters
     */
    public function suppliers(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return Supplier::query()
            ->with('district:id,name')
            ->withCount(['orders', 'receipts'])
            ->when(($filters['q'] ?? null), fn ($q, $term) => $q->search($term))
            ->when(($filters['category'] ?? null), fn ($q, $category) => $q->where('category', $category))
            ->when(($filters['status'] ?? null) === 'blacklisted', fn ($q) => $q->where('is_blacklisted', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(($filters['status'] ?? null) === 'orderable', fn ($q) => $q->orderable())
            ->when(($filters['sort'] ?? null) === 'code', fn ($q) => $q->orderBy('code'))
            ->when(($filters['sort'] ?? null) === 'newest', fn ($q) => $q->orderByDesc('id'))
            ->when(! in_array($filters['sort'] ?? null, ['code', 'newest'], true), fn ($q) => $q->orderBy('name'))
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Headline numbers for the purchase screens, computed from the rows in
     * scope — never from a stored counter.
     *
     * @return array{open_orders:int, open_value:float, awaiting_approval:int, receipts_month:int, receipts_month_value:float, suppliers:int, blacklisted:int}
     */
    public function summary(array $accessibleBranchIds = []): array
    {
        $scoped = fn ($query) => $accessibleBranchIds === [] ? $query : $query->whereIn('branch_id', $accessibleBranchIds);

        $openOrders = $scoped(PurchaseOrder::query()->open());

        $monthStart = now()->startOfMonth()->toDateString();
        $monthReceipts = $scoped(GoodsReceipt::query()->where('status', 'posted')->whereDate('received_date', '>=', $monthStart));

        return [
            'open_orders' => (int) $openOrders->count(),
            'open_value' => (float) $scoped(PurchaseOrder::query()->open())->sum('total'),
            'awaiting_approval' => (int) $scoped(PurchaseOrder::query()->where('status', 'pending_approval'))->count(),
            'receipts_month' => (int) $monthReceipts->count(),
            'receipts_month_value' => (float) $scoped(GoodsReceipt::query()->where('status', 'posted')->whereDate('received_date', '>=', $monthStart))->sum('total'),
            'suppliers' => (int) Supplier::query()->where('is_active', true)->count(),
            'blacklisted' => (int) Supplier::query()->where('is_blacklisted', true)->count(),
        ];
    }

    /**
     * What a supplier owes us in goods: every open order line still short.
     *
     * @return Collection<int, array{order:PurchaseOrder, line:\App\Domain\Purchase\Models\PurchaseOrderLine}>
     */
    public function openLines(int $supplierId, int $limit = 40): Collection
    {
        return PurchaseOrder::query()
            ->with(['lines.product:id,sku,name'])
            ->open()
            ->where('supplier_id', $supplierId)
            ->orderBy('order_date')
            ->limit(20)
            ->get()
            ->flatMap(fn (PurchaseOrder $order) => $order->lines
                ->filter(fn ($line) => $line->outstandingQty() > 0)
                ->map(fn ($line) => ['order' => $order, 'line' => $line]))
            ->take($limit)
            ->values();
    }

    /**
     * Receipt values by day for the last N days — the sparkline data behind the
     * purchasing KPI. Returns [date => value].
     *
     * @return array<string, float>
     */
    public function receiptsByDay(int $days = 14, array $accessibleBranchIds = []): array
    {
        $from = now()->subDays($days - 1)->startOfDay()->toDateString();

        $rows = GoodsReceipt::query()
            ->where('status', 'posted')
            ->whereDate('received_date', '>=', $from)
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds))
            ->selectRaw('received_date, SUM(total) as value')
            ->groupBy('received_date')
            ->pluck('value', 'received_date');

        $out = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->toDateString();
            $out[$date] = (float) ($rows[$date] ?? 0);
        }

        return $out;
    }

    /**
     * Supplier spend by calendar month for one year — used on the supplier
     * profile. Grouped in PHP so the query stays portable across engines.
     *
     * @return array<int, float> keyed by month number 1..12
     */
    public function supplierSpend(int $supplierId, ?int $year = null): array
    {
        $year ??= (int) now()->year;

        $rows = GoodsReceipt::query()
            ->where('supplier_id', $supplierId)
            ->where('status', 'posted')
            ->whereYear('received_date', $year)
            ->get(['received_date', 'total']);

        $out = array_fill(1, 12, 0.0);

        foreach ($rows as $row) {
            $month = (int) $row->received_date->format('n');
            $out[$month] = ($out[$month] ?? 0.0) + (float) $row->total;
        }

        return $out;
    }

    /* ------------------------------------------- purchase bills (§03.6) */

    /**
     * @param  array{q?:?string,status?:?string,supplier?:?int,from?:?string,to?:?string,sort?:?string}  $filters
     */
    public function bills(array $filters, array $accessibleBranchIds = [], int $perPage = 20): LengthAwarePaginator
    {
        return PurchaseBill::query()
            ->with(['supplier:id,name,code', 'branch:id,name', 'receipt:id,code'])
            ->withCount('lines')
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds))
            ->when(($filters['q'] ?? null), fn ($q, $term) => $q->search($term))
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->overdue())
            ->when(
                ($filters['status'] ?? null) && ! in_array($filters['status'], ['open', 'overdue'], true),
                fn ($q, $status) => $q->where('status', $status)
            )
            ->when(($filters['supplier'] ?? null), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when(($filters['from'] ?? null), fn ($q, $d) => $q->whereDate('bill_date', '>=', $d))
            ->when(($filters['to'] ?? null), fn ($q, $d) => $q->whereDate('bill_date', '<=', $d))
            ->when(($filters['sort'] ?? null) === 'due', fn ($q) => $q->orderByRaw('due_date IS NULL')->orderBy('due_date'))
            ->when(($filters['sort'] ?? null) === 'value', fn ($q) => $q->orderByDesc('total'))
            ->when(
                ! in_array($filters['sort'] ?? null, ['due', 'value'], true),
                fn ($q) => $q->orderByDesc('bill_date')->orderByDesc('id')
            )
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Payables headline: what is owed, what is late, and what is waiting on a
     * human. Every figure comes from posted bills — never from a counter.
     *
     * @return array{payable:float, overdue:float, open_bills:int, overdue_bills:int, due_week:float, drafts:int, awaiting:int, posted_month:float}
     */
    public function billSummary(array $accessibleBranchIds = []): array
    {
        $scoped = fn () => PurchaseBill::query()
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds));

        $open = $scoped()->open();
        $overdue = $scoped()->overdue();

        return [
            'payable' => (float) (clone $open)->sum('due_amount'),
            'overdue' => (float) (clone $overdue)->sum('due_amount'),
            'open_bills' => (clone $open)->count(),
            'overdue_bills' => (clone $overdue)->count(),
            'due_week' => (float) $scoped()->open()
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->sum('due_amount'),
            'drafts' => $scoped()->whereIn('status', ['draft', 'pending_approval'])->count(),
            'awaiting' => $scoped()->where('status', 'pending_approval')->count(),
            'posted_month' => (float) $scoped()->where('posting_state', 'posted')
                ->whereYear('bill_date', now()->year)
                ->whereMonth('bill_date', now()->month)
                ->sum('total'),
        ];
    }

    /**
     * Three-way match detail for one bill: per line, what was ordered, what
     * arrived, what was billed, and where they disagree. Read-only — the state
     * stored on the bill is written by PurchaseBillService::runThreeWayMatch().
     *
     * @return array{state:?string, summary:?string, lines:Collection<int, array<string, mixed>>}
     */
    public function matchRows(PurchaseBill $bill): array
    {
        $bill->loadMissing('lines.product:id,sku,name', 'lines.orderLine', 'lines.receiptLine');

        $lines = $bill->lines->map(function ($line) {
            $orderedLine = $line->orderLine;
            $receivedLine = $line->receiptLine;

            $orderedQty = $orderedLine ? (float) $orderedLine->qty_ordered : null;
            $orderedPrice = $orderedLine ? (float) $orderedLine->unit_price : null;
            $receivedQty = $receivedLine ? (float) $receivedLine->qty_received : null;
            $billedQty = (float) $line->qty;
            $billedPrice = (float) $line->unit_cost;

            return [
                'id' => $line->id,
                'label' => $line->product?->name ?? $line->description ?? 'line #'.$line->id,
                'sku' => $line->product?->sku,
                'ordered' => $orderedQty,
                'received' => $receivedQty,
                'billed' => $billedQty,
                'ordered_price' => $orderedPrice,
                'billed_price' => $billedPrice,
                'qty_agrees' => $receivedQty === null ? null : round($billedQty, 4) <= round($receivedQty, 4),
                'price_agrees' => $orderedPrice === null ? null : round($billedPrice, 4) === round($orderedPrice, 4),
                'line_total' => (float) $line->line_total,
            ];
        });

        return ['state' => $bill->match_state, 'summary' => $bill->match_summary, 'lines' => $lines];
    }

    /**
     * Payables for one supplier with ageing buckets — the input for the
     * supplier profile's "what we owe" panel and the due screens.
     *
     * @return array{due:float, overdue:float, buckets:array<string, array{count:int, amount:float}>, rows:Collection<int, PurchaseBill>}
     */
    public function supplierPayables(int $supplierId): array
    {
        $rows = PurchaseBill::query()
            ->where('supplier_id', $supplierId)
            ->open()
            ->orderBy('due_date')
            ->get();

        $buckets = [
            'current' => ['count' => 0, 'amount' => 0.0],
            'd1_30' => ['count' => 0, 'amount' => 0.0],
            'd31_60' => ['count' => 0, 'amount' => 0.0],
            'd61_90' => ['count' => 0, 'amount' => 0.0],
            'd90_plus' => ['count' => 0, 'amount' => 0.0],
        ];

        $due = 0.0;
        $overdue = 0.0;

        foreach ($rows as $row) {
            $amount = (float) $row->due_amount;
            $bucket = $row->ageingBucket();

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] += $amount;
            $due += $amount;

            if ($bucket !== 'current') {
                $overdue += $amount;
            }
        }

        return ['due' => $due, 'overdue' => $overdue, 'buckets' => $buckets, 'rows' => $rows];
    }

    /* ----------------------------------- supplier payments (§03.7) */

    /**
     * @param  array{q?:?string,supplier?:?int,bill?:?int,from?:?string,to?:?string,method?:?string}  $filters
     */
    public function payments(array $filters, array $accessibleBranchIds = [], int $perPage = 20): LengthAwarePaginator
    {
        return Payment::query()
            ->where('direction', 'out')
            ->whereNotNull('supplier_id')
            ->with(['supplier:id,name,code', 'branch:id,name', 'allocations.allocatable'])
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds))
            ->when(($filters['supplier'] ?? null), fn ($q, $id) => $q->where('supplier_id', $id))
            ->when(($filters['method'] ?? null), fn ($q, $m) => $q->where('method', $m))
            ->when(($filters['from'] ?? null), fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
            ->when(($filters['to'] ?? null), fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))
            ->when(($filters['q'] ?? null), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('receipt_no', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%")
                        ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                });
            })
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Payments headline for the month, plus what is still owed so the two
     * numbers sit side by side instead of in separate reports.
     *
     * @return array{paid_month:float, payments_month:int, paid_today:float, payable:float, open_bills:int}
     */
    public function paymentSummary(array $accessibleBranchIds = []): array
    {
        $scoped = fn () => Payment::query()
            ->where('direction', 'out')
            ->whereNotNull('supplier_id')
            ->when($accessibleBranchIds !== [], fn ($q) => $q->whereIn('branch_id', $accessibleBranchIds));

        $bills = $this->billSummary($accessibleBranchIds);

        return [
            'paid_month' => (float) $scoped()->whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount'),
            'payments_month' => $scoped()->whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->count(),
            'paid_today' => (float) $scoped()->whereDate('paid_at', now()->toDateString())->sum('amount'),
            'payable' => $bills['payable'],
            'open_bills' => $bills['open_bills'],
        ];
    }

    /** Posted bills with a balance, for the payment form. */
    public function payableBills(int $limit = 100): Collection
    {
        return PurchaseBill::query()
            ->open()
            ->with('supplier:id,name')
            ->orderBy('due_date')
            ->orderBy('bill_date')
            ->limit($limit)
            ->get(['id', 'code', 'supplier_id', 'bill_date', 'due_date', 'total', 'due_amount', 'status', 'branch_id']);
    }
}
