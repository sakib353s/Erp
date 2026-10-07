<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Inventory\WarehouseZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Warehouses and their structure (§04-42, §04-43, §04-45).
 *
 * Three rules hold this together:
 *
 *  · a warehouse code is unique inside its branch — it is what every stock row,
 *    movement and document points at, so two warehouses cannot share one;
 *  · a bin is a *place*, not a second balance. Quantities stay in the ledger per
 *    product per warehouse; a picker is told where to go, never how much there
 *    is from somewhere else.
 *  · a warehouse that has moved stock is history. Its layout may be edited, but
 *    it cannot be deleted out from under the ledger, and neither can the bins —
 *    the movements point at them.
 */
class WarehouseService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /* ----------------------------------------------------------- warehouses --- */

    /**
     * Warehouses with the size of their layout and the stock they hold, so the
     * list answers "is this one set up?" rather than only "does it exist?".
     */
    public function warehouses(?string $search = null): Collection
    {
        return Warehouse::query()
            ->when($this->context->companyId() !== null, fn ($q) => $q->where('company_id', $this->context->companyId()))
            ->with('branch:id,name,code')
            ->withCount(['zones', 'bins'])
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('address', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, code: string, branch_id: int, address?: string|null, is_active?: bool}  $payload
     */
    public function createWarehouse(array $payload, User $actor): Warehouse
    {
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $branch = Branch::query()->findOrFail($branchId);

        // A warehouse belongs to a branch of *this* company: the branch row is
        // the only place the two are tied together.
        if ($this->context->companyId() !== null && (int) $branch->company_id !== (int) $this->context->companyId()) {
            throw new RuntimeException('That branch does not belong to this company.');
        }

        $code = trim((string) $payload['code']);

        if ($code === '') {
            throw new RuntimeException('A warehouse needs a code — it is what every document and stock row points at.');
        }

        if ($this->codeTaken($branch->company_id, $branch->id, $code)) {
            throw new RuntimeException("Branch {$branch->name} already has a warehouse coded {$code}.");
        }

        return DB::transaction(function () use ($payload, $branch, $code, $actor) {
            $warehouse = Warehouse::create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'code' => $code,
                'name' => $payload['name'],
                'address' => $payload['address'] ?? null,
                'is_default' => (bool) ($payload['is_default'] ?? false),
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]);

            $this->demoteOtherDefaults($warehouse);

            $this->audit->record([
                'action' => 'inventory.warehouse_created',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'after' => ['code' => $warehouse->code, 'name' => $warehouse->name],
            ]);

            return $warehouse;
        });
    }

    /**
     * @param  array{name?: string, code?: string, branch_id?: int, address?: string|null, is_active?: bool, is_default?: bool}  $payload
     */
    public function updateWarehouse(Warehouse $warehouse, array $payload, User $actor): Warehouse
    {
        $before = $warehouse->only(['name', 'code', 'branch_id', 'address', 'is_active', 'is_default']);

        // A warehouse nobody stocks can be switched off; one that holds stock
        // cannot, because every balance and movement row points at it.
        if (array_key_exists('is_active', $payload) && ! (bool) $payload['is_active']) {
            $this->assertWarehouseEmpty($warehouse, 'switched off');
        }

        $branchId = (int) ($payload['branch_id'] ?? $warehouse->branch_id);
        $branch = $branchId === (int) $warehouse->branch_id
            ? $warehouse->branch()->first()
            : Branch::query()->findOrFail($branchId);

        if ($branch === null) {
            throw new RuntimeException('That warehouse is not attached to a branch any more.');
        }

        if ($this->context->companyId() !== null
            && (int) $branch->company_id !== (int) $this->context->companyId()) {
            throw new RuntimeException('That branch does not belong to this company.');
        }

        if ((int) $branch->id !== (int) $warehouse->branch_id) {
            // Stock is reported branch by branch, so a warehouse carrying stock
            // cannot quietly change which branch it reports under.
            $this->assertWarehouseEmpty($warehouse, 'moved to another branch');
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? $warehouse->code)));

        if ($code === '') {
            throw new RuntimeException('A warehouse needs a code — it is what every document and stock row points at.');
        }

        if ($code !== $warehouse->code && $this->codeTaken($warehouse->company_id, $branch->id, $code, $warehouse->id)) {
            throw new RuntimeException("Branch {$branch->name} already has a warehouse coded {$code}.");
        }

        $warehouse->fill([
            'branch_id' => $branch->id,
            'code' => $code,
            'name' => $payload['name'] ?? $warehouse->name,
            'address' => $payload['address'] ?? $warehouse->address,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : $warehouse->is_active,
            'is_default' => array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : $warehouse->is_default,
        ])->save();

        $this->demoteOtherDefaults($warehouse->refresh());

        $this->audit->record([
            'action' => 'inventory.warehouse_updated',
            'entity_type' => 'warehouse',
            'entity_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => $warehouse->only(['name', 'code', 'branch_id', 'address', 'is_active', 'is_default']),
        ]);

        return $warehouse->refresh();
    }

    public function deleteWarehouse(Warehouse $warehouse, User $actor): void
    {
        $this->assertWarehouseEmpty($warehouse, 'deleted');

        DB::transaction(function () use ($warehouse, $actor) {
            $this->audit->record([
                'action' => 'inventory.warehouse_deleted',
                'entity_type' => 'warehouse',
                'entity_id' => $warehouse->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'before' => ['code' => $warehouse->code, 'name' => $warehouse->name],
            ]);

            $warehouse->delete();
        });
    }

    /* ---------------------------------------------------------------- zones --- */

    /** @param array{code: string, name: string, type?: string, notes?: string|null, sort_order?: int|string|null} $payload */
    public function createZone(Warehouse $warehouse, array $payload, User $actor): WarehouseZone
    {
        $type = (string) ($payload['type'] ?? 'storage');

        if (! array_key_exists($type, WarehouseZone::TYPES)) {
            throw new RuntimeException("Unknown zone type [{$type}].");
        }

        $code = strtoupper(trim((string) $payload['code']));

        if ($code === '') {
            throw new RuntimeException('A zone needs a code — bins are labelled by it.');
        }

        if ($warehouse->zones()->where('code', $code)->exists()) {
            throw new RuntimeException("Zone {$code} already exists in {$warehouse->name}.");
        }

        return DB::transaction(function () use ($warehouse, $payload, $code, $type, $actor) {
            $zone = WarehouseZone::create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'code' => $code,
                'name' => $payload['name'] ?? $code,
                'type' => $type,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->audit->record([
                'action' => 'inventory.zone_created',
                'entity_type' => 'warehouse_zone',
                'entity_id' => $zone->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'after' => ['warehouse_id' => $warehouse->id, 'code' => $zone->code, 'type' => $zone->type],
            ]);

            return $zone;
        });
    }

    public function deleteZone(WarehouseZone $zone, User $actor): void
    {
        if ($zone->bins()->exists()) {
            throw new RuntimeException(sprintf(
                'Zone %s still has bins — remove or move them first.',
                $zone->code,
            ));
        }

        DB::transaction(function () use ($zone, $actor) {
            $this->audit->record([
                'action' => 'inventory.zone_deleted',
                'entity_type' => 'warehouse_zone',
                'entity_id' => $zone->id,
                'branch_id' => $zone->warehouse?->branch_id,
                'actor_id' => $actor->id,
                'before' => ['code' => $zone->code, 'type' => $zone->type],
            ]);

            $zone->delete();
        });
    }

    /* ----------------------------------------------------------------- bins --- */

    /** @param array{code: string, name?: string|null, is_pickable?: bool, notes?: string|null} $payload */
    public function createBin(WarehouseZone $zone, array $payload, User $actor): WarehouseBin
    {
        $code = strtoupper(trim((string) $payload['code']));

        if ($code === '') {
            throw new RuntimeException('A bin needs a code — it is what the picker is sent to.');
        }

        if ($zone->bins()->where('code', $code)->exists()) {
            throw new RuntimeException("Bin {$code} already exists in zone {$zone->code}.");
        }

        return DB::transaction(function () use ($zone, $payload, $code, $actor) {
            $bin = WarehouseBin::create([
                'company_id' => $zone->company_id,
                'warehouse_id' => $zone->warehouse_id,
                'warehouse_zone_id' => $zone->id,
                'code' => $code,
                'name' => $payload['name'] ?? null,
                'is_pickable' => (bool) ($payload['is_pickable'] ?? true),
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'notes' => $payload['notes'] ?? null,
            ]);

            $this->audit->record([
                'action' => 'inventory.bin_created',
                'entity_type' => 'warehouse_bin',
                'entity_id' => $bin->id,
                'branch_id' => $zone->warehouse?->branch_id,
                'actor_id' => $actor->id,
                'after' => ['zone_id' => $zone->id, 'code' => $bin->code, 'pickable' => $bin->is_pickable],
            ]);

            return $bin;
        });
    }

    public function deleteBin(WarehouseBin $bin, User $actor): void
    {
        if ($bin->assignments()->exists()) {
            throw new RuntimeException(sprintf(
                'Bin %s still holds product assignments — remove them first, because a picker is still being sent there.',
                $bin->code,
            ));
        }

        DB::transaction(function () use ($bin, $actor) {
            $this->audit->record([
                'action' => 'inventory.bin_deleted',
                'entity_type' => 'warehouse_bin',
                'entity_id' => $bin->id,
                'branch_id' => $bin->warehouse?->branch_id,
                'actor_id' => $actor->id,
                'before' => ['zone_id' => $bin->warehouse_zone_id, 'code' => $bin->code],
            ]);

            $bin->delete();
        });
    }

    /* ---------------------------------------------------------- assignments --- */

    /**
     * Put a product in a bin. Marking it primary demotes whatever was primary
     * before in the same warehouse, so "where do I pick this from?" always has
     * exactly one answer per warehouse.
     */
    public function assignProduct(WarehouseBin $bin, int $productId, bool $primary, ?string $notes, User $actor): ProductBinAssignment
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = $bin->warehouse;

        if ($warehouse === null) {
            throw new RuntimeException('That bin is not attached to a warehouse any more.');
        }

        return DB::transaction(function () use ($bin, $product, $primary, $notes, $warehouse, $actor) {
            if ($primary) {
                ProductBinAssignment::query()
                    ->where('company_id', $bin->company_id)
                    ->where('product_id', $product->id)
                    ->where('is_primary', true)
                    ->whereIn('warehouse_bin_id', WarehouseBin::query()
                        ->where('warehouse_id', $warehouse->id)
                        ->select('id'))
                    ->update(['is_primary' => false]);
            }

            $assignment = ProductBinAssignment::updateOrCreate(
                ['product_id' => $product->id, 'warehouse_bin_id' => $bin->id],
                [
                    'company_id' => $bin->company_id,
                    'is_primary' => $primary,
                    'notes' => $notes,
                ],
            );

            $this->audit->record([
                'action' => 'inventory.bin_assigned',
                'entity_type' => 'product_bin_assignment',
                'entity_id' => $assignment->id,
                'branch_id' => $warehouse->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'product_id' => $product->id,
                    'bin_id' => $bin->id,
                    'warehouse_id' => $warehouse->id,
                    'is_primary' => $assignment->is_primary,
                ],
            ]);

            return $assignment;
        });
    }

    public function unassignProduct(ProductBinAssignment $assignment, User $actor): void
    {
        DB::transaction(function () use ($assignment, $actor) {
            $this->audit->record([
                'action' => 'inventory.bin_unassigned',
                'entity_type' => 'product_bin_assignment',
                'entity_id' => $assignment->id,
                'actor_id' => $actor->id,
                'before' => ['product_id' => $assignment->product_id, 'bin_id' => $assignment->warehouse_bin_id],
            ]);

            $assignment->delete();
        });
    }

    /* ------------------------------------------------------------ the map ----- */

    /**
     * The warehouse laid out from the database: zones in order, their bins, who
     * is assigned where, and — next to a bin — what the ledger says the product
     * actually holds. The map is a view of rows, never a drawing somebody keeps
     * up to date by hand.
     *
     * @return array{
     *   warehouse: Warehouse,
     *   zones: Collection<int, WarehouseZone>,
     *   unzoned_bins: int,
     *   totals: array{zones: int, bins: int, pickable: int, assignments: int, products: int},
     * }
     */
    public function map(Warehouse $warehouse): array
    {
        $zones = $warehouse->zones()
            ->with(['bins.assignments.product'])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $bins = $zones->flatMap(fn (WarehouseZone $zone) => $zone->bins);
        $assignments = $bins->flatMap(fn (WarehouseBin $bin) => $bin->assignments);

        return [
            'warehouse' => $warehouse,
            'zones' => $zones,
            'unzoned_bins' => WarehouseBin::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereNotIn('warehouse_zone_id', $zones->pluck('id'))
                ->count(),
            'totals' => [
                'zones' => $zones->count(),
                'bins' => $bins->count(),
                'pickable' => $bins->where('is_pickable', true)->where('is_active', true)->count(),
                'assignments' => $assignments->count(),
                'products' => $assignments->pluck('product_id')->unique()->count(),
            ],
        ];
    }

    /**
     * Where to pick a product from, per warehouse — the question the assignment
     * table exists to answer. Falls back to nothing (and says so) rather than
     * guessing a bin.
     *
     * @return Collection<int, array{warehouse: Warehouse, bin: WarehouseBin|null, qty_on_hand: float}>
     */
    public function pickLocations(Product $product): Collection
    {
        $warehouses = Warehouse::query()
            ->where('company_id', $product->company_id)
            ->orderBy('name')
            ->get();

        $primaries = ProductBinAssignment::query()
            ->with('bin.zone')
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->get()
            ->keyBy(fn (ProductBinAssignment $a) => $a->bin?->warehouse_id);

        $quantities = StockBalance::query()
            ->where('product_id', $product->id)
            ->pluck('on_hand', 'warehouse_id');

        return $warehouses->map(fn (Warehouse $warehouse) => [
            'warehouse' => $warehouse,
            'bin' => $primaries->get($warehouse->id)?->bin,
            'qty_on_hand' => round((float) ($quantities[$warehouse->id] ?? 0), 4),
        ]);
    }

    /**
     * Products that live in a warehouse's bins but whose last movement is old
     * enough to be worth checking: the layout says they are here, and nobody has
     * touched them. This is a question the map can answer and a drawing cannot.
     *
     * @return Collection<int, array{product: Product, bin: WarehouseBin, days_idle: int}>
     */
    public function staleAssignments(Warehouse $warehouse, int $days = 60): Collection
    {
        $lastMoved = StockMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->selectRaw('product_id, MAX(occurred_at) as last_at')
            ->groupBy('product_id')
            ->pluck('last_at', 'product_id');

        return ProductBinAssignment::query()
            ->with(['product', 'bin.zone'])
            ->whereIn('warehouse_bin_id', WarehouseBin::query()->where('warehouse_id', $warehouse->id)->select('id'))
            ->get()
            ->map(function (ProductBinAssignment $assignment) use ($lastMoved) {
                $last = $lastMoved[$assignment->product_id] ?? null;
                $daysIdle = $last === null
                    ? 9999
                    : (int) now()->diffInDays(\Illuminate\Support\Carbon::parse($last));

                return [
                    'product' => $assignment->product,
                    'bin' => $assignment->bin,
                    'days_idle' => $daysIdle,
                ];
            })
            ->filter(fn (array $row) => $row['days_idle'] >= $days)
            ->sortByDesc('days_idle')
            ->values();
    }

    /* ------------------------------------------------------------- helpers ---- */

    /**
     * A warehouse code is unique inside its branch — asked on create and on
     * rename, and asked *without* the branch scope, because a clash elsewhere in
     * the company would still be a clash the database would refuse.
     */
    protected function codeTaken(int $companyId, int $branchId, string $code, ?int $exceptId = null): bool
    {
        return Warehouse::query()
            ->withoutGlobalScope(\App\Domain\Foundation\Concerns\BranchScope::class)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * One default per branch. A branch with two defaults has no default, and a
     * branch with none leaves every picker screen guessing.
     */
    protected function demoteOtherDefaults(Warehouse $warehouse): void
    {
        if (! $warehouse->is_default) {
            return;
        }

        Warehouse::query()
            ->where('company_id', $warehouse->company_id)
            ->where('branch_id', $warehouse->branch_id)
            ->whereKeyNot($warehouse->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    protected function assertWarehouseEmpty(Warehouse $warehouse, string $verb): void
    {
        $held = (float) StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->sum('on_hand');

        $moved = StockMovement::query()->where('warehouse_id', $warehouse->id)->exists();

        if ($held > 1e-9 || $moved) {
            throw new RuntimeException(sprintf(
                'Warehouse %s has stock history, so it cannot be %s — the ledger points at it.',
                $warehouse->name,
                $verb,
            ));
        }
    }
}
