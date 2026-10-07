<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\NumberingRule;
use App\Domain\Foundation\NumberingSequence;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\User;
use App\Domain\Masters\Bank;
use App\Domain\Masters\Brand;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Masters\Holiday;
use App\Domain\Masters\PaymentMethod;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\Services\TaxService;
use App\Domain\Masters\SmsProvider;
use App\Domain\Masters\TaxRate;
use App\Domain\Masters\Unit;
use App\Domain\Masters\Upazila;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Phase F gate: master CRUD + company scope + numbering concurrency.
 *  - registry-driven master CRUD (units, parties, price lists, tax rates)
 *  - company scope isolation + permission gates (portal.erp.access)
 *  - structural BD seeds: 64 districts, upazilas, banks, couriers,
 *    holidays, payment methods — never fake customers/products
 *  - TaxService effective dating (history not rewritten)
 *  - NumberingService unique row-locked allocation
 */
class MastersCoreTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(DocumentTypeSeeder::class);

        $this->admin = $this->bootInstance();
        $this->seed(ReferenceDataSeeder::class);
    }

    /**
     * A second company_id for scope isolation tests. The singleton unique
     * column only allows one `singleton=1` row — operational instances
     * never create a second tenant, but scope filters still key off
     * company_id, so tests insert a non-singleton shadow row.
     */
    protected function shadowCompanyId(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_master_crud_create_update_delete_with_audit(): void
    {
        $admin = $this->admin;
        $this->actingAs($admin);

        $this->post(route('masters.units.store'), [
            'code' => 'CTN',
            'name' => 'Carton',
            'symbol' => 'ctn',
            'is_active' => 1,
        ])->assertRedirect(route('masters.units.index'));

        $unit = Unit::query()->where('code', 'CTN')->firstOrFail();
        $this->assertSame($admin->company_id, $unit->company_id);
        $this->assertTrue((bool) $unit->is_active);

        $this->put(route('masters.units.update', ['record' => $unit->getKey()]), [
            'code' => 'CTN',
            'name' => 'Carton (Large)',
            'symbol' => 'ctn',
            'is_active' => 1,
        ])->assertRedirect(route('masters.units.index'));

        $unit->refresh();
        $this->assertSame('Carton (Large)', $unit->name);

        $this->assertTrue(
            AuditEvent::query()
                ->where('entity_type', 'units')
                ->where('entity_id', $unit->id)
                ->whereIn('action', ['record.create', 'record.update'])
                ->count() >= 2,
        );

        $this->delete(route('masters.units.destroy', $unit))
            ->assertRedirect(route('masters.units.index'));

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_code_is_normalised_and_unique_per_company(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('masters.brands.store'), [
            'code' => 'nike sports',
            'name' => 'Nike Sports',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $brand = Brand::query()->where('name', 'Nike Sports')->firstOrFail();
        $this->assertSame('NIKE-SPORTS', $brand->code);

        $this->post(route('masters.brands.store'), [
            'code' => 'NIKE SPORTS',
            'name' => 'Duplicate Brand',
            'is_active' => 1,
        ])->assertSessionHasErrors('code');

        // Another company with the same code is allowed (company scope).
        Brand::create([
            'company_id' => $this->shadowCompanyId(),
            'code' => 'NIKE-SPORTS',
            'name' => 'Other Nike',
            'is_active' => true,
        ]);

        $this->assertSame(2, Brand::query()->where('code', 'NIKE-SPORTS')->count());
    }

    public function test_master_index_is_company_scoped(): void
    {
        $shadow = $this->shadowCompanyId();

        Unit::create([
            'company_id' => $shadow,
            'code' => 'FOREIGN',
            'name' => 'Foreign Unit',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('masters.units.index'))->assertOk();
        $source = (string) $response->getContent();

        $units = Unit::query()->where('company_id', $this->admin->company_id)->pluck('code');
        $this->assertFalse($units->contains('FOREIGN'));
        $this->assertStringNotContainsString('Foreign Unit', $source);
    }

    public function test_permission_gates_master_mutations(): void
    {
        // units uses masters.manage for both view and mutate (no split).
        $manager = $this->roleWith(['portal.erp.access', 'masters.manage']);
        $managerUser = $this->makeUser();
        $managerUser->roles()->attach($manager->id);

        $this->actingAs($managerUser)
            ->get(route('masters.units.index'))
            ->assertOk();

        // districts split: view = masters.view, mutate = masters.manage.
        $geoViewer = $this->roleWith(['portal.erp.access', 'masters.view']);
        $geoUser = $this->makeUser();
        $geoUser->roles()->attach($geoViewer->id);

        $this->actingAs($geoUser)
            ->get(route('masters.districts.index'))
            ->assertOk();

        $this->actingAs($geoUser)
            ->post(route('masters.districts.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();

        // masters.view alone must not open unit mutations (needs masters.manage).
        $this->actingAs($geoUser)
            ->post(route('masters.units.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();
    }

    public function test_structural_bd_seeds_are_truthful(): void
    {
        $admin = $this->admin;

        $this->assertSame(64, District::query()->count());
        $this->assertGreaterThan(64, Upazila::query()->count());
        $this->assertSame(
            64,
            Upazila::query()
                ->whereIn('district_id', District::query()->pluck('id'))
                ->distinct()
                ->count('district_id'),
        );

        $codes = Courier::query()->where('company_id', $admin->company_id)->pluck('code');
        foreach (['PATHAO', 'REDX', 'STEADFAST', 'PAPERFLY', 'ECOURIER', 'SUNDARBAN', 'SAPARIBAHAN'] as $code) {
            $this->assertTrue($codes->contains($code), "Missing courier {$code}");
        }

        $this->assertTrue(
            Holiday::query()->where('company_id', $admin->company_id)->count() >= 7,
        );

        $methods = PaymentMethod::query()
            ->where('company_id', $admin->company_id)
            ->pluck('code');
        foreach (['CASH', 'BKASH', 'NAGAD', 'ROCKET', 'UPAY', 'CARD', 'CHEQUE', 'BEFTN', 'TRANSFER'] as $code) {
            $this->assertTrue($methods->contains($code), "Missing payment method {$code}");
        }

        $banks = Bank::query()->where('company_id', $admin->company_id);
        $this->assertTrue($banks->count() >= 10);
        $this->assertTrue((clone $banks)->whereNotNull('routing_number')->exists());

        $sms = SmsProvider::query()->where('company_id', $admin->company_id)->get();
        $this->assertTrue($sms->count() >= 3);
        foreach ($sms as $row) {
            $this->assertSame('not_configured', $row->config_status, 'SMS registry must stay not_configured until real creds.');
        }
    }

    public function test_tax_service_resolves_effective_dated_rates_without_rewriting_history(): void
    {
        $admin = $this->admin;
        $service = app(TaxService::class);

        TaxRate::create([
            'company_id' => $admin->company_id,
            'code' => 'VAT',
            'name' => 'VAT 15',
            'tax_type' => 'vat',
            'rate' => 15,
            'effective_from' => '2025-01-01',
            'effective_to' => '2025-12-31',
            'is_active' => true,
        ]);

        TaxRate::create([
            'company_id' => $admin->company_id,
            'code' => 'VAT-2026',
            'name' => 'VAT 15 (2026)',
            'tax_type' => 'vat',
            'rate' => 15,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        $historical = $service->rateFor('VAT', '2025-06-15');
        $this->assertNotNull($historical);
        $this->assertSame('2025-01-01', $historical->effective_from->format('Y-m-d'));

        // VAT expired end of 2025 → not effective in 2026 under that code.
        $this->assertNull($service->rateFor('VAT', '2026-06-15'));

        // Rate change is a NEW effective-dated row — the old row is untouched.
        $old = TaxRate::query()->where('code', 'VAT')->firstOrFail();
        $this->assertSame('15.000000', $old->rate);

        $current = $service->effectiveRates('2026-06-15', 'vat')->firstWhere('code', 'VAT-2026');
        $this->assertNotNull($current);
        $this->assertSame(15.0, (float) $current->rate);
        $this->assertEqualsWithDelta(150.0, $service->apply(1000, 'VAT-2026', '2026-06-15'), 0.001);
    }

    public function test_numbering_allocates_unique_sequential_numbers(): void
    {
        $admin = $this->admin;
        $this->bindTenantContext($admin);

        $type = DocumentType::query()->where('code', 'invoice')->firstOrFail();
        $rule = NumberingRule::query()
            ->where('company_id', $admin->company_id)
            ->where('document_type_id', $type->id)
            ->where('branch_id', 0)
            ->firstOrFail();

        $service = app(NumberingService::class);
        $numbers = [];

        for ($i = 0; $i < 25; $i++) {
            $numbers[] = $service->allocate($type->id, 0, 'HO');
        }

        $this->assertSame($numbers, array_values(array_unique($numbers)), 'Numbering must never emit duplicates.');
        $this->assertCount(25, array_unique($numbers));

        $sequence = NumberingSequence::query()
            ->where('numbering_rule_id', $rule->id)
            ->where('period_key', '')
            ->firstOrFail();

        $this->assertSame(25, (int) $sequence->last_value);

        $this->assertMatchesRegularExpression('/^INV\/\d{4}\/\d{5}$/', $numbers[0]);
        $this->assertMatchesRegularExpression('/^INV\/\d{4}\/\d{5}$/', $numbers[24]);
    }

    public function test_numbering_is_unique_under_rapid_sequential_allocation(): void
    {
        // Each allocate() opens its own transaction and lockForUpdate's the
        // sequence row — duplicates would appear if the lock were missing.
        $this->bindTenantContext($this->admin);

        $type = DocumentType::query()->where('code', 'sales_order')->firstOrFail();
        $service = app(NumberingService::class);

        $numbers = [];
        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $service->allocate($type->id, 0, 'HO');
        }

        $this->assertCount(50, array_unique($numbers));
    }

    public function test_party_and_price_list_master_crud(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('masters.customers.store'), [
            'code' => 'C-001',
            'name' => 'Walk-in Retailer',
            'phone' => '01700000000',
            'credit_limit' => 50000,
            'is_active' => 1,
        ])->assertRedirect(route('masters.customers.index'));

        $customer = Customer::query()->where('code', 'C-001')->firstOrFail();
        $this->assertSame($this->admin->company_id, $customer->company_id);

        $this->post(route('masters.suppliers.store'), [
            'code' => 'S-001',
            'name' => 'Local Vendor',
            'phone' => '01800000000',
            'is_active' => 1,
        ])->assertRedirect(route('masters.suppliers.index'));

        $this->post(route('pricing.price-lists.store'), [
            'code' => 'DEFAULT',
            'name' => 'Standard Retail',
            'valid_from' => now()->toDateString(),
            'is_default' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('pricing.price-lists.index'));

        $list = PriceList::query()->where('code', 'DEFAULT')->firstOrFail();
        $this->assertTrue((bool) $list->is_default);

        $this->get(route('masters.customers.index'))->assertOk();
        $this->get(route('masters.suppliers.index'))->assertOk();
        $this->get(route('pricing.price-lists.index'))->assertOk();
        $this->get(route('masters.banks.index'))->assertOk();
        $this->get(route('masters.districts.index'))->assertOk();
        $this->get(route('masters.tax-rates.index'))->assertOk();
        $this->get(route('masters.units.index'))->assertOk();
    }

    public function test_permission_gates_party_masters(): void
    {
        $viewer = $this->roleWith(['portal.erp.access', 'masters.view']);
        $user = $this->makeUser();
        $user->roles()->attach($viewer->id);

        $this->actingAs($user)
            ->post(route('masters.customers.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('masters.suppliers.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('pricing.price-lists.store'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();
    }

    public function test_geo_districts_are_readable_with_view_permission(): void
    {
        $geo = $this->roleWith(['portal.erp.access', 'masters.view']);
        $user = $this->makeUser();
        $user->roles()->attach($geo->id);

        $this->actingAs($user)->get(route('masters.districts.index'))->assertOk();
    }

    public function test_masters_root_redirects_to_units(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/masters')
            ->assertRedirect('/app/masters/units');
    }
}
