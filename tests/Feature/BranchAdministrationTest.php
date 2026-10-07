<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Branch administration (one company, many branches): code
 * normalisation, per-company uniqueness, default-flag swapping, and the
 * hard deletion guards (default / assigned users / warehouses).
 */
class BranchAdministrationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    public function test_store_uppercases_the_code_and_audits(): void
    {
        $admin = $this->bootInstance();

        $r = $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Chittagong Depot',
            'code' => 'ctg ',
        ]);

        $r->assertStatus(302);
        $this->assertStringContainsString('/app/branches', (string) $r->headers->get('Location'));
        $this->assertDatabaseHas('branches', ['code' => 'CTG', 'name' => 'Chittagong Depot']);
        $this->assertDatabaseHas('audit_events', ['action' => 'branch.create']);
    }

    public function test_duplicate_code_within_the_company_is_refused(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Head Office Again',
            'code' => 'ho', // normalises to the existing HO code
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Branch::query()->count());
    }

    public function test_promoting_a_branch_to_default_clears_the_previous_default(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Chittagong Depot',
            'code' => 'CTG',
        ])->assertStatus(302);

        $ctg = Branch::query()->where('code', 'CTG')->firstOrFail();
        $this->assertFalse((bool) $ctg->is_default);

        $this->actingAs($admin)->put(route('branches.update', $ctg), [
            'name' => $ctg->name,
            'code' => $ctg->code,
            'is_default' => 1,
        ])->assertStatus(302);

        $ctg->refresh();
        $ho->refresh();

        $this->assertTrue((bool) $ctg->is_default);
        $this->assertFalse((bool) $ho->is_default);
        $this->assertSame(1, Branch::query()->where('is_default', true)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'branch.update']);
    }

    public function test_the_default_branch_cannot_be_deleted(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();

        $this->actingAs($admin)
            ->delete(route('branches.destroy', $ho))
            ->assertStatus(422);

        $this->assertNotNull(Branch::query()->find($ho->id));
        // A refused delete must leave no deletion audit row behind.
        $this->assertDatabaseMissing('audit_events', ['action' => 'record.delete', 'entity_type' => 'branch']);
    }

    public function test_a_branch_with_assigned_users_cannot_be_deleted(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Chittagong Depot',
            'code' => 'CTG',
        ])->assertStatus(302);

        $ctg = Branch::query()->where('code', 'CTG')->firstOrFail();

        $worker = $this->makeUser([
            'branch_scope' => 'assigned',
            'default_branch_id' => $ctg->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('branches.destroy', $ctg))
            ->assertStatus(422);

        $this->assertNotNull(Branch::query()->find($ctg->id));
        $this->assertSame($ctg->id, (int) $worker->fresh()->default_branch_id);
    }

    public function test_a_branch_with_warehouses_cannot_be_deleted(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Chittagong Depot',
            'code' => 'CTG',
        ])->assertStatus(302);

        $ctg = Branch::query()->where('code', 'CTG')->firstOrFail();

        Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        // Realistic flow: manage the branch from within its own context so
        // its warehouses are inside the active scope.
        $this->actingAs($admin)
            ->post(route('context.branch'), ['branch_id' => $ctg->id])
            ->assertStatus(302);

        $this->actingAs($admin)
            ->delete(route('branches.destroy', $ctg))
            ->assertStatus(422);

        $this->assertNotNull(Branch::query()->find($ctg->id));
        $this->assertSame(1, Warehouse::query()->count());
    }
}
