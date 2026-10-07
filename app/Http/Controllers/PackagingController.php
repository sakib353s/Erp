<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\Actions\ConsumePackaging;
use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\PackagingUsage;
use App\Domain\Inventory\Product;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Packaging management screen (02-98): packaging types backed by
 * stock-managed products, consumption against orders through
 * ConsumePackaging (PACK_CONSUME + layer-valued cost on the order),
 * and the usage history. No fake stock is seeded — everything shown
 * comes from packaging_usage and the ledger.
 */
class PackagingController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $types = PackagingType::query()
            ->where('company_id', $companyId)
            ->with('product')
            ->orderBy('code')
            ->get();

        $usage = PackagingUsage::query()
            ->where('company_id', $companyId)
            ->with(['order', 'packagingType', 'warehouse'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $totalCost = round((float) PackagingUsage::query()
            ->where('company_id', $companyId)
            ->sum('total_cost'), 2);

        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('is_stocked', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'sku', 'name']);

        $orders = SalesOrder::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'order_no', 'status', 'warehouse_id']);

        return view('sales.delivery.packaging', [
            'types' => $types,
            'usage' => $usage,
            'totalCost' => $totalCost,
            'products' => $products,
            'orders' => $orders,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'sales_order_id' => ['required', 'integer', Rule::exists('sales_orders', 'id')
                ->where('company_id', $companyId)],
            'packaging_type_id' => ['required', 'integer', Rule::exists('packaging_types', 'id')
                ->where('company_id', $companyId)],
            'qty' => ['required', 'numeric', 'min:0.001', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(ConsumePackaging::class)->handle([
                'sales_order_id' => (int) $data['sales_order_id'],
                'packaging_type_id' => (int) $data['packaging_type_id'],
                'qty' => $data['qty'],
                'notes' => $data['notes'] ?? null,
            ], $request);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['packaging' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery.packaging.index')
            ->with('status', 'Packaging consumed — stock and order cost updated.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('packaging_types', 'code')
                ->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:120'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('company_id', $companyId)],
        ]);

        $product = Product::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['product_id']);

        if (! $product->is_stocked || ! $product->is_active) {
            return back()->withErrors([
                'product_id' => "Product {$product->sku} must be stock-managed and active to be used as packaging.",
            ]);
        }

        $type = PackagingType::create([
            'company_id' => $companyId,
            'code' => $data['code'],
            'name' => $data['name'],
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        app(AuditRecorder::class)->record([
            'action' => 'sales.packaging_type_created',
            'entity_type' => 'packaging_type',
            'entity_id' => $type->id,
            'actor_id' => $request->user()->id,
            'after' => [
                'code' => $type->code,
                'name' => $type->name,
                'product_id' => $type->product_id,
                'sku' => $product->sku,
            ],
        ]);

        return redirect()
            ->route('sales.delivery.packaging.index')
            ->with('status', 'Packaging type created.');
    }
}
