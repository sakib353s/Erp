<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ReorderPolicy;
use App\Domain\Inventory\StockBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reorder policies and the alerts they produce (§04-23, 04-24).
 *
 * Rules owned here:
 *   · a policy is per product **per warehouse**, with an optional company-wide
 *     row (`warehouse_id = null`) as the fallback — the specific shelf wins,
 *     the general rule covers the rest;
 *   · an alert is never invented: a balance only appears in the list when a
 *     policy exists to compare it against, and the figure shown is the one in
 *     the immutable ledger (the balance cache, which replays from it);
 *   · the thresholds have to make sense together (minimum ≤ reorder point ≤
 *     maximum, safety ≤ reorder point), so a bad policy is refused with the
 *     numbers in the message instead of producing noise for ever after.
 */
class ReorderService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * Policies keyed `"{productId}:{warehouseId|0}"`, so a lookup per balance is
     * a hash hit rather than a query per row.
     *
     * @return array<string, ReorderPolicy>
     */
    public function policyIndex(): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for reorder policies.');

        return ReorderPolicy::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy(fn (ReorderPolicy $policy) => $policy->product_id.':'.($policy->warehouse_id ?? 0))
            ->all();
    }

    /**
     * The policy that governs this product in this warehouse: the warehouse's
     * own row if there is one, otherwise the company-wide rule.
     *
     * @param  array<string, ReorderPolicy>  $index
     */
    public function policyFor(int $productId, ?int $warehouseId, array $index): ?ReorderPolicy
    {
        return $index[$productId.':'.($warehouseId ?? 0)]
            ?? $index[$productId.':0']
            ?? null;
    }

    /**
     * @param  array{min_level?:float,max_level?:float,reorder_point?:float,safety_stock?:float,
     *               reorder_qty?:float,lead_time_days?:int,is_active?:bool}  $data
     */
    public function savePolicy(Product $product, array $data, ?int $warehouseId = null, ?int $actorId = null): ReorderPolicy
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for reorder policies.');

        $warehouse = null;

        if ($warehouseId !== null) {
            $warehouse = Warehouse::query()->whereKey($warehouseId)->firstOrFail();
        }

        $min = round((float) ($data['min_level'] ?? 0), 4);
        $max = round((float) ($data['max_level'] ?? 0), 4);
        $point = round((float) ($data['reorder_point'] ?? 0), 4);
        $safety = round((float) ($data['safety_stock'] ?? 0), 4);
        $qty = round((float) ($data['reorder_qty'] ?? 0), 4);
        $lead = (int) ($data['lead_time_days'] ?? 0);

        foreach (['minimum level' => $min, 'maximum level' => $max, 'reorder point' => $point,
                  'safety stock' => $safety, 'reorder quantity' => $qty] as $label => $value) {
            if ($value < 0) {
                throw new RuntimeException("The {$label} cannot be negative.");
            }
        }

        if ($lead < 0 || $lead > 365) {
            throw new RuntimeException('Lead time must be between 0 and 365 days.');
        }

        if ($max > 0 && $min > $max) {
            throw new RuntimeException(sprintf(
                'The minimum level (%s) cannot be above the maximum level (%s) — the shelf would always look overstocked.',
                number_format($min, 4),
                number_format($max, 4),
            ));
        }

        if ($max > 0 && $point > $max) {
            throw new RuntimeException(sprintf(
                'The reorder point (%s) cannot be above the maximum level (%s) — you would be ordering past the ceiling.',
                number_format($point, 4),
                number_format($max, 4),
            ));
        }

        if ($point > 0 && $min > 0 && $point < $min) {
            throw new RuntimeException(sprintf(
                'The reorder point (%s) is below the minimum level (%s) — reordering that late means the minimum is only ever seen after the shelf is empty.',
                number_format($point, 4),
                number_format($min, 4),
            ));
        }

        if ($point > 0 && $safety > $point) {
            throw new RuntimeException(sprintf(
                'Safety stock (%s) cannot exceed the reorder point (%s) — the buffer would never be held.',
                number_format($safety, 4),
                number_format($point, 4),
            ));
        }

        $existing = ReorderPolicy::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $before = $existing?->only(['min_level', 'max_level', 'reorder_point', 'safety_stock', 'reorder_qty', 'lead_time_days', 'is_active']);

        return DB::transaction(function () use ($companyId, $product, $warehouseId, $warehouse, $min, $max, $point, $safety, $qty, $lead, $data, $existing, $before, $actorId) {
            $policy = ReorderPolicy::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                ],
                [
                    'min_level' => $min,
                    'max_level' => $max,
                    'reorder_point' => $point,
                    'safety_stock' => $safety,
                    'reorder_qty' => $qty,
                    'lead_time_days' => $lead,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ],
            );

            $this->audit->record([
                'action' => $existing === null ? 'inventory.reorder_policy_created' : 'inventory.reorder_policy_updated',
                'entity_type' => 'reorder_policy',
                'entity_id' => $policy->id,
                'branch_id' => $warehouse?->branch_id ?? $this->context->branchId(),
                'actor_id' => $actorId,
                'before' => $before,
                'after' => $policy->only(['min_level', 'max_level', 'reorder_point', 'safety_stock', 'reorder_qty', 'lead_time_days', 'is_active'])
                    + ['product' => $product->sku, 'warehouse' => $warehouse?->code ?? 'company-wide'],
            ]);

            return $policy;
        });
    }

    public function deletePolicy(ReorderPolicy $policy, ?int $actorId = null): void
    {
        $snapshot = $policy->only(['product_id', 'warehouse_id', 'min_level', 'max_level', 'reorder_point']);
        $policy->delete();

        $this->audit->record([
            'action' => 'inventory.reorder_policy_deleted',
            'entity_type' => 'reorder_policy',
            'entity_id' => $snapshot['product_id'] ?? null,
            'actor_id' => $actorId,
            'before' => $snapshot,
            'after' => ['policy' => 'removed — this product is no longer watched in this warehouse'],
        ]);
    }

    public function policies(?int $warehouseId = null, ?string $search = null): Collection
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for reorder policies.');

        return ReorderPolicy::query()
            ->where('company_id', $companyId)
            ->with(['product:id,sku,name', 'warehouse:id,name,code'])
            ->when($warehouseId !== null, fn ($q) => $q->where(function ($w) use ($warehouseId) {
                $w->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
            }))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereHas('product', function ($p) use ($search) {
                $p->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->orderBy('product_id')
            ->get();
    }

    /**
     * Alert rows across every warehouse the caller can see. Each row carries the
     * policy it was judged by, so the number on screen can be argued with.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function alertRows(string $type = 'low', ?int $warehouseId = null, ?string $search = null): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for stock alerts.');
        $index = $this->policyIndex();

        $balances = StockBalance::query()
            ->where('company_id', $companyId)
            ->with(['product:id,sku,name', 'warehouse:id,name,code'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereHas('product', function ($p) use ($search) {
                $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->get();

        $counts = ['low' => 0, 'out' => 0, 'over' => 0];
        $rows = [];

        foreach ($balances as $balance) {
            $product = $balance->product;

            if ($product === null) {
                continue;
            }

            $policy = $this->policyFor((int) $product->id, $balance->warehouse_id, $index);

            if ($policy === null || ! $policy->is_active) {
                continue; // no threshold, no claim
            }

            $onHand = (float) $balance->on_hand;
            $min = (float) $policy->min_level;
            $max = (float) $policy->max_level;

            $state = $onHand <= 0
                ? 'out'
                : (($max > 0 && $onHand > $max)
                    ? 'over'
                    : (($min > 0 && $onHand <= $min) ? 'low' : null));

            if ($state === null) {
                continue;
            }

            $counts[$state]++;

            if ($state !== $type) {
                continue;
            }

            $rows[] = [
                'product' => $product,
                'warehouse' => $balance->warehouse,
                'on_hand' => round($onHand, 4),
                'reserved' => round((float) $balance->reserved, 4),
                'available' => round($onHand - (float) $balance->reserved, 4),
                'in_transit' => round((float) $balance->in_transit, 4),
                'policy' => $policy,
                'state' => $state,
                'shortage' => $state === 'out' || $state === 'low' ? round(max(0, $min - $onHand), 4) : 0.0,
                'suggested_qty' => $this->suggestedQty($policy, $onHand),
                'scope' => $policy->warehouse_id === null ? 'company-wide rule' : 'this warehouse',
            ];
        }

        usort($rows, fn ($a, $b) => ($b['shortage'] <=> $a['shortage']) ?: strcmp((string) $a['product']->sku, (string) $b['product']->sku));

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** What to order: the policy's own quantity, else enough to reach the maximum. */
    protected function suggestedQty(ReorderPolicy $policy, float $onHand): float
    {
        $qty = (float) $policy->reorder_qty;

        if ($qty > 0) {
            return round($qty, 4);
        }

        $max = (float) $policy->max_level;

        return $max > $onHand ? round($max - $onHand, 4) : 0.0;
    }
}
