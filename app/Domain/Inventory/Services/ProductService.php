<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductCostHistory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Product catalogue mutations (04-01…04-03). SKU/code unique per company;
 * cost-method change never rewrites posted valuation layers.
 */
class ProductService
{
    public function __construct(protected TenantContext $context) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Product
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $code = (string) $data['code'];
        $sku = (string) ($data['sku'] ?? $code);

        if (Product::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw new RuntimeException('Product code already exists.');
        }

        if (Product::query()->where('company_id', $companyId)->where('sku', $sku)->exists()) {
            throw new RuntimeException('Product SKU already exists.');
        }

        if (! in_array($data['cost_method'] ?? 'wac', Product::COST_METHODS, true)) {
            throw new RuntimeException('Unsupported valuation cost method.');
        }

        return DB::transaction(function () use ($data, $companyId, $sku) {
            return Product::create([
                'company_id' => $companyId,
                'product_category_id' => $data['product_category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'unit_id' => $data['unit_id'] ?? null,
                'code' => $data['code'],
                'sku' => $sku,
                'name' => $data['name'],
                'barcode' => $data['barcode'] ?? null,
                'description' => $data['description'] ?? null,
                'cost_method' => $data['cost_method'] ?? 'wac',
                'standard_cost' => $data['standard_cost'] ?? 0,
                'is_stocked' => $data['is_stocked'] ?? true,
                'track_batch' => $data['track_batch'] ?? false,
                'track_serial' => $data['track_serial'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  User|null  $actor  whoever made the change, so a cost jump has a name
     */
    public function update(Product $product, array $data, ?User $actor = null): Product
    {
        if (array_key_exists('code', $data) && $data['code'] !== $product->code) {
            if (Product::query()
                ->where('company_id', $product->company_id)
                ->where('code', $data['code'])
                ->where('id', '!=', $product->id)
                ->exists()) {
                throw new RuntimeException('Product code already exists.');
            }
        }

        if (array_key_exists('sku', $data) && $data['sku'] !== $product->sku) {
            if (Product::query()
                ->where('company_id', $product->company_id)
                ->where('sku', $data['sku'])
                ->where('id', '!=', $product->id)
                ->exists()) {
                throw new RuntimeException('Product SKU already exists.');
            }
        }

        // Cost method may change for FUTURE layers; existing layers are never rewritten.
        return DB::transaction(function () use ($product, $data, $actor) {
            $before = [
                'standard_cost' => $product->standard_cost === null ? null : (float) $product->standard_cost,
                'cost_method' => (string) $product->cost_method,
            ];

            $product->fill(collect($data)->only([
                'product_category_id', 'brand_id', 'unit_id',
                'code', 'sku', 'name', 'barcode', 'description',
                'cost_method', 'standard_cost', 'is_stocked',
                'track_batch', 'track_serial', 'is_active',
            ])->all());
            $product->save();

            // §04-10: a cost-affecting edit is a decision somebody took, so it is
            // recorded with its before, after, actor and reason — the valuation
            // layers keep answering what the stock actually cost.
            if ($this->costChanged($before, $product)) {
                ProductCostHistory::create([
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'changed_by' => $actor?->id,
                    'old_standard_cost' => $before['standard_cost'],
                    'new_standard_cost' => $product->standard_cost === null ? null : (float) $product->standard_cost,
                    'old_cost_method' => $before['cost_method'],
                    'new_cost_method' => (string) $product->cost_method,
                    'reason' => isset($data['cost_change_reason']) ? trim((string) $data['cost_change_reason']) ?: null : null,
                    'changed_at' => now(),
                ]);
            }

            return $product;
        });
    }

    /**
     * A copy of a product (§04-04): the catalogue row and its configuration, and
     * nothing else. No stock, no balances, no layers, no history and no barcode —
     * a barcode is a physical identifier, and the copy is not on the shelf yet.
     *
     * @param  array<string, mixed>  $data  code, sku and (optionally) name of the copy
     */
    public function duplicate(Product $source, array $data): Product
    {
        $name = trim((string) ($data['name'] ?? ''));

        return $this->create([
            'code' => $data['code'],
            'sku' => $data['sku'],
            'name' => $name !== '' ? $name : 'Copy of '.$source->name,
            'product_category_id' => $source->product_category_id,
            'brand_id' => $source->brand_id,
            'unit_id' => $source->unit_id,
            'description' => $source->description,
            'cost_method' => $source->cost_method,
            'standard_cost' => (float) $source->standard_cost,
            'is_stocked' => (bool) $source->is_stocked,
            'track_batch' => (bool) $source->track_batch,
            'track_serial' => (bool) $source->track_serial,
            'is_active' => (bool) $source->is_active,
        ]);
    }

    public function delete(Product $product): void
    {
        if ($product->movements()->exists()) {
            throw new RuntimeException('Products with stock history cannot be deleted.');
        }

        $product->delete();
    }

    /**
     * Did this edit change what the product says it costs? Compared as numbers,
     * because the decimal cast hands back strings like "12.0000".
     *
     * @param  array{standard_cost: float|null, cost_method: string}  $before
     */
    protected function costChanged(array $before, Product $product): bool
    {
        $afterCost = $product->standard_cost === null ? null : (float) $product->standard_cost;

        if ($before['standard_cost'] === null || $afterCost === null) {
            return $before['standard_cost'] !== $afterCost;
        }

        if (abs($before['standard_cost'] - $afterCost) > 0.00005) {
            return true;
        }

        return $before['cost_method'] !== (string) $product->cost_method;
    }
}
