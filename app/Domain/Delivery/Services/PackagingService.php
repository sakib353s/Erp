<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\PackagingUsage;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockLayer;
use App\Domain\Inventory\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §04-59/04-60/04-61/04-62 — packaging, seen from the inventory side.
 *
 * Packaging was already real where it is *used* (02-98: a type maps to a
 * stock-managed product, consumption posts `PACK_CONSUME` and the layer decides
 * the cost). What was missing is the other half of the job: managing the types
 * as items — which box, is it still in use, what is on the shelf, what did it
 * cost, what did we spend it on — and none of that is a second set of numbers.
 *
 * So every figure on these screens is read from where the fact already lives:
 *
 *  · what is on the shelf → `stock_balances` (the ledger's own cache);
 *  · what it is worth → `stock_layers` remaining quantity × unit cost, the
 *    same layers a write-off or a valuation report reads;
 *  · what was consumed and what it cost → `packaging_usage`, written by the
 *    consumption action at the moment the layer priced it.
 *
 * A packaging type never carries a price of its own. If it did, the register
 * and the ledger would eventually disagree about the same box, and the one
 * people would believe is the wrong one.
 */
class PackagingService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /* ------------------------------------------------------------------ reads --- */

    /**
     * The types register: every packaging item the company has declared, with
     * the shelf and the money read in one pass each (no query per row).
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function types(?string $search = null, int $windowDays = 30): array
    {
        $companyId = $this->companyId();

        $types = PackagingType::query()
            ->where('company_id', $companyId)
            ->with('product:id,sku,name,is_active,is_stocked,unit_id')
            ->when($search !== null && $search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            }))
            ->orderBy('code')
            ->get();

        if ($types->isEmpty()) {
            // An empty register still answers every figure the screen asks for.
            return ['rows' => collect(), 'totals' => [
                'types' => 0, 'active' => 0, 'on_hand' => 0.0, 'value' => 0.0, 'consumed_cost' => 0.0,
            ]];
        }

        $productIds = $types->pluck('product_id')->unique()->values()->all();

        $stock = $this->onHandByProduct($productIds);
        $values = $this->layerValueByProduct($productIds);
        $recent = $this->usageSince($productIds, $windowDays);

        $rows = $types->map(function (PackagingType $type) use ($stock, $values, $recent) {
            $product = $type->product;
            $usage = $recent[$type->product_id] ?? ['qty' => 0.0, 'cost' => 0.0, 'orders' => 0, 'last' => null];
            $onHand = round((float) ($stock[$type->product_id] ?? 0.0), 4);
            $value = round((float) ($values[$type->product_id] ?? 0.0), 4);

            return [
                'type' => $type,
                'product' => $product,
                'on_hand' => $onHand,
                'value' => $value,
                'avg_cost' => $onHand > 0 ? round($value / $onHand, 4) : null,
                'consumed_qty' => $usage['qty'],
                'consumed_cost' => $usage['cost'],
                'consumed_orders' => $usage['orders'],
                'last_consumed_at' => $usage['last'],
                // A type whose product stopped being stocked or active can no
                // longer be consumed; the register says so instead of letting
                // somebody find out at packing time.
                'usable' => $product !== null && $product->is_active && $product->is_stocked,
            ];
        });

        return [
            'rows' => $rows,
            'totals' => [
                'types' => $types->count(),
                'active' => $types->where('is_active', true)->count(),
                'on_hand' => round($rows->sum('on_hand'), 4),
                'value' => round($rows->sum('value'), 4),
                'consumed_cost' => round($rows->sum('consumed_cost'), 4),
            ],
        ];
    }

    /**
     * §04-60 — packaging stock, from the same ledger as everything else.
     *
     * One row per type per warehouse where the packaging product has a balance
     * row, because that is what the ledger knows: a warehouse nobody has
     * delivered packaging to has no row to show, and inventing a zero there
     * would suggest a shelf was counted when it never was.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function stock(?int $warehouseId = null, ?string $search = null): array
    {
        $companyId = $this->companyId();

        $types = PackagingType::query()
            ->where('company_id', $companyId)
            ->with('product:id,sku,name,is_active,is_stocked')
            ->when($search !== null && $search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            }))
            ->orderBy('code')
            ->get();

        if ($types->isEmpty()) {
            // An empty desk still answers every figure the screen asks for.
            return ['rows' => collect(), 'totals' => [
                'shelves' => 0, 'on_hand' => 0.0, 'value' => 0.0, 'warehouses' => 0,
            ]];
        }

        $productIds = $types->pluck('product_id')->unique()->values()->all();
        $byProduct = $types->keyBy('product_id');

        $balances = StockBalance::query()
            ->where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->with('warehouse:id,name,code')
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->sortBy(fn (StockBalance $b) => (string) $byProduct[$b->product_id]->code.'|'.$b->warehouse_id)
            ->values();

        $values = $this->layerValueByProductWarehouse($productIds, $warehouseId);
        $lastMoved = $this->lastMovementByProductWarehouse($productIds, $warehouseId);

        $rows = $balances->map(function (StockBalance $balance) use ($byProduct, $values, $lastMoved) {
            $type = $byProduct[$balance->product_id];
            $key = $balance->warehouse_id.':'.$balance->product_id;
            $onHand = round((float) $balance->on_hand, 4);
            $value = round((float) ($values[$key] ?? 0.0), 4);

            return [
                'type' => $type,
                'product' => $type->product,
                'warehouse' => $balance->warehouse,
                'on_hand' => $onHand,
                'reserved' => round((float) $balance->reserved, 4),
                'available' => round($onHand - (float) $balance->reserved, 4),
                'in_transit' => round((float) $balance->in_transit, 4),
                'damaged' => round((float) $balance->damaged, 4),
                'value' => $value,
                'avg_cost' => $onHand > 0 ? round($value / $onHand, 4) : null,
                'last_moved_at' => $lastMoved[$key] ?? null,
                'run_out_days' => null,
            ];
        });

        // How long the shelf lasts, at the rate this type is actually being
        // used — the one number a storekeeper needs before a busy week.
        $rate = $this->usageSince($productIds, 30);
        $rows = $rows->map(function (array $row) use ($rate) {
            $used = $rate[$row['type']->product_id]['qty'] ?? 0.0;
            $perDay = $used > 0 ? $used / 30 : 0.0;

            $row['run_out_days'] = $perDay > 0 && $row['on_hand'] > 0
                ? round($row['on_hand'] / $perDay, 1)
                : null;

            return $row;
        });

        return [
            'rows' => $rows,
            'totals' => [
                'shelves' => $rows->count(),
                'on_hand' => round($rows->sum('on_hand'), 4),
                'value' => round($rows->sum('value'), 4),
                'warehouses' => $rows->pluck('warehouse.id')->unique()->count(),
            ],
        ];
    }

    /**
     * §04-61 — what packaging costs: what is on the shelf is worth, what was
     * consumed cost, and the two average unit costs side by side, because a
     * shelf bought last year and a box bought today are not the same money.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function cost(?int $warehouseId = null, int $days = 90, ?string $search = null): array
    {
        $companyId = $this->companyId();

        $types = PackagingType::query()
            ->where('company_id', $companyId)
            ->with('product:id,sku,name,is_active,is_stocked')
            ->when($search !== null && $search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            }))
            ->orderBy('code')
            ->get();

        if ($types->isEmpty()) {
            // An empty desk still answers every figure the screen asks for.
            return ['rows' => collect(), 'totals' => [
                'types' => 0, 'consumed_qty' => 0.0, 'consumed_cost' => 0.0, 'stock_value' => 0.0, 'days' => $days,
            ]];
        }

        $productIds = $types->pluck('product_id')->unique()->values()->all();
        $consumed = $this->usageSince($productIds, $days, $warehouseId, detailed: true);
        $stockValue = $this->layerValueByProduct($productIds);

        $rows = $types->map(function (PackagingType $type) use ($consumed, $stockValue) {
            $usage = $consumed[$type->product_id] ?? [
                'qty' => 0.0, 'cost' => 0.0, 'orders' => 0, 'last' => null,
                'last_order' => null, 'last_unit_cost' => null,
            ];

            return [
                'type' => $type,
                'product' => $type->product,
                'consumed_qty' => round((float) $usage['qty'], 4),
                'consumed_cost' => round((float) $usage['cost'], 4),
                // The layer priced every consumption; this is the average of
                // what was actually paid, not a list price.
                'consumed_unit_cost' => (float) $usage['qty'] > 0
                    ? round((float) $usage['cost'] / (float) $usage['qty'], 4)
                    : null,
                'consumed_orders' => (int) $usage['orders'],
                'last_consumed_at' => $usage['last'],
                'last_order_no' => $usage['last_order'],
                'last_unit_cost' => $usage['last_unit_cost'],
                'stock_value' => round((float) ($stockValue[$type->product_id] ?? 0.0), 4),
            ];
        });

        return [
            'rows' => $rows,
            'totals' => [
                'types' => $types->count(),
                'consumed_qty' => round($rows->sum('consumed_qty'), 4),
                'consumed_cost' => round($rows->sum('consumed_cost'), 4),
                'stock_value' => round($rows->sum('stock_value'), 4),
                'days' => $days,
            ],
        ];
    }

    /**
     * §04-62 — consumption by month and type, which is how packaging money is
     * actually looked at: "did October cost more because we shipped more, or
     * because boxes got dearer?"
     *
     * @return array{rows: Collection<int, array<string, mixed>>, months: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function report(string $from, string $to, ?int $warehouseId = null, ?string $search = null): array
    {
        $companyId = $this->companyId();

        $query = PackagingUsage::query()
            ->where('packaging_usage.company_id', $companyId)
            ->whereBetween('consumed_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereHas('packagingType', function ($t) use ($search) {
                $t->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->with(['packagingType:id,code,name,product_id', 'order:id,order_no'])
            ->orderByDesc('consumed_at')
            ->get();

        $rows = $query
            ->groupBy(fn (PackagingUsage $usage) => $usage->consumed_at->format('Y-m').'|'.$usage->packaging_type_id)
            ->map(function (Collection $group) {
                $first = $group->first();
                $qty = round((float) $group->sum('qty'), 4);
                $cost = round((float) $group->sum('total_cost'), 4);

                return [
                    'period' => $first->consumed_at->format('Y-m'),
                    'period_label' => $first->consumed_at->format('M Y'),
                    'type' => $first->packagingType,
                    'qty' => $qty,
                    'cost' => $cost,
                    'orders' => $group->pluck('sales_order_id')->unique()->count(),
                    'unit_cost' => $qty > 0 ? round($cost / $qty, 4) : null,
                ];
            })
            ->sortBy([['period', 'desc'], ['cost', 'desc']])
            ->values();

        $months = $query
            ->groupBy(fn (PackagingUsage $usage) => $usage->consumed_at->format('Y-m'))
            ->map(fn (Collection $group, $period) => [
                'period' => $period,
                'label' => $group->first()->consumed_at->format('M Y'),
                'qty' => round((float) $group->sum('qty'), 4),
                'cost' => round((float) $group->sum('total_cost'), 4),
                'orders' => $group->pluck('sales_order_id')->unique()->count(),
                'types' => $group->pluck('packaging_type_id')->unique()->count(),
            ])
            ->sortByDesc('period')
            ->values();

        return [
            'rows' => $rows,
            'months' => $months,
            'totals' => [
                'qty' => round((float) $query->sum('qty'), 4),
                'cost' => round((float) $query->sum('total_cost'), 4),
                'orders' => $query->pluck('sales_order_id')->unique()->count(),
                'types' => $query->pluck('packaging_type_id')->unique()->count(),
                'months' => $months->count(),
            ],
        ];
    }

    /** @return Collection<int, Warehouse> */
    public function warehouses()
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /* ---------------------------------------------------------------- writes --- */

    /**
     * Declare a packaging item. The product has to be a real stock-managed one:
     * a type that cannot be consumed is a promise the ledger will not keep.
     */
    public function createType(array $data, User $actor, string $auditAction = 'inventory.packaging_type_created'): PackagingType
    {
        $companyId = $this->companyId();
        $code = strtoupper(trim((string) $data['code']));

        if (PackagingType::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw new RuntimeException("Packaging code {$code} is already used.");
        }

        $product = $this->usableProduct((int) ($data['product_id'] ?? 0));

        $type = PackagingType::create([
            'company_id' => $companyId,
            'code' => $code,
            'name' => trim((string) $data['name']),
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        $this->audit->record([
            'action' => $auditAction,
            'entity_type' => 'packaging_type',
            'entity_id' => $type->id,
            'actor_id' => $actor->id,
            'after' => [
                'code' => $type->code,
                'name' => $type->name,
                'product_id' => $type->product_id,
                'sku' => $product->sku,
            ],
        ]);

        return $type;
    }

    /**
     * Rename a type — and change its code or its product only while no
     * consumption points at it. Once boxes have been taken out of stock under a
     * code, that code means something; rewriting it would rewrite history that
     * has already been costed.
     */
    public function updateType(PackagingType $type, array $data, User $actor): PackagingType
    {
        $code = strtoupper(trim((string) $data['code']));
        $productId = (int) ($data['product_id'] ?? $type->product_id);
        $moved = $this->usageCount($type) > 0;

        if ($moved && $code !== $type->code) {
            throw new RuntimeException(sprintf(
                'Code %s has already been consumed under, so it cannot be renamed — retire the type and declare a new one.',
                $type->code,
            ));
        }

        if ($moved && $productId !== (int) $type->product_id) {
            throw new RuntimeException(sprintf(
                'Type %s has already consumed stock, so it cannot be pointed at another product — the consumption would then price a box it never took.',
                $type->code,
            ));
        }

        if ($code !== $type->code && PackagingType::query()
            ->where('company_id', $type->company_id)
            ->where('code', $code)
            ->whereKeyNot($type->id)
            ->exists()) {
            throw new RuntimeException("Packaging code {$code} is already used.");
        }

        $product = $productId !== (int) $type->product_id
            ? $this->usableProduct($productId)
            : $type->product;

        $before = $type->only(['code', 'name', 'product_id']);

        $type->forceFill([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'product_id' => $product?->id ?? $type->product_id,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.packaging_type_updated',
            'entity_type' => 'packaging_type',
            'entity_id' => $type->id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => $type->only(['code', 'name', 'product_id']),
            'reason' => $moved ? 'name-only change: consumption already refers to this type' : null,
        ]);

        return $type;
    }

    /**
     * Retire or bring back a type. Retiring never deletes: consumption rows and
     * purchase history point at it, and a type that vanished would leave those
     * pointing at nothing.
     */
    public function toggle(PackagingType $type, User $actor): PackagingType
    {
        if (! $type->is_active) {
            // Coming back has to be safe: a type whose product stopped being
            // stocked would be consumable on paper and refused in the ledger.
            $this->usableProduct((int) $type->product_id);
        }

        $type->forceFill(['is_active' => ! $type->is_active])->save();

        $this->audit->record([
            'action' => $type->is_active
                ? 'inventory.packaging_type_activated'
                : 'inventory.packaging_type_retired',
            'entity_type' => 'packaging_type',
            'entity_id' => $type->id,
            'actor_id' => $actor->id,
            'after' => ['code' => $type->code, 'is_active' => $type->is_active],
        ]);

        return $type;
    }

    /**
     * Remove a declaration that was never used. A type that has taken stock out
     * of the warehouse is kept — deactivated instead — because deleting it would
     * orphan the movements that priced it.
     */
    public function deleteType(PackagingType $type, User $actor): void
    {
        $usage = $this->usageCount($type);

        if ($usage > 0) {
            throw new RuntimeException(sprintf(
                'Type %s has %d consumption record(s) behind it, so deleting it would orphan the movements that priced them — retire it instead.',
                $type->code,
                $usage,
            ));
        }

        $before = $type->only(['code', 'name', 'product_id', 'is_active']);
        $type->delete();

        $this->audit->record([
            'action' => 'inventory.packaging_type_deleted',
            'entity_type' => 'packaging_type',
            'entity_id' => $type->id,
            'actor_id' => $actor->id,
            'before' => $before,
        ]);
    }

    /* -------------------------------------------------------------- internals --- */

    protected function companyId(): int
    {
        return $this->context->companyId() ?? abort(500, 'No company context for packaging.');
    }

    protected function usableProduct(int $productId): Product
    {
        $product = Product::query()
            ->where('company_id', $this->companyId())
            ->find($productId);

        if ($product === null) {
            throw new RuntimeException('Pick a product from this company\'s catalogue to declare as packaging.');
        }

        if (! $product->is_stocked) {
            throw new RuntimeException("Product {$product->sku} is not stock-managed, so it cannot be consumed as packaging.");
        }

        if (! $product->is_active) {
            throw new RuntimeException("Product {$product->sku} is inactive, so it cannot be declared as packaging.");
        }

        return $product;
    }

    protected function usageCount(PackagingType $type): int
    {
        return PackagingUsage::query()->where('packaging_type_id', $type->id)->count();
    }

    /**
     * On hand per product, summed across warehouses where asked.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    protected function onHandByProduct(array $productIds, ?int $warehouseId = null): array
    {
        return StockBalance::query()
            ->where('company_id', $this->companyId())
            ->whereIn('product_id', $productIds)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(on_hand) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty) => round((float) $qty, 4))
            ->all();
    }

    /**
     * What the remaining layers are worth, per product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    protected function layerValueByProduct(array $productIds, ?int $warehouseId = null): array
    {
        return StockLayer::query()
            ->where('company_id', $this->companyId())
            ->whereIn('product_id', $productIds)
            ->where('qty_remaining', '>', 0)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(qty_remaining * unit_cost) as value')
            ->pluck('value', 'product_id')
            ->map(fn ($value) => round((float) $value, 4))
            ->all();
    }

    /**
     * The same value keyed `warehouse:product`, for the per-shelf stock screen.
     *
     * @param  array<int, int>  $productIds
     * @return array<string, float>
     */
    protected function layerValueByProductWarehouse(array $productIds, ?int $warehouseId = null): array
    {
        return StockLayer::query()
            ->where('company_id', $this->companyId())
            ->whereIn('product_id', $productIds)
            ->where('qty_remaining', '>', 0)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get(['warehouse_id', 'product_id', 'qty_remaining', 'unit_cost'])
            ->groupBy(fn (StockLayer $layer) => $layer->warehouse_id.':'.$layer->product_id)
            ->map(fn (Collection $layers) => round($layers->sum(fn (StockLayer $l) => (float) $l->qty_remaining * (float) $l->unit_cost), 4))
            ->all();
    }

    /**
     * The last time anything moved for each packaging product on each shelf —
     * "is this box sitting here because nobody ships?" is a real question.
     *
     * @param  array<int, int>  $productIds
     * @return array<string, string>
     */
    protected function lastMovementByProductWarehouse(array $productIds, ?int $warehouseId = null): array
    {
        return StockMovement::query()
            ->where('company_id', $this->companyId())
            ->whereIn('product_id', $productIds)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get(['warehouse_id', 'product_id', 'occurred_at'])
            ->groupBy(fn (StockMovement $m) => $m->warehouse_id.':'.$m->product_id)
            ->map(fn (Collection $rows) => (string) $rows->max('occurred_at'))
            ->all();
    }

    /**
     * Consumption inside a window, per product: how much, what it cost, how many
     * orders it packed, and the last time it happened.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{qty: float, cost: float, orders: int, last: ?string, last_order: ?string, last_unit_cost: ?float}>
     */
    protected function usageSince(array $productIds, int $days, ?int $warehouseId = null, bool $detailed = false): array
    {
        $since = now()->subDays(max(1, $days))->startOfDay();

        $rows = PackagingUsage::query()
            ->where('company_id', $this->companyId())
            ->whereIn('product_id', $productIds)
            ->where('consumed_at', '>=', $since)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->with('order:id,order_no')
            ->orderByDesc('consumed_at')
            ->get();

        return $rows
            ->groupBy('product_id')
            ->map(function (Collection $group) use ($detailed) {
                $last = $group->first();

                return [
                    'qty' => round((float) $group->sum('qty'), 4),
                    'cost' => round((float) $group->sum('total_cost'), 4),
                    'orders' => $group->pluck('sales_order_id')->unique()->count(),
                    'last' => $last?->consumed_at?->toDateTimeString(),
                    'last_order' => $detailed ? $last?->order?->order_no : null,
                    'last_unit_cost' => $detailed && $last !== null ? round((float) $last->unit_cost, 4) : null,
                ];
            })
            ->all();
    }
}
