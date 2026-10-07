<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Actions\DuplicateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\Services\ProductImportService;
use App\Domain\Inventory\Services\ProductService;
use App\Domain\Masters\Brand;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\Unit;
use App\Http\Requests\DuplicateProductRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Product catalogue admin (04-01…04-03). Domain rules live in ProductService.
 */
class ProductController extends Controller
{
    public function __construct(
        protected ProductService $products,
        protected CreateProduct $createProduct,
        protected DuplicateProduct $duplicateProduct,
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
            'counts' => $this->counts($request->user()),
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
            $this->products->update($product, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['sku' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.index')
            ->with('status', 'Product updated.');
    }

    /**
     * The catalogue as CSV (§04-12). The columns are the import template's own,
     * in its own order, so an export can be edited and imported straight back —
     * that round trip is the point of an export in an ERP, not a report.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));

        $query = Product::query()
            ->where('company_id', $user->company_id)
            ->with(['category:id,code', 'brand:id,code', 'unit:id,code'])
            ->orderBy('sku');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (($data['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($data['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $products = $query->get();

        return response()->streamDownload(function () use ($products): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ProductImportService::COLUMNS);

            foreach ($products as $product) {
                fputcsv($out, [
                    $product->code,
                    $product->sku,
                    $product->name,
                    $product->category?->code,
                    $product->brand?->code,
                    $product->unit?->code,
                    $product->barcode,
                    $product->description,
                    $product->cost_method,
                    number_format((float) $product->standard_cost, 4, '.', ''),
                    $product->is_stocked ? '1' : '0',
                    $product->track_batch ? '1' : '0',
                    $product->is_active ? '1' : '0',
                ]);
            }

            fclose($out);
        }, 'products-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The copy form (§04-04) — prefilled with an identity that is actually free. */
    public function duplicateForm(Request $request, Product $product): View
    {
        $this->assertSameCompany($request, $product);

        return view('inventory.products.duplicate', [
            'product' => $product,
            'suggestedCode' => $this->freeCode($product),
            'suggestedSku' => $this->freeSku($product),
            'sourceStock' => (float) StockBalance::query()
                ->where('product_id', $product->id)
                ->sum('on_hand'),
        ]);
    }

    public function duplicate(DuplicateProductRequest $request, Product $product): RedirectResponse
    {
        $this->assertSameCompany($request, $product);

        try {
            $copy = $this->duplicateProduct->handle($product, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.products.edit', $copy)
            ->with('status', "Product {$copy->sku} copied from {$product->sku} — its own stock starts at zero.");
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

    /**
     * A product from another company is not this company's product. The instance
     * is single-company by decision D1, so this should never fire — which is
     * exactly why it is a one-line refusal and not an assumption.
     */
    protected function assertSameCompany(Request $request, Product $product): void
    {
        abort_unless(
            (int) $product->company_id === (int) $request->user()?->company_id,
            404,
            'That product does not exist.',
        );
    }

    protected function freeCode(Product $product): string
    {
        $n = 2;

        while (Product::query()
            ->where('company_id', $product->company_id)
            ->where('code', $candidate = $product->code.'-'.$n)
            ->exists()) {
            $n++;
        }

        return $candidate;
    }

    protected function freeSku(Product $product): string
    {
        $n = 2;

        while (Product::query()
            ->where('company_id', $product->company_id)
            ->where('sku', $candidate = $product->sku.'-'.$n)
            ->exists()) {
            $n++;
        }

        return $candidate;
    }

    /** @return array<string, int> */
    protected function counts(?User $user): array
    {
        $base = fn () => Product::query()->where('company_id', $user?->company_id);

        return [
            'total' => $base()->count(),
            'active' => $base()->where('is_active', true)->count(),
            'inactive' => $base()->where('is_active', false)->count(),
            'tracked' => $base()->where(fn ($q) => $q->where('track_batch', true)->orWhere('track_serial', true))->count(),
        ];
    }
}
