<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Warehouse;
use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Warehouse administration. Always branch-bound; the branch picker is
 * limited to the actor's accessible branches server-side (Rule 5).
 */
class WarehouseController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $ids = $actor->accessibleBranchIds();

        $query = Warehouse::query()->with('branch')->orderBy('name');

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

        $warehouse = Warehouse::create($data + ['company_id' => $request->user()->company_id]);

        if ($warehouse->is_default) {
            Warehouse::query()
                ->where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $this->audit->record([
            'action' => 'record.create',
            'entity_type' => 'warehouse',
            'entity_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'actor_id' => $request->user()->id,
            'after' => ['code' => $warehouse->code, 'name' => $warehouse->name],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('warehouses.index')->with('status', 'Warehouse created.');
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

        $warehouse->fill($data);
        $warehouse->save();

        if ($warehouse->is_default) {
            Warehouse::query()
                ->where('branch_id', $warehouse->branch_id)
                ->whereKeyNot($warehouse->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'warehouse',
            'entity_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'actor_id' => $request->user()->id,
            'after' => ['name' => $warehouse->name, 'is_active' => $warehouse->is_active],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('warehouses.index')->with('status', 'Warehouse updated.');
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->assertBranchAllowed($request, (int) $warehouse->branch_id);

        $snapshot = ['name' => $warehouse->name, 'code' => $warehouse->code];
        $warehouse->delete();

        $this->audit->record([
            'action' => 'record.delete',
            'entity_type' => 'warehouse',
            'entity_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            'actor_id' => $request->user()->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('warehouses.index')->with('status', 'Warehouse deleted.');
    }

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
}
