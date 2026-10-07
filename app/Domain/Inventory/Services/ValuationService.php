<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockLayer;
use RuntimeException;

/**
 * Valuation layers per configured method (FIFO / LIFO / WAC / Standard).
 * Posted layers are never rewritten when a product's cost method changes.
 */
class ValuationService
{
    /**
     * Unit cost for an inbound quantity (receipt / opening / adjust-in).
     * Returns [unit_cost, layer|null].
     *
     * @return array{0: string, 1: StockLayer|null}
     */
    public function receive(
        Product $product,
        Warehouse $warehouse,
        float $qty,
        ?float $explicitUnitCost = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): array {
        if ($qty <= 0) {
            throw new RuntimeException('Receipt quantity must be greater than zero.');
        }

        $unitCost = $explicitUnitCost ?? $this->defaultInboundCost($product, $warehouse);

        $layer = StockLayer::create([
            'company_id' => $product->company_id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'qty_initial' => number_format($qty, 4, '.', ''),
            'qty_remaining' => number_format($qty, 4, '.', ''),
            'unit_cost' => number_format($unitCost, 4, '.', ''),
            'received_at' => now(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        return [number_format($unitCost, 4, '.', ''), $layer];
    }

    /**
     * Consume qty from open layers (outbound). Returns [total_cost, unit_cost_avg].
     *
     * @return array{0: string, 1: string}
     */
    public function consume(
        Product $product,
        Warehouse $warehouse,
        float $qty,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): array {
        if ($qty <= 0) {
            throw new RuntimeException('Consumption quantity must be greater than zero.');
        }

        $method = $product->cost_method;

        if ($method === 'standard') {
            $unit = (float) $product->standard_cost;

            return [
                number_format($unit * $qty, 4, '.', ''),
                number_format($unit, 4, '.', ''),
            ];
        }

        $open = StockLayer::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->orderBy($method === 'lifo' ? 'received_at' : 'received_at', $method === 'lifo' ? 'desc' : 'asc')
            ->lockForUpdate()
            ->get();

        $available = (float) $open->sum('qty_remaining');

        if ($available + 1e-9 < $qty) {
            throw new RuntimeException(sprintf(
                'Insufficient stock layers for %s: need %s, have %s.',
                $product->sku,
                $qty,
                $available,
            ));
        }

        $remaining = $qty;
        $totalCost = 0.0;

        foreach ($open as $layer) {
            if ($remaining <= 1e-9) {
                break;
            }

            $take = min((float) $layer->qty_remaining, $remaining);
            $totalCost += $take * (float) $layer->unit_cost;
            $layer->qty_remaining = number_format((float) $layer->qty_remaining - $take, 4, '.', '');
            $layer->save();
            $remaining -= $take;
        }

        if ($method === 'wac') {
            // Weighted average: recompute residual average across open layers
            $left = StockLayer::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->where('qty_remaining', '>', 0)
                ->get();

            $wacQty = (float) $left->sum('qty_remaining');
            $wacValue = $left->sum(fn (StockLayer $l) => (float) $l->qty_remaining * (float) $l->unit_cost);
            $avg = $wacQty > 0 ? $wacValue / $wacQty : $totalCost / max($qty, 1e-9);

            return [
                number_format($totalCost, 4, '.', ''),
                number_format($avg, 4, '.', ''),
            ];
        }

        return [
            number_format($totalCost, 4, '.', ''),
            number_format($qty > 0 ? $totalCost / $qty : 0, 4, '.', ''),
        ];
    }

    /** Value of remaining open layers for a warehouse/product. */
    public function stockValue(Product $product, Warehouse $warehouse): float
    {
        return (float) StockLayer::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as value')
            ->value('value');
    }

    protected function defaultInboundCost(Product $product, Warehouse $warehouse): float
    {
        if ($product->cost_method === 'standard') {
            return (float) $product->standard_cost;
        }

        $openQty = (float) StockLayer::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->sum('qty_remaining');

        $openValue = (float) StockLayer::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('qty_remaining', '>', 0)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as value')
            ->value('value');

        if ($openQty > 0) {
            return $openValue / $openQty;
        }

        return (float) $product->standard_cost;
    }
}
