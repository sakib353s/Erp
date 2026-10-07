<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Warehouse;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Warehouse administration: branch-bound rows, server-side refusal of
 * branches outside the actor's own access (Rule 5), per-branch code
 * uniqueness, default-flag swapping, and fail-closed route binding for
 * another branch's warehouse.
 */
class WarehouseAdministrationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    public function test_store_refuses_a_branch_outside_the_actor_access(): void
    {
        $admin = $this->bootInstance();
        $ctg = Branch::create([
            'company_id' => $admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        $role = $this->roleWith(['portal.erp.access', 'warehouses.create']);
        $limited = $this->makeUser(['branch_scope' => 'assigned']); // head office only
        $limited->roles()->attach($role->id);

        $this->actingAs($limited)->post(route('warehouses.store'), [
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
        ])->assertStatus(302);

        $this->assertStringContainsString('That branch is outside your own access.', $this->allFlashedErrors());
        $this->assertSame(0, Warehouse::query()->count());
    }

    public function test_code_is_unique_per_branch_not_per_company(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = Branch::create([
            'company_id' => $admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        // First warehouse with the code in head office → stored.
        $this->actingAs($admin)->post(route('warehouses.store'), [
            'branch_id' => $ho->id,
            'code' => 'wh1',
            'name' => 'Main Store',
        ])->assertStatus(302);

        // Same code, same branch → refused.
        $this->actingAs($admin)->post(route('warehouses.store'), [
            'branch_id' => $ho->id,
            'code' => 'WH1',
            'name' => 'Main Store Two',
        ])->assertSessionHasErrors('code');

        // Same code, ANOTHER branch → allowed (unique per branch).
        $this->actingAs($admin)->post(route('warehouses.store'), [
            'branch_id' => $ctg->id,
            'code' => 'WH1',
            'name' => 'CTG Store',
        ])->assertStatus(302);

        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('warehouses')->count());
    }

    public function test_default_flag_swaps_within_the_branch(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();

        $this->actingAs($admin)->post(route('warehouses.store'), [
            'branch_id' => $ho->id,
            'code' => 'WHA',
            'name' => 'Store A',
            'is_default' => 1,
        ])->assertStatus(302);

        $this->actingAs($admin)->post(route('warehouses.store'), [
            'branch_id' => $ho->id,
            'code' => 'WHB',
            'name' => 'Store B',
        ])->assertStatus(302);

        $a = Warehouse::query()->where('code', 'WHA')->firstOrFail();
        $b = Warehouse::query()->where('code', 'WHB')->firstOrFail();

        $this->assertTrue((bool) $a->is_default);
        $this->assertFalse((bool) $b->is_default);

        $this->actingAs($admin)->put(route('warehouses.update', $b), [
            'branch_id' => $ho->id,
            'code' => 'WHB',
            'name' => 'Store B',
            'is_default' => 1,
        ])->assertStatus(302);

        $a->refresh();
        $b->refresh();

        $this->assertFalse((bool) $a->is_default);
        $this->assertTrue((bool) $b->is_default);
        $this->assertSame(
            1,
            Warehouse::query()->where('branch_id', $ho->id)->where('is_default', true)->count(),
        );
        $this->assertDatabaseHas('audit_events', ['action' => 'record.update', 'entity_type' => 'warehouse']);
    }

    public function test_a_warehouse_of_another_branch_is_refused_for_a_cross_branch_actor(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = Branch::create([
            'company_id' => $admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        $whCtg = Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        $role = $this->roleWith(['portal.erp.access', 'warehouses.update']);
        $limited = $this->makeUser(['branch_scope' => 'assigned']); // head office only
        $limited->roles()->attach($role->id);

        // A head-office-scoped actor hits the server-side branch guard:
        // the update is refused as a validation error and the row is
        // never mutated — the client's proposal is never honoured.
        $this->actingAs($limited)->put(route('warehouses.update', $whCtg), [
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'Renamed By Outsider',
            'is_default' => 0,
        ])->assertStatus(302);

        $this->assertStringContainsString('That branch is outside your own access.', $this->allFlashedErrors());

        $whCtg->refresh();
        $this->assertSame('CTG Depot', $whCtg->name);
    }
}
