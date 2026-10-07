<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Product;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\Services\PriceListService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Price list admin (02-108): headers plus per-product price rows that
 * PricingService resolves for server-authoritative unit prices.
 */
class PriceListController extends Controller
{
    public function __construct(protected PriceListService $priceLists) {}

    public function index(Request $request): View
    {
        $query = PriceList::query()
            ->where('company_id', $request->user()->company_id)
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('code');

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->query('status') === 'inactive') {
            $query->where('is_active', false);
        } elseif ($request->query('status') === 'active') {
            $query->where('is_active', true);
        }

        return view('pricing.price-lists.index', [
            'lists' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('pricing.price-lists.form', [
            'list' => new PriceList(['is_active' => true]),
            'mode' => 'create',
            'products' => $this->productOptions((int) $request->user()->company_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatePayload($request);

        try {
            $list = $this->priceLists->create((int) $request->user()->company_id, $payload);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('pricing.price-lists.index')
            ->with('status', "Price list {$list->code} created.");
    }

    public function edit(Request $request, PriceList $priceList): View
    {
        $priceList->load('items');

        return view('pricing.price-lists.form', [
            'list' => $priceList,
            'mode' => 'edit',
            'products' => $this->productOptions((int) $request->user()->company_id),
        ]);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $payload = $this->validatePayload($request);

        try {
            $this->priceLists->update($priceList, $payload);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('pricing.price-lists.index')
            ->with('status', "Price list {$priceList->code} updated.");
    }

    public function destroy(Request $request, PriceList $priceList): RedirectResponse
    {
        try {
            $this->priceLists->delete($priceList);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['price_list' => $e->getMessage()]);
        }

        return redirect()
            ->route('pricing.price-lists.index')
            ->with('status', "Price list {$priceList->code} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:128'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @return Collection<int, Model>
     */
    protected function productOptions(int $companyId)
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name']);
    }
}
