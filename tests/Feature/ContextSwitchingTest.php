<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Branch/warehouse/locale context switching (spec rules 4/5, D6/D21):
 * the client only PROPOSES an id — existence, activity, actor scope and
 * branch match are all re-validated server-side, and every accepted
 * switch rewrites the session keys SetTenantContext trusts on the next
 * request.
 */
class ContextSwitchingTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    private function extraBranch(User $admin, string $code = 'CTG', array $attributes = []): Branch
    {
        return Branch::create(array_merge([
            'company_id' => $admin->company_id,
            'code' => $code,
            'name' => 'Branch '.$code,
            'is_active' => true,
        ], $attributes));
    }

    public function test_branch_switch_updates_session_scope_and_audits(): void
    {
        $admin = $this->bootInstance();
        $ctg = $this->extraBranch($admin);

        $this->actingAs($admin)
            ->post(route('context.branch'), ['branch_id' => $ctg->id])
            ->assertStatus(302);

        $this->assertSame($ctg->id, (int) session('tenant.branch_id'));
        // A switch always drops the warehouse — it belonged to the old branch.
        $this->assertNull(session('tenant.warehouse_id'));

        $this->assertDatabaseHas('audit_events', ['action' => 'branch.switch']);
    }

    public function test_switching_outside_own_access_is_refused_server_side(): void
    {
        $admin = $this->bootInstance();
        $ctg = $this->extraBranch($admin);
        $limited = $this->makeUser(['branch_scope' => 'assigned']); // head office only

        $this->actingAs($limited)
            ->post(route('context.branch'), ['branch_id' => $ctg->id])
            ->assertForbidden();

        $this->assertNotSame($ctg->id, (int) session('tenant.branch_id'));
        $this->assertDatabaseMissing('audit_events', ['action' => 'branch.switch']);
    }

    public function test_unknown_and_inactive_branches_return_404(): void
    {
        $admin = $this->bootInstance();
        $inactive = $this->extraBranch($admin, 'SYL', ['is_active' => false]);

        $this->actingAs($admin)
            ->post(route('context.branch'), ['branch_id' => $inactive->id])
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('context.branch'), ['branch_id' => 999999])
            ->assertNotFound();
    }

    public function test_warehouse_switch_is_bound_to_the_current_branch(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = $this->extraBranch($admin);

        $whHo = Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ho->id,
            'code' => 'WHQ',
            'name' => 'HO Depot',
            'is_active' => true,
        ]);
        $whCtg = Warehouse::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'WCTG',
            'name' => 'CTG Depot',
            'is_active' => true,
        ]);

        // Context branch is head office → the Chittagong warehouse is a 404.
        $this->actingAs($admin)
            ->post(route('context.warehouse'), ['warehouse_id' => $whCtg->id])
            ->assertNotFound();

        // The head-office warehouse is inside the current branch → accepted.
        $this->actingAs($admin)
            ->post(route('context.warehouse'), ['warehouse_id' => $whHo->id])
            ->assertStatus(302);

        $this->assertSame($whHo->id, (int) session('tenant.warehouse_id'));
    }

    public function test_locale_switch_accepts_english_and_bangla_only(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)
            ->post(route('context.locale'), ['locale' => 'bn'])
            ->assertStatus(302);
        $this->assertSame('bn', session('locale'));

        $this->actingAs($admin)
            ->post(route('context.locale'), ['locale' => 'en'])
            ->assertStatus(302);
        $this->assertSame('en', session('locale'));

        $this->actingAs($admin)
            ->post(route('context.locale'), ['locale' => 'fr'])
            ->assertStatus(422);
        $this->assertSame('en', session('locale'));
    }
}
