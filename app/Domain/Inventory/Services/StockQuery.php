<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use Illuminate\Support\Collection;

/**
 * Stock overview queries over the derived balance cache with drill
 * into the immutable movement ledger.
 */
class StockQuery
{
    /**
     * Stock overview rows (on_hand / reserved / available / in_transit / …).
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function overview(
        int $companyId,
        ?int $branchId = null,
        ?int $warehouseId = null,
        ?string $search = null,
    ): array {
        $query = StockBalance::query()
            ->where('stock_balances.company_id', $companyId)
            ->with(['product', 'warehouse'])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where(function ($w) use ($search) {
                        $w->where('sku', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
                });
            })
            ->orderBy('product_id');

        $rows = $query->get()->map(function (StockBalance $balance) {
            $product = $balance->product;

            return [
                'balance' => $balance,
                'product' => $product,
                'warehouse' => $balance->warehouse,
                'on_hand' => (float) $balance->on_hand,
                'reserved' => (float) $balance->reserved,
                'available' => $balance->available(),
                'in_transit' => (float) $balance->in_transit,
                'damaged' => (float) $balance->damaged,
                'quarantined' => (float) $balance->quarantined,
            ];
        });

        $totals = [
            'on_hand' => (float) $rows->sum('on_hand'),
            'reserved' => (float) $rows->sum('reserved'),
            'available' => (float) $rows->sum('available'),
            'in_transit' => (float) $rows->sum('in_transit'),
            'damaged' => (float) $rows->sum('damaged'),
            'quarantined' => (float) $rows->sum('quarantined'),
        ];

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Immutable movement ledger for one product with running on-hand. */
    public function productLedger(Product $product, ?int $warehouseId = null): array
    {
        $query = StockMovement::query()
            ->where('product_id', $product->id)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->with(['warehouse', 'actor']);

        $running = 0.0;
        $rows = [];

        foreach ($query->get() as $movement) {
            // Reconstruct on-hand from on_hand-affecting movements only for display
            $affectsOnHand = $movement->state === StockMovement::STATE_ON_HAND
                || in_array($movement->movement_type, [
                    StockMovement::TYPE_TRANSIT_OUT,
                    StockMovement::TYPE_TRANSIT_IN,
                ], true)
                || ($movement->state === StockMovement::STATE_DAMAGED && ! $movement->isInbound());

            if ($affectsOnHand) {
                if ($movement->movement_type === StockMovement::TYPE_TRANSIT_OUT) {
                    $running -= abs((float) $movement->qty_signed);
                } elseif ($movement->movement_type === StockMovement::TYPE_TRANSIT_IN) {
                    $running += abs((float) $movement->qty_signed);
                } elseif ($movement->state === StockMovement::STATE_ON_HAND) {
                    $running += (float) $movement->qty_signed;
                } elseif ($movement->state === StockMovement::STATE_DAMAGED) {
                    $running -= abs((float) $movement->qty_signed);
                }
            }

            $rows[] = [
                'movement' => $movement,
                'running_on_hand' => number_format($running, 4, '.', ''),
            ];
        }

        return [
            'product' => $product,
            'rows' => $rows,
            'on_hand' => number_format($running, 4, '.', ''),
        ];
    }

    /** Stock alerts from reorder_policies thresholds (low / out / over). */
    public function alerts(int $companyId, string $type = 'low'): Collection
    {
        return StockBalance::query()
            ->where('company_id', $companyId)
            ->with(['product.reorderPolicy', 'warehouse'])
            ->whereHas('product.reorderPolicy', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(function (StockBalance $balance) use ($type) {
                $policy = $balance->product->reorderPolicy;
                if ($policy === null) {
                    return false;
                }

                $onHand = (float) $balance->on_hand;
                $min = (float) $policy->min_level;
                $max = (float) $policy->max_level;

                return match ($type) {
                    'out' => $onHand <= 0,
                    'over' => $max > 0 && $onHand > $max,
                    default => $onHand > 0 && $min > 0 && $onHand <= $min,
                };
            })
            ->values();
    }
}
