<?php

namespace Tests\Feature;

use App\Domain\Foundation\FeatureEntitlement;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Services\EntitlementService;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Rule 6: database-driven permissions — guests rejected, missing grants
 * denied (with an audit row), role grants and wildcards honoured, direct
 * denies beating role grants, super admin bypass. Route guards may stack
 * permission + feature gates; every key passed to `permission:` is ANDed.
 */
class PermissionAuthorizationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
    }

    public function test_guests_get_a_redirect_for_html_and_401_for_json(): void
    {
        $this->get('/app/users')->assertRedirect(route('login'));

        $this->getJson('/app/users')->assertStatus(401);
    }

    public function test_missing_permission_yields_403_with_an_audit_row(): void
    {
        $this->bootInstance();

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']); // deliberately NOT users.view
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/users')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied',
            'reason' => 'missing permission: users.view',
        ]);
    }

    public function test_granted_permission_allows_access(): void
    {
        $this->bootInstance();

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'users.view']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/users')->assertOk();
    }

    public function test_wildcard_grant_covers_child_permissions(): void
    {
        $this->bootInstance();

        // The catalog's matcher accepts module-level wildcard keys; the
        // seeded vocabulary ships only concrete keys, so materialise the
        // wildcard entry the same way any grantable row would exist.
        $wildcard = Permission::firstOrCreate(
            ['key' => 'users.*'],
            ['module' => 'foundation', 'resource' => 'users', 'action' => '*', 'label' => 'All user permissions', 'is_system' => true],
        );

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $role->permissions()->syncWithoutDetaching([$wildcard->id]);
        $user->roles()->attach($role->id);

        // users.* must satisfy the users.view route guard.
        $this->actingAs($user)->get('/app/users')->assertOk();
    }

    public function test_direct_deny_beats_a_role_grant(): void
    {
        $this->bootInstance();

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'users.view']);
        $user->roles()->attach($role->id);

        $permission = Permission::query()->where('key', 'users.view')->firstOrFail();
        $user->directPermissions()->attach($permission->id, ['effect' => 'deny']);

        $this->actingAs($user)->get('/app/users')->assertForbidden();
    }

    public function test_super_admin_passes_without_any_explicit_grant(): void
    {
        $admin = $this->bootInstance();

        // No seeded grants for this user beyond is_super_admin + Gate::before.
        $admin->roles()->detach();

        $this->actingAs($admin)->get('/app/users')->assertOk();
    }

    public function test_stacked_permission_and_feature_gates_both_apply(): void
    {
        $admin = $this->bootInstance();

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'dashboard.view']);
        $user->roles()->attach($role->id);

        // /app/dashboard stacks permission:dashboard.view + feature:dashboard;
        // both pass while the feature stays entitled (default on).
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        FeatureEntitlement::query()->updateOrCreate(
            ['company_id' => $admin->company_id, 'feature_key' => 'dashboard'],
            ['is_enabled' => false, 'source' => 'test'],
        );
        app(EntitlementService::class)->forget();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }
}
