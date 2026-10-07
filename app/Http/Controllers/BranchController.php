<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Branch administration (Rule: one company, many branches). */
class BranchController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

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

    protected function authorizeTarget(Request $request, Branch $branch): void
    {
        $request->user()->can('view', $branch)
            || abort(403, 'You do not have access to this branch.');
    }
}
