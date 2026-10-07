<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
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

    /** @param array<string, mixed> $data */
    public function update(Product $product, array $data): Product
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
        return DB::transaction(function () use ($product, $data) {
            $product->fill(collect($data)->only([
                'product_category_id', 'brand_id', 'unit_id',
                'code', 'sku', 'name', 'barcode', 'description',
                'cost_method', 'standard_cost', 'is_stocked',
                'track_batch', 'track_serial', 'is_active',
            ])->all());
            $product->save();

            return $product;
        });
    }

    public function delete(Product $product): void
    {
        if ($product->movements()->exists()) {
            throw new RuntimeException('Products with stock history cannot be deleted.');
        }

        $product->delete();
    }
}
