<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Events\RolePermissionsChanged;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role administration: CRUD + the DB-driven permission matrix UI.
 * Permission sets change → RolePermissionsChanged fires synchronously so
 * affected users see the change on their very next request (Rule 6).
 */
class RoleController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(): View
    {
        return view('roles.index', [
            'roles' => Role::query()->withCount('users')->withCount('permissions')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('roles.form', [
            'role' => new Role(['is_system' => false]),
            'matrix' => $this->matrix(),
            'assigned' => [],
            'mode' => 'create',
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $role = Role::create([
            'company_id' => $request->user()->company_id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->permissions()->sync($data['permissions'] ?? []);

        $this->audit->record([
            'action' => 'role.create',
            'entity_type' => 'role',
            'entity_id' => $role->id,
            'actor_id' => $request->user()->id,
            'after' => ['name' => $role->name, 'slug' => $role->slug, 'permissions' => count($data['permissions'] ?? [])],
            'ip' => (string) $request->ip(),
        ]);

        return redirect()->route('roles.show', $role)->with('status', 'Role created.');
    }

    public function show(Role $role): View
    {
        return view('roles.show', [
            'role' => $role->load(['users', 'permissions']),
        ]);
    }

    public function edit(Role $role): View
    {
        return view('roles.form', [
            'role' => $role,
            'matrix' => $this->matrix(),
            'assigned' => $role->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all(),
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();

        if ($role->is_system && ($data['slug'] ?? $role->slug) !== $role->slug) {
            abort(422, 'System roles cannot change their code.');
        }

        $before = $role->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all();

        $role->fill(array_diff_key($data, array_flip(['permissions'])))->save();

        if (array_key_exists('permissions', $data)) {
            $role->permissions()->sync($data['permissions']);
        }

        $this->audit->record([
            'action' => empty($data['permissions']) ? 'role.update' : 'role.permissions_synced',
            'entity_type' => 'role',
            'entity_id' => $role->id,
            'actor_id' => $request->user()->id,
            'before' => ['permission_ids' => $before],
            'after' => ['permission_ids' => $data['permissions'] ?? $before, 'name' => $role->name],
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(roleId: $role->id));

        return redirect()->route('roles.show', $role)->with('status', 'Role updated.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->is_system, 422, 'System roles cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Reassign the users of this role before deleting it.');

        $userIds = []; // no users (guarded above)
        $snapshot = ['name' => $role->name, 'slug' => $role->slug];

        $role->permissions()->detach();
        $role->delete();

        $this->audit->record([
            'action' => 'role.delete',
            'entity_type' => 'role',
            'entity_id' => $role->id,
            'actor_id' => $request->user()->id,
            'before' => $snapshot,
            'ip' => (string) $request->ip(),
        ]);

        event(new RolePermissionsChanged(userIds: $userIds));

        return redirect()->route('roles.index')->with('status', 'Role deleted.');
    }

    /** @return array<string, array<int, Permission>> module => permissions */
    protected function matrix(): array
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('resource')
            ->orderBy('action')
            ->get()
            ->groupBy('module')
            ->all();
    }
}
