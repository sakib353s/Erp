<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockLayer;
use App\Domain\Inventory\StockMovement;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The stock report family (§04-35, §04-36): how old the stock is, what has
 * stopped moving, and what the shelf is worth.
 *
 * Everything here is derived from the same two sources the rest of inventory
 * trusts: the immutable movement ledger for *when* something moved, and the
 * valuation layers for *what it is worth*. Nothing is cached into a report
 * table, so a report can never disagree with the ledger it describes.
 */
class StockReportService
{
    /** When stock has not moved for this many days it is dead by default. */
    public const DEFAULT_DEAD_STOCK_DAYS = 90;

    public function __construct(protected SettingService $settings) {}

    /**
     * Ageing: every product with stock on hand, dated by its last movement in
     * that warehouse, bucketed 0–30 / 31–60 / 61–90 / 90+ days.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>, buckets: array<string, array{rows: int, value: float}>, threshold: int}
     */
    public function aging(int $companyId, ?int $warehouseId = null, ?string $search = null): array
    {
        return $this->build($companyId, $warehouseId, $search, onlyDead: false);
    }

    /**
     * Dead stock: the same rows, but only those idle for at least the threshold.
     * The threshold comes from settings (`inventory.dead_stock_days`) unless the
     * screen overrides it, and the figure in force is reported back so the user
     * always sees which rule produced the list.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>, buckets: array<string, array{rows: int, value: float}>, threshold: int}
     */
    public function deadStock(int $companyId, ?int $thresholdDays = null, ?int $warehouseId = null, ?string $search = null): array
    {
        $threshold = $thresholdDays ?? $this->deadStockDays();

        return $this->build($companyId, $warehouseId, $search, onlyDead: true, threshold: $threshold);
    }

    public function deadStockDays(): int
    {
        $days = $this->settings->getInt('inventory', 'dead_stock_days', self::DEFAULT_DEAD_STOCK_DAYS);

        return max(1, $days);
    }

    /**
     * Stock report (§04-36): what is on hand, what is promised, what it is worth.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function stock(int $companyId, ?int $warehouseId = null, ?string $search = null): array
    {
        $aging = $this->build($companyId, $warehouseId, $search, onlyDead: false);

        $totals = [
            'on_hand' => 0.0,
            'reserved' => 0.0,
            'available' => 0.0,
            'in_transit' => 0.0,
            'damaged' => 0.0,
            'quarantined' => 0.0,
            'value' => 0.0,
        ];

        foreach ($aging['rows'] as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'rows' => $aging['rows'],
            'totals' => array_map(fn ($v) => round($v, 4), $totals),
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>, buckets: array<string, array{rows: int, value: float}>, threshold: int}
     */
    protected function build(int $companyId, ?int $warehouseId, ?string $search, bool $onlyDead, ?int $threshold = null): array
    {
        $threshold = $threshold ?? $this->deadStockDays();

        $balances = StockBalance::query()
            ->where('stock_balances.company_id', $companyId)
            ->where('on_hand', '>', 0)
            ->with(['product:id,sku,name,standard_cost,cost_method', 'warehouse:id,name,code'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereHas('product', function ($p) use ($search) {
                $p->where('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->get();

        if ($balances->isEmpty()) {
            return ['rows' => collect(), 'totals' => [], 'buckets' => $this->emptyBuckets(), 'threshold' => $threshold];
        }

        $keys = $balances->map(fn (StockBalance $b) => $b->warehouse_id.':'.$b->product_id);

        // Where the money is: the remaining valuation layers, one grouped query.
        $layerValues = StockLayer::query()
            ->where('company_id', $companyId)
            ->whereIn('warehouse_id', $balances->pluck('warehouse_id')->unique()->all())
            ->get(['warehouse_id', 'product_id', 'qty_remaining', 'unit_cost'])
            ->groupBy(fn (StockLayer $layer) => $layer->warehouse_id.':'.$layer->product_id)
            ->map(fn (Collection $layers) => round($layers->sum(fn (StockLayer $l) => (float) $l->qty_remaining * (float) $l->unit_cost), 4));

        // How old it is: the last time anything moved for that product in that
        // warehouse — inbound or outbound, both mean the shelf was touched.
        $lastMovements = StockMovement::query()
            ->where('company_id', $companyId)
            ->whereIn('warehouse_id', $balances->pluck('warehouse_id')->unique()->all())
            ->get(['warehouse_id', 'product_id', 'occurred_at'])
            ->groupBy(fn (StockMovement $m) => $m->warehouse_id.':'.$m->product_id)
            ->map(fn (Collection $rows) => $rows->max('occurred_at'));

        $rows = collect();
        $buckets = $this->emptyBuckets();

        foreach ($balances as $balance) {
            $key = $balance->warehouse_id.':'.$balance->product_id;
            $product = $balance->product;

            if ($product === null) {
                continue;
            }

            $onHand = (float) $balance->on_hand;
            $layered = (float) ($layerValues[$key] ?? 0);
            // Standard-cost products carry no layers; fall back to the standard
            // cost so the report still shows what the shelf is worth.
            $value = round($layered > 0 ? $layered : $onHand * (float) $product->standard_cost, 4);

            $last = $lastMovements[$key] ?? null;
            $lastAt = $last !== null ? Carbon::parse($last) : null;
            $daysIdle = $lastAt !== null ? (int) $lastAt->diffInDays(now()) : 0;

            $bucket = match (true) {
                $daysIdle <= 30 => '0-30',
                $daysIdle <= 60 => '31-60',
                $daysIdle <= 90 => '61-90',
                default => '90+',
            };

            $buckets[$bucket]['rows']++;
            $buckets[$bucket]['value'] = round($buckets[$bucket]['value'] + $value, 4);

            if ($onlyDead && $daysIdle < $threshold) {
                continue;
            }

            $rows->push([
                'product' => $product,
                'warehouse' => $balance->warehouse,
                'on_hand' => round($onHand, 4),
                'reserved' => round((float) $balance->reserved, 4),
                'available' => round($onHand - (float) $balance->reserved, 4),
                'in_transit' => round((float) $balance->in_transit, 4),
                'damaged' => round((float) $balance->damaged, 4),
                'quarantined' => round((float) $balance->quarantined, 4),
                'value' => $value,
                'last_movement_at' => $lastAt,
                'days_idle' => $daysIdle,
                'bucket' => $bucket,
                'is_dead' => $daysIdle >= $threshold,
            ]);
        }

        // Oldest first: the report is read from the top to decide what to do.
        $rows = $rows->sortBy([['days_idle', 'desc'], ['value', 'desc']])->values();

        $totals = [
            'rows' => $rows->count(),
            'on_hand' => round((float) $rows->sum('on_hand'), 4),
            'reserved' => round((float) $rows->sum('reserved'), 4),
            'available' => round((float) $rows->sum('available'), 4),
            'in_transit' => round((float) $rows->sum('in_transit'), 4),
            'damaged' => round((float) $rows->sum('damaged'), 4),
            'quarantined' => round((float) $rows->sum('quarantined'), 4),
            'value' => round((float) $rows->sum('value'), 4),
        ];

        return ['rows' => $rows, 'totals' => $totals, 'buckets' => $buckets, 'threshold' => $threshold];
    }

    /** @return array<string, array{rows: int, value: float}> */
    protected function emptyBuckets(): array
    {
        return [
            '0-30' => ['rows' => 0, 'value' => 0.0],
            '31-60' => ['rows' => 0, 'value' => 0.0],
            '61-90' => ['rows' => 0, 'value' => 0.0],
            '90+' => ['rows' => 0, 'value' => 0.0],
        ];
    }

    /** Warehouse options for the report filters. */
    public function warehouses(): Collection
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']);
    }
}
