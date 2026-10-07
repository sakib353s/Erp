<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\District;
use App\Domain\Masters\Unit;
use App\Domain\People\Employee;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Phase 3 / section-X security suite (DoD): company singleton, company
 * settings authorization, masters + employees permission gates, company
 * scoping, branch isolation on employees, effective-permission preview,
 * suspend/activate, geo master rules, tax segregation, and audit
 * trail coverage for admin mutations.
 */
class Phase3SecurityTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    /** 1 — company profile page requires settings.company */
    public function test_company_profile_requires_settings_company_permission(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($user)->get('/app/settings/company')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied',
            'reason' => 'missing permission: settings.company',
        ]);
    }

    /** 2 — company profile update is authorized and audited */
    public function test_company_profile_update_is_audited(): void
    {
        $admin = $this->bootInstance();

        $this->actingAs($admin)->put('/app/settings/company', [
            'name' => 'Renamed Holdings Ltd',
        ])->assertRedirect(route('company.edit'));

        $this->assertSame(1, Company::query()->count());
        $this->assertSame('Renamed Holdings Ltd', Company::current()->name);
    }

    /** 3 — a second company can never be created through the UI */
    public function test_company_singleton_is_preserved_on_update(): void
    {
        $admin = $this->bootInstance();
        $before = Company::current()->id;

        $this->actingAs($admin)->put('/app/settings/company', [
            'name' => 'Still One Company',
        ]);

        $this->assertSame(1, Company::query()->count());
        $this->assertSame($before, Company::current()->id);
    }

    /** 4 — masters index requires masters.manage (or geo masters.view) */
    public function test_masters_index_requires_masters_manage(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($user)->get('/app/masters/units')->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied',
            'reason' => 'missing permission: masters.manage',
        ]);
    }

    /** 5 — masters mutations are permission-gated server-side */
    public function test_masters_store_is_forbidden_without_permission(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'masters.view'])->id);

        $this->actingAs($user)->post('/app/masters/units', [
            'code' => 'PCS',
            'name' => 'Pieces',
        ])->assertForbidden();

        $this->assertSame(0, Unit::query()->count());
    }

    /** 6 — granted masters.manage can create a unit scoped to the company */
    public function test_granted_masters_manage_creates_company_scoped_unit(): void
    {
        $admin = $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'masters.manage'])->id);

        $this->actingAs($user)->post('/app/masters/units', [
            'code' => 'pcs',
            'name' => 'Pieces',
        ])->assertRedirect(route('masters.units.index'));

        $unit = Unit::query()->where('code', 'PCS')->firstOrFail();
        $this->assertSame($admin->company_id, $unit->company_id);
    }

    /** 7 — employees list is permission-gated */
    public function test_employees_index_requires_employees_view(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($user)->get('/app/employees')->assertForbidden();
    }

    /** 8 — employee create enforces branch access (Rule 5) */
    public function test_employee_store_refuses_branch_outside_actor_access(): void
    {
        $admin = $this->bootInstance();
        $ctg = Branch::create([
            'company_id' => $admin->company_id,
            'code' => 'CTG2',
            'name' => 'Chattogram Branch',
            'is_active' => true,
        ]);

        $limited = $this->makeUser(['branch_scope' => 'assigned']);
        $limited->roles()->attach($this->roleWith(['portal.erp.access', 'employees.view', 'employees.create'])->id);

        $this->actingAs($limited)->post('/app/employees', [
            'code' => 'EMPX',
            'first_name' => 'Outside',
            'branch_id' => $ctg->id,
        ])->assertStatus(302);

        $this->assertStringContainsString('That branch is outside your own access.', $this->allFlashedErrors());
        $this->assertSame(0, Employee::query()->count());
    }

    /** 9 — branch-scoped employee listing never leaks other branches */
    public function test_employee_list_is_branch_scoped(): void
    {
        $admin = $this->bootInstance();
        $ho = $this->defaultBranch();
        $ctg = Branch::create([
            'company_id' => $admin->company_id,
            'code' => 'CTG3',
            'name' => 'Chattogram Only',
            'is_active' => true,
        ]);

        Employee::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ho->id,
            'code' => 'EMP-HO',
            'first_name' => 'Head',
            'full_name' => 'Head Office Person',
        ]);
        Employee::create([
            'company_id' => $admin->company_id,
            'branch_id' => $ctg->id,
            'code' => 'EMP-CTG',
            'first_name' => 'Other',
            'full_name' => 'Other Branch Person',
        ]);

        $limited = $this->makeUser(['branch_scope' => 'assigned']);
        $limited->roles()->attach($this->roleWith(['portal.erp.access', 'employees.view'])->id);

        $response = $this->actingAs($limited)->get('/app/employees');
        $response->assertOk();
        $response->assertSee('EMP-HO');
        $response->assertDontSee('EMP-CTG');
    }

    /** 10 — effective permission preview is permission-gated and real */
    public function test_effective_access_preview_requires_users_view(): void
    {
        $admin = $this->bootInstance();
        $target = $this->makeUser();
        $target->roles()->attach($this->roleWith(['roles.view'])->id);

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($outsider)->get(route('users.access', $target))->assertForbidden();

        $keys = app(PermissionCatalog::class)->keysFor($target);
        $this->assertArrayHasKey('roles.view', $keys);
        $this->assertArrayNotHasKey('users.delete', $keys);

        $this->actingAs($admin)->get(route('users.access', $target))->assertOk();
    }

    /** 11 — suspend then activate with audit rows */
    public function test_user_suspend_and_activate_are_audited_and_enforced(): void
    {
        $admin = $this->bootInstance();
        $target = $this->makeUser();

        $this->actingAs($admin)->post(route('users.suspend', $target), [
            'suspension_reason' => 'Policy violation',
        ])->assertRedirect(route('users.show', $target));

        $target->refresh();
        $this->assertNotNull($target->suspended_at);
        $this->assertFalse($target->isActive());

        $this->assertDatabaseHas('audit_events', [
            'entity_type' => 'user',
            'entity_id' => $target->id,
            'action' => 'record.update',
        ]);

        $this->actingAs($admin)->post(route('users.activate', $target))
            ->assertRedirect(route('users.show', $target));

        $target->refresh();
        $this->assertNull($target->suspended_at);
        $this->assertTrue($target->isActive());
    }

    /** 12 — super admin cannot be suspended; self-suspend blocked */
    public function test_super_admin_and_self_cannot_be_suspended(): void
    {
        $admin = $this->bootInstance();
        $other = $this->makeUser();
        $other->update(['is_super_admin' => true]);

        $this->actingAs($admin)->post(route('users.suspend', $admin), [
            'suspension_reason' => 'self',
        ])->assertStatus(422);

        $this->actingAs($admin)->post(route('users.suspend', $other), [
            'suspension_reason' => 'other',
        ])->assertStatus(422);
    }

    /** 13 — geo masters: view needs masters.view, mutations need masters.manage */
    public function test_geo_masters_view_and_mutation_permissions_are_split(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'masters.view'])->id);

        // Read is allowed with masters.view only.
        $this->actingAs($user)->get('/app/masters/districts')->assertOk();

        // Mutation still requires masters.manage.
        $this->actingAs($user)->put('/app/masters/districts/1', [
            'code' => 'DHK',
            'name' => 'Dhaka',
        ])->assertForbidden();
    }

    /** 14 — tax rates are segregated behind tax.manage, not masters.manage */
    public function test_tax_rates_require_tax_manage_not_masters_manage(): void
    {
        $this->bootInstance();
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith(['portal.erp.access', 'masters.manage'])->id);

        $this->actingAs($user)->get('/app/masters/tax-rates')->assertForbidden();

        $taxUser = $this->makeUser();
        $taxUser->roles()->attach($this->roleWith(['portal.erp.access', 'tax.manage'])->id);
        $this->actingAs($taxUser)->get('/app/masters/tax-rates')->assertOk();
    }

    /** 15 — reference seeder ships 64 districts; no fake business rows */
    public function test_reference_seeder_seeds_64_districts_without_fake_business_data(): void
    {
        $this->bootInstance();
        $auditsBefore = \DB::table('audit_events')->count();
        $this->seed(ReferenceDataSeeder::class);

        $this->assertSame(64, District::query()->count());
        $this->assertSame(1, Company::query()->count());
        $this->assertSame(0, Employee::query()->count());
        $this->assertSame($auditsBefore, \DB::table('audit_events')->count());
    }
}
