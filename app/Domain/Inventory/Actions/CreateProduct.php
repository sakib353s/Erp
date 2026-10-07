<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\ProductService;
use Illuminate\Http\Request;

/**
 * CreateProduct (04-02). SKU/code uniqueness and domain rules live in
 * ProductService; route middleware enforces inventory.products.create.
 */
class CreateProduct
{
    public function __construct(
        protected ProductService $products,
        protected AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, Request $request): Product
    {
        $product = $this->products->create($data);

        $this->audit->record([
            'action' => 'inventory.product_created',
            'entity_type' => 'product',
            'entity_id' => $product->id,
            'actor_id' => $request->user()?->id,
            'after' => [
                'code' => $product->code,
                'sku' => $product->sku,
                'name' => $product->name,
                'cost_method' => $product->cost_method,
            ],
        ]);

        return $product;
    }
}
