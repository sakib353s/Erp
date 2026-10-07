<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\ProductService;
use App\Domain\Masters\Brand;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\Unit;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Product catalogue admin (04-01…04-03). Domain rules live in ProductService.
 */
class ProductController extends Controller
{
    public function __construct(
        protected ProductService $products,
        protected CreateProduct $createProduct,
    ) {}

    public function index(Request $request): View
    {
        $query = Product::query()
            ->with(['category', 'unit', 'brand'])
            ->orderBy('sku');

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        } elseif ($request->query('status') === 'active') {
            $query->where('is_active', true);
        }

        return view('inventory.products.index', [
            'products' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
        ]);
    }

    public function create(): View
    {
        return view('inventory.products.form', [
            'product' => new Product(['cost_method' => 'wac', 'is_stocked' => true, 'is_active' => true]),
            'mode' => 'create',
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'costMethods' => Product::COST_METHODS,
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        try {
            $product = $this->createProduct->handle($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.index')
            ->with('status', "Product {$product->sku} created.");
    }

    public function edit(Product $product): View
    {
        return view('inventory.products.form', [
            'product' => $product,
            'mode' => 'edit',
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'costMethods' => Product::COST_METHODS,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $this->products->update($product, $request->validated());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.index')
            ->with('status', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->products->delete($product);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['product' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.index')
            ->with('status', 'Product deleted.');
    }
}
