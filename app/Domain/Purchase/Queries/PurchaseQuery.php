<?php

namespace App\Domain\Purchase\Queries;

use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseOrder;
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
}
