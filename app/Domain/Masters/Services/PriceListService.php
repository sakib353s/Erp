<?php

namespace App\Domain\Masters\Services;

use App\Domain\Inventory\Product;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Price list lifecycle (02-108). Company-unique codes, exactly one default
 * list per company (the one PricingService resolves), validated validity
 * windows, and per-product item rows written only for products that belong
 * to this company.
 */
class PriceListService
{
    public function create(int $companyId, array $payload): PriceList
    {
        $code = $this->normalizeCode($payload, $companyId);
        $this->assertWindow($payload);

        return DB::transaction(function () use ($companyId, $payload, $code) {
            $attributes = [
                'company_id' => $companyId,
                'code' => $code,
                'name' => trim((string) ($payload['name'] ?? '')),
                'valid_from' => $payload['valid_from'] ?? null,
                'valid_to' => $payload['valid_to'] ?? null,
            ];

            foreach (['is_default', 'is_active'] as $flag) {
                if (array_key_exists($flag, $payload)) {
                    $attributes[$flag] = (bool) $payload[$flag];
                }
            }

            $list = PriceList::create($attributes);
            $this->syncItems($list, $payload['items'] ?? [], $companyId);

            if ($list->is_default) {
                $this->demoteOtherDefaults($list);
            }

            return $list;
        });
    }

    public function update(PriceList $list, array $payload): PriceList
    {
        $companyId = (int) $list->company_id;
        $code = $this->normalizeCode($payload, $companyId, $list);
        $this->assertWindow($payload);

        return DB::transaction(function () use ($list, $payload, $companyId, $code) {
            $attributes = [
                'code' => $code,
                'name' => trim((string) ($payload['name'] ?? '')),
                'valid_from' => $payload['valid_from'] ?? null,
                'valid_to' => $payload['valid_to'] ?? null,
            ];

            foreach (['is_default', 'is_active'] as $flag) {
                if (array_key_exists($flag, $payload)) {
                    $attributes[$flag] = (bool) $payload[$flag];
                }
            }

            $list->update($attributes);
            $this->syncItems($list, $payload['items'] ?? [], $companyId);

            if (! empty($attributes['is_default'])) {
                $this->demoteOtherDefaults($list);
            }

            return $list->refresh();
        });
    }

    public function delete(PriceList $list): void
    {
        DB::transaction(function () use ($list) {
            $list->items()->delete();
            $list->delete();
        });
    }

    /**
     * Replaces the item set wholesale: rows are only kept for products that
     * belong to this company, at non-negative prices, one row per product.
     *
     * @param  array<int, array{product_id?: mixed, price?: mixed}>  $items
     */
    public function syncItems(PriceList $list, array $items, int $companyId): void
    {
        $rows = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $price = round((float) ($item['price'] ?? -1), 4);

            if ($productId <= 0) {
                throw new RuntimeException('Every price row needs a product.');
            }
            if ($price < 0) {
                throw new RuntimeException('Price cannot be negative.');
            }
            if (array_key_exists($productId, $rows)) {
                throw new RuntimeException('The same product appears twice in the price rows.');
            }

            $product = Product::query()
                ->where('company_id', $companyId)
                ->find($productId);

            if ($product === null) {
                throw new RuntimeException("Product #{$productId} does not belong to this company.");
            }

            $rows[$productId] = $price;
        }

        $list->items()->delete();

        foreach ($rows as $productId => $price) {
            PriceListItem::create([
                'price_list_id' => $list->id,
                'product_id' => $productId,
                'price' => number_format($price, 4, '.', ''),
            ]);
        }
    }

    protected function normalizeCode(array $payload, int $companyId, ?PriceList $ignore = null): string
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));

        if ($code === '') {
            throw new RuntimeException('Price list code is required.');
        }

        $query = PriceList::query()
            ->where('company_id', $companyId)
            ->where('code', $code);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->id);
        }

        if ($query->exists()) {
            throw new RuntimeException("Price list code {$code} already exists.");
        }

        return $code;
    }

    protected function assertWindow(array $payload): void
    {
        $from = $payload['valid_from'] ?? null;
        $to = $payload['valid_to'] ?? null;

        if ($from !== null && $to !== null && $to < $from) {
            throw new RuntimeException('Valid to must be on or after valid from.');
        }
    }

    /** Exactly one default list per company — the one PricingService reads. */
    protected function demoteOtherDefaults(PriceList $list): void
    {
        PriceList::query()
            ->where('company_id', $list->company_id)
            ->whereKeyNot($list->id)
            ->where('is_default', true)
            ->get()
            ->each(fn (PriceList $other) => $other->update(['is_default' => false]));
    }
}
