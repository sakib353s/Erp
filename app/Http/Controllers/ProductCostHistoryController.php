<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductCostHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Product cost history (§04-10).
 *
 * Two screens, one source. The register lists products and when their cost last
 * moved; the per-product screen is the append-only trail itself. Both read the
 * same rows, and nothing here writes: a change to a product's cost is recorded
 * by the service that makes it, with its reason and its author.
 *
 * The distinction the screen exists to keep straight: `product_cost_history` is
 * what the product *record* says it costs; `stock_layers` is what the goods
 * actually cost when they arrived. The second one is the ledger's business, and
 * the per-product screen links to it rather than pretending to be it.
 */
class ProductCostHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'changed' => ['nullable', 'in:any,30,never'],
        ]);

        $search = trim((string) ($data['q'] ?? ''));
        $changed = (string) ($data['changed'] ?? 'any');

        $query = Product::query()
            ->where('company_id', $user->company_id)
            ->with(['category:id,name', 'unit:id,name'])
            ->withCount('costHistory')
            ->addSelect(['last_cost_change_at' => ProductCostHistory::query()
                ->select('changed_at')
                ->whereColumn('product_id', 'products.id')
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(1)]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($changed === '30') {
            $query->whereHas('costHistory', fn ($q) => $q->where('changed_at', '>=', now()->subDays(30)));
        } elseif ($changed === 'never') {
            $query->whereDoesntHave('costHistory');
        }

        $today = now()->startOfDay();

        return view('inventory.products.cost-history', [
            'products' => $query->orderBy('sku')->paginate(20)->withQueryString(),
            'filters' => ['q' => $search, 'changed' => $changed],
            'today' => $today,
            'totals' => [
                'changes' => ProductCostHistory::query()->where('company_id', $user->company_id)->count(),
                'recent' => ProductCostHistory::query()
                    ->where('company_id', $user->company_id)
                    ->where('changed_at', '>=', $today->copy()->subDays(30))
                    ->count(),
                'products' => Product::query()->where('company_id', $user->company_id)->count(),
            ],
        ]);
    }

    public function forProduct(Request $request, Product $product): View
    {
        $user = $request->user();

        abort_unless(
            (int) $product->company_id === (int) $user->company_id,
            404,
            'That product does not exist.',
        );

        $product->load(['category:id,name', 'brand:id,name', 'unit:id,name']);

        $rows = ProductCostHistory::query()
            ->where('company_id', $user->company_id)
            ->forProduct($product->id)
            ->with(['actor:id,name'])
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        // The valuation story next to the record story: layers are what the stock
        // actually cost, and they are never rewritten by a cost-method change.
        $layers = $product->layers()
            ->where('qty_remaining', '>', 0)
            ->with('batch:id,batch_no,expires_on')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get(['id', 'received_at', 'qty_remaining', 'unit_cost', 'stock_batch_id']);

        return view('inventory.products.cost-history-show', [
            'product' => $product,
            'rows' => $rows->paginate(25)->withQueryString(),
            'changes' => ProductCostHistory::query()->where('company_id', $user->company_id)->forProduct($product->id)->count(),
            'lastChange' => ProductCostHistory::query()
                ->where('company_id', $user->company_id)
                ->forProduct($product->id)
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->first(),
            'layers' => $layers,
            'layerValue' => $layers->sum(fn ($layer) => (float) $layer->qty_remaining * (float) $layer->unit_cost),
            'layerQty' => (float) $layers->sum('qty_remaining'),
        ]);
    }
}
