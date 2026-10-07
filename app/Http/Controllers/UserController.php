<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Events\RolePermissionsChanged;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User administration (Rules 5/6): branch-scoped listing (an admin only
 * ever sees users inside their own accessible branches), role + branch +
 * warehouse assignment, status control, guarded deletion (never self,
 * never the last super admin). All changes audited + permission-cache
 * invalidation dispatched synchronously.
 */
class UserController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $actorBranches = $actor->accessibleBranchIds();

        $query = User::query()->with('roles')->orderBy('name');

        if ($actorBranches !== null) {
            $query->where(function ($q) use ($actorBranches, $actor) {
                $q->where('id', $actor->id)
                    ->orWhere(function ($qq) use ($actorBranches) {
                        $qq->where('branch_scope', '!=', 'all')
                            ->whereHas('branchAssignments', fn ($w) => $w->whereIn('branches.id', $actorBranches));
                    });
            });
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = (string) $request->query('status')) {
            $query->where('status', $status);
        }

        return view('users.index', [
            'users' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeScope($request);

        return view('users.form', [
            'user' => new User(['status' => 'active', 'branch_scope' => 'assigned']),
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'mode' => 'create',
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        /** @var User $user */
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
            'branch_scope' => $data['branch_scope'],
            'default_branch_id' => $data['default_branch_id'] ?? null,
            'company_id' => $request->user()->company_id,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ]);

        $this->syncAssignments($user, $data);

        $this->audit->record([
            'action' => 'record.create',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $request->user()->id,
            'branch_id' => $user->default_branch_id,
            'after' => ['name' => $user->name, 'email' => $user->email, 'status' => $user->status],
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: [$user->id]));

        return redirect()->route('users.show', $user)->with('status', 'User created.');
    }

    public function show(Request $request, User $user): View
    {
        $this->authorizeTarget($request, $user, 'view');

        return view('users.show', [
            'user' => $user->load(['roles', 'branchAssignments', 'warehouses', 'defaultBranch']),
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorizeTarget($request, $user, 'update');

        return view('users.form', [
            'user' => $user->load(['roles', 'branchAssignments']),
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user, 'update');

        $data = $request->validated();

        $user->fill(array_diff_key($data, array_flip(['password', 'roles', 'branch_ids', 'warehouse_ids'])));
        $user->password = $data['password'] ?? $user->password;
        if (isset($data['password'])) {
            $user->must_change_password = true;
            $user->password_changed_at = now();
        }
        $user->save();

        $this->syncAssignments($user, $data);

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $request->user()->id,
            'before' => ['status' => $user->getOriginal('status')],
            'after' => ['name' => $user->name, 'status' => $user->status, 'roles' => $data['roles'] ?? []],
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: [$user->id]));

        return redirect()->route('users.show', $user)->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user, 'update');

        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');

        $remainingSuperAdmins = User::query()
            ->where('is_super_admin', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($user->is_super_admin && $remainingSuperAdmins === 0) {
            abort(422, 'The last super admin cannot be deleted.');
        }

        $snapshot = ['name' => $user->name, 'email' => $user->email];
        $actor = $request->user();

        $user->roles()->detach();
        $user->directPermissions()->detach();
        $user->branchAssignments()->detach();
        $user->delete();

        $this->audit->record([
            'action' => 'record.delete',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $actor->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: [$user->id]));

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user, 'update');

        abort_if($user->id === $request->user()->id, 422, 'You cannot suspend your own account.');
        abort_if($user->is_super_admin && $user->isActive(), 422, 'The active super admin cannot be suspended.');

        $data = $request->validate([
            'suspension_reason' => ['required', 'string', 'max:500'],
        ]);

        $before = ['status' => $user->status, 'suspended_at' => $user->suspended_at?->toIso8601String()];

        $user->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => $data['suspension_reason'],
        ])->save();

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $request->user()->id,
            'before' => $before,
            'after' => ['status' => 'suspended', 'suspended_at' => now()->toIso8601String()],
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: [$user->id]));

        return redirect()->route('users.show', $user)->with('status', 'User suspended.');
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user, 'update');

        $before = ['status' => $user->status, 'suspended_at' => $user->suspended_at?->toIso8601String()];

        $user->forceFill([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->audit->record([
            'action' => 'record.update',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'actor_id' => $request->user()->id,
            'before' => $before,
            'after' => ['status' => 'active', 'suspended_at' => null],
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: [$user->id]));

        return redirect()->route('users.show', $user)->with('status', 'User reactivated.');
    }

    /** Effective permission keys after role + direct grants (preview). */
    public function access(Request $request, User $user): View
    {
        $this->authorizeTarget($request, $user, 'view');

        return view('users.access', [
            'user' => $user->load('roles'),
            'keys' => app(PermissionCatalog::class)->keysFor($user),
            'isSuperAdmin' => $user->isSuperAdmin(),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function syncAssignments(User $user, array $data): void
    {
        $user->roles()->sync($data['roles'] ?? []);
        $user->branchAssignments()->sync($data['branch_ids'] ?? []);

        if (array_key_exists('warehouse_ids', $data)) {
            $user->warehouses()->sync($data['warehouse_ids'] ?? []);
        }

        if ($user->branch_scope === 'all') {
            $user->branchAssignments()->detach();
        }
    }

    protected function authorizeTarget(Request $request, User $target, string $ability): void
    {
        $request->user()->can($ability, $target)
            ?: abort(403, 'You are not allowed to manage this user.');
    }

    protected function authorizeScope(Request $request): void
    {
        $request->user()->can('create', User::class)
            || abort(403, 'You are not allowed to create users.');
    }
}
