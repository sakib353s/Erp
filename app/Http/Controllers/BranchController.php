<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\BranchScope;
use App\Domain\Foundation\Services\BranchComparison;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\StockTransferService;
use App\Domain\Inventory\StockTransfer;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\StoreBranchTransferRequest;
use App\Http\Requests\UpdateBranchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Branch administration (Rule: one company, many branches). */
class BranchController extends Controller
{
    public function __construct(
        protected AuditRecorder $audit,
        protected BranchComparison $comparison,
        protected StockTransferService $transfers,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $ids = $actor->accessibleBranchIds();

        $query = Branch::query()->orderBy('name');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        return view('branches.index', [
            'branches' => $query->withCount('users')->paginate(15)->withQueryString(),
            'q' => $search,
        ]);
    }

    public function create(): View
    {
        return view('branches.form', ['branch' => new Branch(['is_active' => true]), 'mode' => 'create']);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $branch = Branch::create($request->validated() + [
            'company_id' => $request->user()->company_id,
        ]);

        if ($branch->is_default) {
            Branch::query()->whereKeyNot($branch->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $this->audit->record([
            'action' => 'branch.create',
            'entity_type' => 'branch',
            'entity_id' => $branch->id,
            'branch_id' => $branch->id,
            'actor_id' => $request->user()->id,
            'after' => ['code' => $branch->code, 'name' => $branch->name],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('branches.index')->with('status', 'Branch created.');
    }

    public function show(Request $request, Branch $branch): View
    {
        $this->authorizeTarget($request, $branch);

        return view('branches.show', [
            'branch' => $branch->loadCount('users'),
        ]);
    }

    public function edit(Request $request, Branch $branch): View
    {
        $this->authorizeTarget($request, $branch);

        return view('branches.form', ['branch' => $branch, 'mode' => 'edit']);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorizeTarget($request, $branch);

        $branch->fill($request->validated());
        $branch->save();

        if ($branch->is_default) {
            Branch::query()->whereKeyNot($branch->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $this->audit->record([
            'action' => 'branch.update',
            'entity_type' => 'branch',
            'entity_id' => $branch->id,
            'branch_id' => $branch->id,
            'actor_id' => $request->user()->id,
            'after' => ['name' => $branch->name, 'is_active' => $branch->is_active],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('branches.index')->with('status', 'Branch updated.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorizeTarget($request, $branch);

        abort_if($branch->is_default, 422, 'The default branch cannot be deleted.');
        abort_if($branch->users()->exists(), 422, 'Reassign the users of this branch before deleting it.');
        abort_if(\App\Domain\Foundation\Warehouse::query()->where('branch_id', $branch->id)->exists(), 422,
            'Delete or move this branch’s warehouses first.');

        $snapshot = ['code' => $branch->code, 'name' => $branch->name];
        $branch->delete();

        $this->audit->record([
            'action' => 'record.delete',
            'entity_type' => 'branch',
            'entity_id' => $branch->id,
            'actor_id' => $request->user()->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('branches.index')->with('status', 'Branch deleted.');
    }

    /**
     * §12-07 — the branches side by side.
     *
     * For the people who can see every branch and nobody else: a branch-scoped
     * user comparing their branch with branches they cannot open would be
     * reading somebody else's numbers out of a screen that pretends to be a
     * report. The permission and the scope both have to allow it, and the
     * refusal says which one did not.
     */
    public function compare(Request $request): View
    {
        $actor = $request->user();

        abort_unless($this->comparison->isWholeCompany($actor), 403,
            'Branch comparison is for users whose scope is the whole company.');

        $comparison = $this->comparison->compare($request->query('month'), $actor);

        return view('branches.compare', [
            'comparison' => $comparison,
            'months' => $this->comparison->months(),
            'canTransfer' => $actor->can('branches.transfer'),
        ]);
    }

    /**
     * §12-08 — every transfer that crossed a branch boundary, newest first.
     *
     * The branch desk answers "what has this branch sent and received"; this
     * answers "what is moving around the company at all", which is the question
     * the person who owns the stock asks. A transfer between two warehouses of
     * the same branch is not in it: that never crossed a boundary.
     */
    public function transferOverview(Request $request): View
    {
        $actor = $request->user();
        $companyId = (int) $actor->company_id;
        $ids = $actor->accessibleBranchIds();

        // Company-wide: the point of the overview is to see both ends of a move
        // between branches, and the branch scope would hide the far one.
        $warehouses = Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('company_id', $companyId)
            ->whereNotNull('branch_id')
            ->when($ids !== null, fn ($query) => $query->whereIn('branch_id', $ids))
            ->get(['id', 'branch_id'])
            ->groupBy('branch_id')
            ->map(fn ($group) => $group->pluck('id')->all());

        $transfers = StockTransfer::query()
            ->where('company_id', $companyId)
            ->when($ids !== null, fn ($query) => $query->whereIn('branch_id', $ids))
            ->with(['fromWarehouse.branch', 'toWarehouse.branch', 'lines'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->get()
            // A branch transfer is one whose two ends belong to different
            // branches — the same rule the raise form enforces.
            ->filter(fn (StockTransfer $transfer) => $transfer->fromWarehouse?->branch_id !== null
                && $transfer->toWarehouse?->branch_id !== null
                && (int) $transfer->fromWarehouse->branch_id !== (int) $transfer->toWarehouse->branch_id)
            ->values();

        return view('branches.transfers', [
            'transfers' => $transfers,
            'branches' => $this->comparison->branches($actor),
            'threshold' => $this->transfers->approvalThreshold(),
        ]);
    }

    /**
     * §12-08 — what has moved between this branch and the others.
     *
     * A branch transfer is not a second kind of transfer: stock leaves one
     * warehouse and arrives at another, and the engine that moves it, values
     * it, holds it for approval above the threshold and posts the ledger is
     * the one the inventory desk uses. This screen is the branch's own front
     * door to that engine — the same transfer seen from the branch rather
     * than from the stockroom.
     */
    public function transfer(Request $request, Branch|string $branch): View
    {
        $branch = $this->scopedBranch($branch);
        $this->authorizeTarget($request, $branch);

        $warehouses = $this->branchWarehouses($branch);

        return view('branches.transfer', [
            'branch' => $branch,
            'warehouses' => $warehouses,
            'products' => $this->products(),
            'destinations' => $this->otherWarehouses($branch),
            'transfers' => $this->transfersInvolving($branch),
            'threshold' => $this->transfers->approvalThreshold(),
            'canTransfer' => $request->user()->can('branches.transfer'),
        ]);
    }

    /** Raise the transfer through the stock-transfer engine, approval and all. */
    public function raiseTransfer(StoreBranchTransferRequest $request, Branch|string $branch): RedirectResponse
    {
        $branch = $this->scopedBranch($branch);
        $this->authorizeTarget($request, $branch);

        abort_unless($request->user()->can('branches.transfer'), 403,
            'You may look at this desk but not move stock out of it.');

        try {
            $transfer = $this->transfers->create($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        // The transfer is filed against the user's own branch by the engine;
        // what this door adds is that the operator is standing at the branch
        // the goods leave from, so the message says where they went.
        $message = $transfer->isPending()
            ? sprintf('Transfer %s is worth %s — it is above the %s limit and waits for a second person before anything leaves.',
                $transfer->transfer_no,
                number_format((float) $transfer->total_value, 2),
                number_format($this->transfers->approvalThreshold(), 2))
            : sprintf('Transfer %s raised from %s.', $transfer->transfer_no, $branch->name);

        return redirect()
            ->route('branches.transfer', $branch)
            ->with('status', $message);
    }

    /** The products a transfer may name: active and actually stocked. */
    protected function products()
    {
        return \App\Domain\Inventory\Product::query()
            ->active()
            ->stocked()
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);
    }

    /**
     * The warehouses stock would leave from: this branch's own.
     *
     * Read company-wide and filtered by the branch asked for, not by the branch
     * the reader happens to be standing in — an all-branch user opening the
     * depot's desk must see the depot's warehouses, not their own.
     */
    protected function branchWarehouses(Branch $branch)
    {
        return Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('company_id', (int) $branch->company_id)
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get();
    }

    /** Where it can go: every other branch's, so a transfer always crosses one. */
    protected function otherWarehouses(Branch $branch)
    {
        return Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('company_id', (int) $branch->company_id)
            ->where('branch_id', '!=', $branch->id)
            ->whereNotNull('branch_id')
            ->with('branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Transfers whose either end is one of this branch's warehouses.
     *
     * Both directions are worth seeing on the same page: "we sent 40 bags to
     * Motijheel" and "Motijheel sent us a printer" are the same question asked
     * from two ends, and a branch manager needs both answers.
     */
    protected function transfersInvolving(Branch $branch)
    {
        $warehouseIds = $this->branchWarehouses($branch)->pluck('id')->all();

        if ($warehouseIds === []) {
            return StockTransfer::query()->whereRaw('1 = 0')->paginate(20);
        }

        return StockTransfer::query()
            ->where('company_id', (int) $branch->company_id)
            ->where(fn ($query) => $query
                ->whereIn('from_warehouse_id', $warehouseIds)
                ->orWhereIn('to_warehouse_id', $warehouseIds))
            ->with(['fromWarehouse.branch', 'toWarehouse.branch', 'lines'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    /** The same {branch} binding the settings desk uses: refused, not 404'd. */
    protected function scopedBranch(Branch|string $branch): Branch
    {
        if ($branch instanceof Branch) {
            return $branch;
        }

        $companyId = (int) request()->user()->company_id;

        $found = Branch::withoutGlobalScope(BranchScope::class)
            ->where('company_id', $companyId)
            ->whereKey($branch)
            ->first();

        abort_if($found === null, 404);

        return $found;
    }

    protected function authorizeTarget(Request $request, Branch $branch): void
    {
        $request->user()->can('view', $branch)
            || abort(403, 'You do not have access to this branch.');
    }
}
