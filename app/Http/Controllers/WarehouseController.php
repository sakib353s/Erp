<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\Services\WarehouseService;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Inventory\WarehouseZone;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Warehouse administration (§04-42) and the physical structure inside it
 * (§04-43/04-45: zones, bins, product bin assignment and the map).
 *
 * Always branch-bound; the branch picker is limited to the actor's accessible
 * branches server-side (Rule 5). The write rules that must not be forgotten —
 * a code unique inside a branch, a warehouse holding stock that cannot be
 * deleted, one primary pick face per product — live in WarehouseService, not
 * here; the controller only validates what the client sent and reports what the
 * domain refused.
 *
 * Create/update/delete are audited by the model's own `Auditable` trait; the
 * structure events (zone, bin, assignment) are recorded by the service, because
 * those models are not audited automatically.
 */
class WarehouseController extends Controller
{
    public function __construct(protected WarehouseService $warehouses) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $ids = $actor->accessibleBranchIds();

        $query = Warehouse::query()
            ->with('branch')
            ->withCount(['zones', 'bins'])
            ->orderBy('name');

        if ($ids !== null) {
            $query->whereIn('branch_id', $ids);
        }

        if ($branchFilter = (int) $request->query('branch_id')) {
            if ($ids === null || in_array($branchFilter, $ids, true)) {
                $query->where('branch_id', $branchFilter);
            }
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        return view('warehouses.index', [
            'warehouses' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'branches' => $this->branches($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('warehouses.form', [
            'warehouse' => new Warehouse(['is_active' => true]),
            'branches' => $this->branches($request),
            'mode' => 'create',
        ]);
    }

    public function store(StoreWarehouseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertBranchAllowed($request, (int) $data['branch_id']);

        try {
            $warehouse = $this->warehouses->createWarehouse($data, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['warehouse' => $e->getMessage()]);
        }

        return redirect()->route('warehouses.index')
            ->with('status', "Warehouse {$warehouse->name} created — lay out its zones and bins next.");
    }

    public function edit(Request $request, Warehouse $warehouse): View
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        return view('warehouses.form', [
            'warehouse' => $warehouse,
            'branches' => $this->branches($request),
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        $data = $request->validated();
        $this->assertBranchAllowed($request, (int) $data['branch_id']);

        try {
            $warehouse = $this->warehouses->updateWarehouse($warehouse, $data, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['warehouse' => $e->getMessage()]);
        }

        return redirect()->route('warehouses.index')->with('status', 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        try {
            $this->warehouses->deleteWarehouse($warehouse, $request->user());
        } catch (\RuntimeException $e) {
            // A warehouse the ledger points at is not a delete away from being
            // gone — say so instead of letting the foreign key throw a 500.
            return back()->withErrors(['warehouse' => $e->getMessage()]);
        }

        return redirect()->route('warehouses.index')->with('status', 'Warehouse deleted — it had never moved stock.');
    }

    /* ------------------------------------------------- structure and map ------ */

    /** The map, and the layout editor, on one screen (§04-43/04-45). */
    public function show(Request $request, Warehouse $warehouse): View
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        return view('warehouses.layout', [
            'layout' => $this->warehouses->map($warehouse),
            'zoneTypes' => WarehouseZone::TYPES,
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(['id', 'sku', 'name']),
            'stale' => $this->warehouses->staleAssignments($warehouse, 60),
        ]);
    }

    public function storeZone(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:191'],
            'type' => ['required', 'string', 'max:24'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['code' => 'zone code', 'type' => 'zone type']);

        try {
            $zone = $this->warehouses->createZone($warehouse, $data, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['zone' => $e->getMessage()]);
        }

        return back()->with('status', "Zone {$zone->code} added to {$warehouse->name}.");
    }

    public function destroyZone(Request $request, WarehouseZone $zone): RedirectResponse
    {
        $this->assertSameCompany($request, $zone, 'zone');

        try {
            $this->warehouses->deleteZone($zone, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['zone' => $e->getMessage()]);
        }

        return back()->with('status', "Zone {$zone->code} removed.");
    }

    public function storeBin(Request $request, WarehouseZone $zone): RedirectResponse
    {
        $this->assertSameCompany($request, $zone, 'zone');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:191'],
            'is_pickable' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['code' => 'bin code']);

        $data['is_pickable'] = $request->boolean('is_pickable', true);

        try {
            $bin = $this->warehouses->createBin($zone, $data, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['bin' => $e->getMessage()]);
        }

        return back()->with('status', "Bin {$bin->code} added to zone {$zone->code}.");
    }

    public function destroyBin(Request $request, WarehouseBin $bin): RedirectResponse
    {
        $this->assertSameCompany($request, $bin, 'bin');

        try {
            $this->warehouses->deleteBin($bin, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['bin' => $e->getMessage()]);
        }

        return back()->with('status', "Bin {$bin->code} removed.");
    }

    /**
     * Put a product in one of this warehouse's bins. The bin arrives in the
     * payload (a zone form lists the bins it may use) and is checked against the
     * warehouse the form was opened for, so a form can never place stock in a
     * bin it was not showing.
     */
    public function assignBin(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        $data = $request->validate([
            'bin_id' => ['required', 'integer', 'exists:warehouse_bins,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['product_id' => 'product', 'bin_id' => 'bin']);

        $bin = WarehouseBin::query()
            ->where('warehouse_id', $warehouse->id)
            ->find($data['bin_id']);

        if ($bin === null) {
            return back()->withInput()->withErrors(['bin_id' => 'That bin does not belong to this warehouse.']);
        }

        try {
            $assignment = $this->warehouses->assignProduct(
                $bin,
                (int) $data['product_id'],
                $request->boolean('is_primary'),
                $data['notes'] ?? null,
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['product_id' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s is now picked from %s%s.',
            $assignment->product?->sku ?? 'The product',
            $bin->label(),
            $assignment->is_primary ? ' (primary pick face)' : '',
        ));
    }

    public function unassignBin(Request $request, ProductBinAssignment $assignment): RedirectResponse
    {
        $this->assertSameCompany($request, $assignment, 'assignment');

        $label = $assignment->bin?->label() ?? 'that bin';

        $this->warehouses->unassignProduct($assignment, $request->user());

        return back()->with('status', "Assignment removed from {$label}.");
    }

    /* ------------------------------------------------------------- helpers ---- */

    /** @return \Illuminate\Database\Eloquent\Collection<int, Branch> */
    protected function branches(Request $request)
    {
        $ids = $request->user()->accessibleBranchIds();

        $query = Branch::query()->where('is_active', true)->orderBy('name');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    protected function assertBranchAllowed(Request $request, int $branchId): void
    {
        $request->user()->hasBranchAccess($branchId)
            || abort(403, 'You do not have access to that branch.');
    }

    /**
     * Zones, bins and assignments are bound flat by id, so the binding itself
     * says nothing about who owns the row. A row from another company is not
     * forbidden — it does not exist, and answering 404 keeps it that way.
     */
    protected function assertSameCompany(
        Request $request,
        WarehouseZone|WarehouseBin|ProductBinAssignment $model,
        string $what,
    ): void {
        abort_unless(
            (int) $model->company_id === (int) $request->user()->company_id,
            404,
            "That {$what} does not exist.",
        );
    }
}
