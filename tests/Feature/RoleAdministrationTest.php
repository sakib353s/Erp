<?php

namespace Tests\Feature;

use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Role administration: slug normalisation, system-role locks, guarded
 * deletion, and IMMEDIATE permission-cache invalidation when a grant set
 * changes (Rule 6 — cached grants must never outlive their source).
 */
class RoleAdministrationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
    }

    public function test_slug_is_normalised_on_store(): void
    {
        $admin = $this->bootInstance();

        $r = $this->actingAs($admin)->post(route('roles.store'), [
            'name' => 'Sales Manager!',
            'slug' => 'Sales Manager!',
            'description' => 'Handles the sales floor.',
            'permissions' => [],
        ]);

        $role = Role::query()->where('name', 'Sales Manager!')->firstOrFail();

        $r->assertStatus(302);
        $this->assertStringContainsString('/app/roles/', (string) $r->headers->get('Location'));
        $this->assertSame('sales-manager', $role->slug);
        $this->assertFalse((bool) $role->is_system);
        $this->assertDatabaseHas('audit_events', ['action' => 'role.create']);
    }

    public function test_system_role_code_and_row_are_locked(): void
    {
        $admin = $this->bootInstance();

        $sys = Role::query()->create([
            'company_id' => $admin->company_id,
            'name' => 'Auditor',
            'slug' => 'auditor',
            'is_system' => true,
        ]);

        // Slug change on a system role → refused, code untouched.
        $this->actingAs($admin)->put(route('roles.update', $sys), [
            'name' => 'Auditor Renamed',
            'slug' => 'auditor-renamed',
            'permissions' => [],
        ])->assertStatus(422);

        $sys->refresh();
        $this->assertSame('auditor', $sys->slug);

        // Deletion of a system role → refused, row survives.
        $this->actingAs($admin)
            ->delete(route('roles.destroy', $sys))
            ->assertStatus(422);

        $this->assertNotNull(Role::query()->find($sys->id));
    }

    public function test_a_role_with_users_cannot_be_deleted(): void
    {
        $admin = $this->bootInstance();

        $role = $this->roleWith(['users.view']);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $this->actingAs($admin)
            ->delete(route('roles.destroy', $role))
            ->assertStatus(422);

        $this->assertNotNull(Role::query()->find($role->id));
        $this->assertSame(1, $user->roles()->count());
    }

    public function test_permission_changes_invalidate_cached_grants_immediately(): void
    {
        $admin = $this->bootInstance();
        $portalId = Permission::query()->where('key', 'portal.erp.access')->firstOrFail()->id;

        $role = $this->roleWith(['portal.erp.access', 'users.view']);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        // Warm the permission cache: the route guard passes.
        $this->actingAs($user)->get(route('users.index'))->assertOk();

        // The grant set changes (portal access deliberately kept).
        $this->actingAs($admin)->put(route('roles.update', $role), [
            'name' => $role->name,
            'slug' => $role->slug,
            'permissions' => [$portalId],
        ])->assertStatus(302);

        // Cached grants must be gone on the very next request.
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
    }
}
