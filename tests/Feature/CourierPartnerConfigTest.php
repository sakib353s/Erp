<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Masters\Courier;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-91 Courier partners at GET|POST /app/settings/couriers behind
 * sales.delivery.configure: company-scoped create/update with unique
 * codes, configuration_status + integration_enabled as the single
 * source of truth CourierPort reads, and a write-only webhook signing
 * secret (encrypted at rest, blank keeps the stored value, never
 * rendered or flashed back). The screen reports what the system knows —
 * no fake connection test ever exists.
 */
class CourierPartnerConfigTest extends TestCase
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
    }

    public function test_create_courier_with_truthful_defaults_and_audit(): void
    {
        $response = $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'newx',
            'name' => 'New Courier',
            'configuration_status' => 'not_configured',
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('couriers.index'));
        $response->assertSessionHas('status');

        $courier = Courier::query()->where('code', 'NEWX')->firstOrFail();
        $this->assertSame((int) $this->admin->company_id, (int) $courier->company_id);
        $this->assertSame('not_configured', $courier->configuration_status);
        $this->assertFalse($courier->integration_enabled);
        $this->assertTrue($courier->is_active);
        $this->assertNull($courier->webhook_secret);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'record.create')
            ->where('entity_type', Courier::class)
            ->where('entity_id', $courier->id)
            ->count());
    }

    public function test_update_flips_configuration_and_manages_the_secret(): void
    {
        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'SECR',
            'name' => 'Secret Courier',
            'configuration_status' => 'not_configured',
            'webhook_secret' => 'secret-alpha-1',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $courier = Courier::query()->where('code', 'SECR')->firstOrFail();

        $raw = DB::table('couriers')->where('id', $courier->id)->value('webhook_secret');
        $this->assertNotSame('secret-alpha-1', (string) $raw);
        $this->assertStringNotContainsString('secret-alpha-1', (string) $raw);

        // Blank keeps the stored secret; configuration flips to the truth.
        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'id' => $courier->id,
            'code' => 'SECR',
            'name' => 'Secret Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => 1,
            'webhook_secret' => '',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $courier = $courier->fresh();
        $this->assertSame('configured', $courier->configuration_status);
        $this->assertTrue($courier->integration_enabled);
        $this->assertSame('secret-alpha-1', $courier->webhook_secret);

        // A new value replaces it.
        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'id' => $courier->id,
            'code' => 'SECR',
            'name' => 'Secret Courier Renamed',
            'configuration_status' => 'configured',
            'webhook_secret' => 'secret-beta-22',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('secret-beta-22', $courier->fresh()->webhook_secret);
        $this->assertSame('Secret Courier Renamed', $courier->fresh()->name);

        $this->assertSame(2, AuditEvent::query()
            ->where('action', 'record.update')
            ->where('entity_type', Courier::class)
            ->where('entity_id', $courier->id)
            ->count());
    }

    public function test_secret_is_never_rendered_or_flashed_back(): void
    {
        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'HUSH',
            'name' => 'Hush Courier',
            'configuration_status' => 'configured',
            'webhook_secret' => 'never-show-this-value',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $page = $this->actingAs($this->admin)->get(route('couriers.index'));
        $page->assertOk();
        $page->assertSee('Hush Courier');
        $page->assertDontSee('never-show-this-value');

        $status = (string) session('status');
        $this->assertStringNotContainsString('never-show-this-value', $status);
    }

    public function test_duplicate_code_within_the_company_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'DUOC',
            'name' => 'First',
            'configuration_status' => 'not_configured',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'DUOC',
            'name' => 'Second',
            'configuration_status' => 'not_configured',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Courier::query()->where('code', 'DUOC')->count());
    }

    public function test_foreign_courier_cannot_be_edited(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Couriers Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourier = Courier::query()->create([
            'company_id' => $shadowId,
            'code' => 'FOREIGN',
            'name' => 'Foreign Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'id' => $shadowCourier->id,
            'code' => 'FOREIGN',
            'name' => 'Hijacked',
            'configuration_status' => 'not_configured',
        ])->assertNotFound();

        $this->assertSame('Foreign Courier', $shadowCourier->fresh()->name);
        $this->assertSame(0, Courier::query()->where('company_id', $this->admin->company_id)->count());
    }

    public function test_validation_rejects_missing_and_invalid_values(): void
    {
        $this->actingAs($this->admin)->post(route('couriers.store'), [])
            ->assertSessionHasErrors(['code', 'name', 'configuration_status']);

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'bad code!',
            'name' => 'X',
            'configuration_status' => 'not_configured',
        ])->assertSessionHasErrors('code');

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'OKAY',
            'name' => 'X',
            'configuration_status' => 'halfway',
        ])->assertSessionHasErrors('configuration_status');

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'OKAY',
            'name' => 'X',
            'configuration_status' => 'configured',
            'webhook_secret' => 'short7c',
        ])->assertSessionHasErrors('webhook_secret');

        $this->assertSame(0, Courier::query()->count());
    }

    public function test_route_requires_sales_delivery_configure(): void
    {
        $denied = $this->makeUser(['name' => 'Delivery Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('couriers.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('couriers.store'), [
            'code' => 'NOPE',
            'name' => 'Nope',
            'configuration_status' => 'not_configured',
        ])->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Courier Admin']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.configure'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('couriers.index'))->assertOk();

        $this->actingAs($allowed)->post(route('couriers.store'), [
            'code' => 'YEPX',
            'name' => 'Allowed Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('couriers.index'));

        $this->assertSame(1, Courier::query()->where('code', 'YEPX')->count());
    }

    public function test_list_is_company_scoped(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow List Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Courier::query()->create([
            'company_id' => $shadowId,
            'code' => 'OTHERCO',
            'name' => 'Other Company Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('couriers.store'), [
            'code' => 'MINECO',
            'name' => 'My Company Courier',
            'configuration_status' => 'configured',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('couriers.index'))
            ->assertOk()
            ->assertSee('My Company Courier')
            ->assertDontSee('Other Company Courier');
    }
}
