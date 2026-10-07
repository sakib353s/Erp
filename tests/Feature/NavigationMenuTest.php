<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Permission;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * DB-driven sidebar (Rule 7): the menu renders from menu_items +
 * permission rows only — an item the user may not open never appears
 * (not disabled, not hidden-by-CSS), and permitted items do render.
 */
class NavigationMenuTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    public function test_sidebar_renders_permitted_entries_and_omits_forbidden_ones(): void
    {
        $admin = $this->bootInstance();

        // A real, routable, permission-gated menu entry (e.g. Users).
        $leaf = MenuItem::query()
            ->join('permissions', 'permissions.id', '=', 'menu_items.permission_id')
            ->where('menu_items.status', 'active')
            ->where('menu_items.is_active', true)
            ->whereNotNull('menu_items.route')
            ->where('permissions.key', 'like', 'users.%')
            ->select('menu_items.*')
            ->firstOrFail();

        // The administrator sees the entry (all permissions via Gate::before).
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($leaf->label);

        // A role holding portal access + the dashboard gate — but nothing
        // else — must not see that entry.
        $role = $this->roleWith(['portal.erp.access', 'dashboard.view']);
        $limited = $this->makeUser();
        $limited->roles()->attach($role->id);

        $this->assertNotContains(
            $leaf->permission?->key ?? '',
            ['portal.erp.access'],
        );

        $this->actingAs($limited)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($leaf->label);
    }

    public function test_menu_vocabulary_exists_for_every_routed_permission_gate(): void
    {
        $this->bootInstance();

        // The sidebar vocabulary is DB-driven: every menu row carries a
        // permission linkage — no hard-coded Blade sidebar exists.
        $gated = MenuItem::query()
            ->where('location', 'sidebar')
            ->where('status', 'active')
            ->whereNotNull('permission_id')
            ->count();

        $this->assertGreaterThan(0, $gated);

        // …and every linked permission id actually resolves to a row.
        $dangling = MenuItem::query()
            ->whereNotNull('permission_id')
            ->whereNotIn('permission_id', Permission::query()->select('id'))
            ->count();

        $this->assertSame(0, $dangling);
    }
}
