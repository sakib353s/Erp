<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\ProductService;

/**
 * DuplicateProduct (§04-04).
 *
 * A copy of the catalogue row, and deliberately nothing else: no balances, no
 * valuation layers, no movements, no cost history and no barcode. The stock a
 * product holds belongs to the product that received it; copying that would
 * invent inventory out of a form submission, which is exactly the kind of thing
 * a stock ledger must never allow. The reason to duplicate at all is that the
 * configuration (category, brand, unit, cost method, batch/serial flags) is the
 * tedious part — the stock is not.
 */
class DuplicateProduct
{
    public function __construct(
        protected ProductService $products,
        protected AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data  code, sku, name of the copy */
    public function handle(Product $source, array $data, User $actor): Product
    {
        $copy = $this->products->duplicate($source, $data);

        $this->audit->record([
            'action' => 'inventory.product_duplicated',
            'entity_type' => 'product',
            'entity_id' => $copy->id,
            'actor_id' => $actor->id,
            'after' => [
                'code' => $copy->code,
                'sku' => $copy->sku,
                'duplicated_from' => $source->id,
                'duplicated_from_sku' => $source->sku,
                'stock_copied' => false,
            ],
        ]);

        return $copy;
    }
}
