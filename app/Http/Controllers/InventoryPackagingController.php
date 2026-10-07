<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\Services\PackagingService;
use App\Http\Requests\StorePackagingTypeRequest;
use App\Http\Requests\UpdatePackagingTypeRequest;
use App\Domain\Inventory\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * §04-59/04-60/04-61 — packaging from the inventory side.
 *
 * The sales screen (02-98) is where packaging is *consumed* against an order.
 * This desk is where packaging is *kept*: which types exist, whether they can
 * still be used, what is on the shelf, what it is worth, and what was spent.
 * Both write the same two tables and read the same ledger, so there is nothing
 * here that could disagree with a dispatch.
 */
class InventoryPackagingController extends Controller
{
    public function __construct(
        protected PackagingService $packaging,
    ) {}

    /** The types register (§04-59). */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $look = $this->packaging->types($search === '' ? null : $search);

        return view('inventory.packaging.index', [
            'rows' => $look['rows'],
            'totals' => $look['totals'],
            'filters' => ['q' => $search],
            'products' => $this->products(),
        ]);
    }

    /** Packaging stock on the same ledger as everything else (§04-60). */
    public function stock(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));

        $look = $this->packaging->stock($warehouseId, $search === '' ? null : $search);

        return view('inventory.packaging.stock', [
            'rows' => $look['rows'],
            'totals' => $look['totals'],
            'filters' => ['warehouse' => $warehouseId, 'q' => $search],
            'warehouses' => $this->packaging->warehouses(),
        ]);
    }

    /** What packaging costs, from the layers that priced it (§04-61). */
    public function cost(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));
        $days = $request->filled('days') ? max(1, min(730, (int) $request->query('days'))) : 90;

        $look = $this->packaging->cost($warehouseId, $days, $search === '' ? null : $search);

        return view('inventory.packaging.cost', [
            'rows' => $look['rows'],
            'totals' => $look['totals'],
            'filters' => ['warehouse' => $warehouseId, 'q' => $search, 'days' => $days],
            'warehouses' => $this->packaging->warehouses(),
        ]);
    }

    public function store(StorePackagingTypeRequest $request): RedirectResponse
    {
        try {
            $type = $this->packaging->createType(
                $request->validated(),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['packaging' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.packaging.index')
            ->with('status', "Packaging type {$type->code} declared.");
    }

    public function update(UpdatePackagingTypeRequest $request, PackagingType $packagingType): RedirectResponse
    {
        $this->assertSameCompany($packagingType);

        try {
            $this->packaging->updateType($packagingType, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['packaging' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.packaging.index')
            ->with('status', "Packaging type {$packagingType->code} updated.");
    }

    public function toggle(Request $request, PackagingType $packagingType): RedirectResponse
    {
        $this->assertSameCompany($packagingType);

        try {
            $this->packaging->toggle($packagingType, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['packaging' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.packaging.index')
            ->with('status', $packagingType->is_active
                ? "Packaging type {$packagingType->code} is usable again."
                : "Packaging type {$packagingType->code} retired — it can no longer be consumed, and its history is kept.");
    }

    public function destroy(Request $request, PackagingType $packagingType): RedirectResponse
    {
        $this->assertSameCompany($packagingType);

        $code = $packagingType->code;

        try {
            $this->packaging->deleteType($packagingType, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['packaging' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.packaging.index')
            ->with('status', "Packaging type {$code} removed.");
    }

    /**
     * Products offered as packaging: stock-managed, active, this company's —
     * the same rule the write path enforces, applied to the picker so the form
     * cannot offer something the service will refuse.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    protected function products()
    {
        return Product::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('is_stocked', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(400)
            ->get(['id', 'sku', 'name']);
    }

    protected function assertSameCompany(PackagingType $type): void
    {
        abort_unless($type->company_id === auth()->user()->company_id, 404);
    }
}
