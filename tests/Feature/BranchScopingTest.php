<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Warehouse;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Server-side branch scoping (Rule 5): row visibility is decided by the
 * trusted TenantContext on EVERY query and EVERY listing — never by the
 * client, never by the view.
 */
class BranchScopingTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    private function secondBranch(string $code, string $name): Branch
    {
        return Branch::create([
            'company_id' => Company::current()?->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function test_warehouse_rows_are_filtered_to_the_active_branch(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = $this->secondBranch('CTG', 'Chittagong Depot');

        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ho->id,
            'code' => 'WHQ',
            'name' => 'HO Depot',
            'is_active' => true,
        ]);
        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        // Branch-bound context: the other branch's row must not leak.
        $this->bindTenantContext($admin, $ho);

        $names = Warehouse::query()->pluck('name')->all();

        $this->assertSame(1, count($names));
        $this->assertContains('HO Depot', $names);
        $this->assertNotContains('CTG Depot', $names);
    }

    public function test_branch_listing_only_reaches_accessible_branches(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = $this->secondBranch('CTG', 'Chittagong Depot');

        $role = $this->roleWith(['portal.erp.access', 'branches.view']);
        $limited = $this->makeUser(['branch_scope' => 'assigned']);
        $limited->roles()->attach($role->id);

        $this->actingAs($limited)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertSee($ho->name)
            ->assertDontSee('Chittagong Depot');

        // An all-branch actor sees every branch.
        $this->actingAs($admin)
            ->get(route('branches.index'))
            ->assertOk()
            ->assertSee('Chittagong Depot');
    }

    public function test_warehouse_listing_only_reaches_accessible_branches(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = $this->secondBranch('CTG', 'Chittagong Depot');

        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ho->id,
            'code' => 'WHQ',
            'name' => 'HO Depot',
            'is_active' => true,
        ]);
        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        $role = $this->roleWith(['portal.erp.access', 'warehouses.view']);
        $limited = $this->makeUser(['branch_scope' => 'assigned']);
        $limited->roles()->attach($role->id);

        $this->actingAs($limited)
            ->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('HO Depot')
            ->assertDontSee('CTG Depot');
    }

    public function test_scoping_is_a_no_op_without_a_company_context(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = $this->secondBranch('CTG', 'Chittagong Depot');

        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ho->id,
            'code' => 'WHQ',
            'name' => 'HO Depot',
            'is_active' => true,
        ]);
        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        $context = $this->bindTenantContext($admin, $ho);
        $this->assertSame(1, Warehouse::query()->count());

        // Console-style: without a company there is nothing to scope against.
        $context->setCompany(null);
        $this->assertSame(2, Warehouse::query()->count());
    }
}
