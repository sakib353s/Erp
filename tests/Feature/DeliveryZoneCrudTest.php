<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\ZoneCharge;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-88a Delivery zone CRUD at GET|POST /app/sales/delivery/zones
 * behind sales.delivery.zones: company-scoped zones with district
 * coverage, weight-slab charge rows, audits, and permission gates.
 */
class DeliveryZoneCrudTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
    }

    private function shadowCompanyId(): int
    {
        return DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Zones Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_store_creates_zone_with_districts_and_audit(): void
    {
        $districtId = DB::table('districts')->orderBy('id')->value('id');

        $response = $this->actingAs($this->admin)->post(route('sales.delivery.zones.store'), [
            'code' => 'DHK-1',
            'name' => 'Dhaka metro',
            'description' => 'Inside Dhaka city',
            'base_charge' => 40,
            'per_kg_charge' => 10,
            'is_active' => 1,
            'district_ids' => [$districtId],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $zone = DeliveryZone::query()->where('code', 'DHK-1')->firstOrFail();
        $this->assertSame((int) $this->admin->company_id, (int) $zone->company_id);
        $this->assertEqualsWithDelta(40.0, (float) $zone->base_charge, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $zone->per_kg_charge, 0.0001);
        $this->assertTrue($zone->is_active);
        $this->assertSame([(int) $districtId], $zone->districts->pluck('id')->map(fn ($id) => (int) $id)->all());

        $audit = AuditEvent::query()
            ->where('action', 'record.create')
            ->where('entity_type', DeliveryZone::class)
            ->where('entity_id', $zone->id)
            ->firstOrFail();
        $this->assertSame((int) $this->admin->company_id, (int) $audit->company_id);
    }

    public function test_duplicate_code_and_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.store'), ['code' => 'DUP', 'name' => 'First'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.store'), ['code' => 'DUP', 'name' => 'Second'])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.store'), [
                'code' => 'NEG',
                'name' => 'Negative',
                'base_charge' => -5,
            ])
            ->assertSessionHasErrors('base_charge');

        $this->assertSame(1, DeliveryZone::query()->where('company_id', $this->admin->company_id)->count());
    }

    public function test_index_lists_only_own_company_zones(): void
    {
        $shadowId = $this->shadowCompanyId();

        DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'MINE',
            'name' => 'My own zone',
        ]);
        DeliveryZone::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow zone',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.zones.index'))
            ->assertOk()
            ->assertSee('MINE')
            ->assertSee('My own zone')
            ->assertDontSee('SHDW')
            ->assertDontSee('Shadow zone');
    }

    public function test_charge_rows_crud_and_validation(): void
    {
        $zone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CHG',
            'name' => 'Charge zone',
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.charges.store', $zone), [
                'code' => 'SLAB-5',
                'weight_from' => 0,
                'weight_to' => 5,
                'amount' => 30,
            ])
            ->assertSessionHasNoErrors();

        $charge = ZoneCharge::query()->where('code', 'SLAB-5')->firstOrFail();
        $this->assertSame((int) $zone->id, (int) $charge->delivery_zone_id);
        $this->assertEqualsWithDelta(30.0, (float) $charge->amount, 0.0001);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.charges.store', $zone), [
                'code' => 'BAD',
                'weight_from' => 5,
                'weight_to' => 1,
                'amount' => 10,
            ])
            ->assertSessionHasErrors('weight_to');

        $this->actingAs($this->admin)
            ->delete(route('sales.delivery.zones.charges.destroy', $charge))
            ->assertSessionHasNoErrors();

        $this->assertNull(ZoneCharge::query()->find($charge->id));
    }

    public function test_foreign_company_zone_and_charge_are_404(): void
    {
        $shadowId = $this->shadowCompanyId();
        $shadowZone = DeliveryZone::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow zone',
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.zones.charges.store', $shadowZone), [
                'code' => 'X',
                'weight_from' => 0,
                'amount' => 1,
            ])
            ->assertNotFound();

        $shadowCharge = ZoneCharge::query()->create([
            'company_id' => $shadowId,
            'delivery_zone_id' => $shadowZone->id,
            'code' => 'SC',
            'weight_from' => 0,
            'amount' => 5,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('sales.delivery.zones.charges.destroy', $shadowCharge))
            ->assertNotFound();

        $this->assertSame(0, ZoneCharge::query()->where('company_id', $this->admin->company_id)->count());
    }

    public function test_zone_routes_respect_sales_delivery_zones(): void
    {
        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.delivery.zones.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('sales.delivery.zones.store'), ['code' => 'NO', 'name' => 'Nope'])
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Zone Manager']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.zones'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.delivery.zones.index'))
            ->assertOk()
            ->assertSee('Add delivery zone');

        $this->actingAs($allowed)
            ->post(route('sales.delivery.zones.store'), ['code' => 'OK', 'name' => 'Allowed zone'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DeliveryZone::query()->where('company_id', $this->admin->company_id)->count());
    }
}
