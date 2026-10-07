<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\TrackShipmentEvent;
use App\Domain\Delivery\Couriers\CourierAdapter;
use App\Domain\Delivery\Couriers\ProviderRegistry;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-92 Courier provider adapters: the seven brand leaves each resolve
 * to a dedicated GET|PUT /app/settings/couriers/{provider} screen and
 * an adapter implementing the four CourierPort capabilities — for every
 * provider the same contract holds (spec: not-configured → truthful
 * error, configured → mapped response):
 *
 *  - assign: not configured → local pending assignment with no ref;
 *    configured → {CODE}-{ORDER_NO} consignment + tracking URL;
 *  - rates: only ever from the tenant's own delivery zones
 *    (source=delivery_zone), truthful unavailable reasons otherwise;
 *  - track: maps locally recorded events, never invents history;
 *  - webhook: the adapter-declared vocabulary translates to canonical
 *    codes; anything else is an honest 422.
 */
class CourierProviderAdapterTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected const WEIGHT_KG = 2.0;

    protected const BASE_CHARGE = 120.0;

    protected const PER_KG_CHARGE = 15.0;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'PVA-1',
            'sku' => 'PVA-SKU-1',
            'name' => 'Provider Adapter Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'pva-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__provider-adapter', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function adapter(string $slug): CourierAdapter
    {
        $adapter = app(ProviderRegistry::class)->resolve($slug);
        $this->assertNotNull($adapter, "No adapter for slug {$slug}");

        return $adapter;
    }

    protected function makeCourierRow(string $code, array $overrides = []): Courier
    {
        // ReferenceDataSeeder already ships one truthful row per brand —
        // upsert so tests flip that single row instead of fighting the
        // unique (company_id, code) constraint.
        return Courier::query()->updateOrCreate(
            ['company_id' => $this->admin->company_id, 'code' => $code],
            array_merge([
                'name' => $code.' row',
                'configuration_status' => 'not_configured',
                'integration_enabled' => false,
                'is_active' => true,
            ], $overrides),
        );
    }

    protected function makeOrder(int $qty = 3): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    protected function makeShipment(Courier $courier, ?SalesOrder $order = null): Shipment
    {
        $order ??= $this->makeOrder();

        return Shipment::query()->create([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $order->id,
            'courier_id' => $courier->id,
            'status' => Shipment::STATUS_PENDING_DISPATCH,
            'external_ref' => strtoupper($courier->code).'-REF1',
        ]);
    }

    /**
     * Active zone (base + per-kg) attached to a district, plus a second
     * district with no zone at all.
     *
     * @return array{0: District, 1: District}
     */
    protected function makeZonedAndUnzonedDistricts(): array
    {
        $zoned = District::query()->orderBy('id')->firstOrFail();

        $zone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PAD'.substr(md5(uniqid('', true)), 0, 4),
            'name' => 'Provider adapter zone',
            'base_charge' => self::BASE_CHARGE,
            'per_kg_charge' => self::PER_KG_CHARGE,
            'is_active' => true,
        ]);
        $zone->districts()->attach($zoned->id);

        $used = DB::table('delivery_zone_district')->pluck('district_id')->all();
        $unzoned = District::query()
            ->whereNotIn('id', $used)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($unzoned, 'Need at least one district outside the zone');

        return [$zoned, $unzoned];
    }

    /** Full per-provider contract: truthful errors, then mapped responses. */
    protected function runProvider(string $slug): void
    {
        $adapter = $this->adapter($slug);
        $this->assertSame($slug, $adapter->slug());
        $code = $adapter->code();
        $this->assertSame($code, strtoupper($code));
        $this->assertNotSame('', $adapter->label());

        $order = $this->makeOrder();

        // --- not configured → truthful error on every capability ------
        $courier = $this->makeCourierRow($code);

        $assign = $adapter->assign($courier, $order);
        $this->assertFalse($assign['dispatched']);
        $this->assertNull($assign['external_ref']);
        $this->assertSame($slug, $assign['provider']);
        $this->assertNull($assign['tracking_url']);

        $rate = $adapter->quoteRate($courier, [
            'district_id' => 1,
            'weight_kg' => self::WEIGHT_KG,
        ]);
        $this->assertFalse($rate['available']);
        $this->assertStringContainsString('not configured', (string) $rate['reason']);
        $this->assertStringContainsString($adapter->label(), (string) $rate['reason']);

        $track = $adapter->track($courier, $this->makeShipment($courier, $order));
        $this->assertFalse($track['available']);
        $this->assertStringContainsString('not configured', (string) $track['reason']);
        $this->assertSame([], $track['events']);

        // --- configured → mapped responses from local truth -----------
        [$zonedDistrict] = $this->makeZonedAndUnzonedDistricts();

        $courier->fill([
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'tracking_url_pattern' => 'https://track.example/{external_ref}',
        ])->save();

        $assign2 = $adapter->assign($courier, $order);
        $expectedRef = $code.'-'.strtoupper($order->order_no);
        $this->assertTrue($assign2['dispatched']);
        $this->assertSame($expectedRef, $assign2['external_ref']);
        $this->assertSame($slug, $assign2['provider']);
        $this->assertSame('https://track.example/'.$expectedRef, $assign2['tracking_url']);

        $rate2 = $adapter->quoteRate($courier, [
            'district_id' => $zonedDistrict->id,
            'weight_kg' => self::WEIGHT_KG,
        ]);
        $expectedAmount = self::BASE_CHARGE + self::PER_KG_CHARGE * self::WEIGHT_KG;
        $this->assertTrue($rate2['available']);
        $this->assertSame($expectedAmount, (float) $rate2['amount']);
        $this->assertSame('BDT', $rate2['currency']);
        $this->assertSame('delivery_zone', $rate2['source']);

        $configuredShipment = $this->makeShipment($courier);
        TrackingEvent::query()->create([
            'company_id' => $this->admin->company_id,
            'shipment_id' => $configuredShipment->id,
            'courier_id' => $courier->id,
            'event_code' => 'in_transit',
            'description' => 'On the way',
            'location' => 'Dhaka',
            'occurred_at' => now(),
            'source' => TrackingEvent::SOURCE_MANUAL,
        ]);

        $track2 = $adapter->track($courier, $configuredShipment);
        $this->assertTrue($track2['available']);
        $this->assertSame('local_tracking_events', $track2['source']);
        $this->assertSame($configuredShipment->external_ref, $track2['consignment_ref']);
        $this->assertSame(
            'https://track.example/'.$configuredShipment->external_ref,
            $track2['tracking_url'],
        );
        $this->assertCount(1, $track2['events']);
        $this->assertSame('in_transit', $track2['events'][0]['code']);
        $this->assertSame(
            TrackShipmentEvent::CODES['in_transit'],
            $track2['events'][0]['label'],
        );

        // --- webhook vocabulary: declared in, canonical out ------------
        $map = $adapter->eventMap();
        $this->assertNotEmpty($map);
        foreach ($map as $providerEvent => $canonical) {
            $this->assertContains($canonical, array_keys(TrackShipmentEvent::CODES));
            $this->assertSame($canonical, $adapter->mapWebhookEvent($providerEvent));
            $this->assertSame($canonical, $adapter->mapWebhookEvent(strtolower($providerEvent)));
        }
        $this->assertSame('delivered', $adapter->mapWebhookEvent('delivered'));
        $this->assertNull($adapter->mapWebhookEvent('TELEPORTED'));
    }

    public function test_pathao_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('pathao');
    }

    public function test_redx_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('redx');
    }

    public function test_steadfast_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('steadfast');
    }

    public function test_paperfly_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('paperfly');
    }

    public function test_e_courier_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('e-courier');
    }

    public function test_sundarban_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('sundarban');
    }

    public function test_sa_paribahan_adapter_is_truthful_and_maps_when_configured(): void
    {
        $this->runProvider('sa-paribahan');
    }

    public function test_provider_webhook_translates_vocabulary_and_rejects_unknown_codes(): void
    {
        $courier = $this->makeCourierRow('PATHAO', [
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'webhook_secret' => 'provider-hook-secret-1',
        ]);
        $shipment = $this->makeShipment($courier);

        // Provider vocabulary is translated to the canonical code.
        $this->pushWebhook($courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'PV-1',
            'event_code' => 'PICKED_UP',
        ])->assertOk()->assertJson(['outcome' => 'created']);

        $event = TrackingEvent::query()->where('external_event_id', 'PV-1')->firstOrFail();
        $this->assertSame('picked_up', $event->event_code);
        $this->assertSame(TrackingEvent::SOURCE_WEBHOOK, $event->source);

        // A code outside both sets is an honest 422, never guessed.
        $this->pushWebhook($courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'PV-2',
            'event_code' => 'TELEPORTED',
        ])->assertUnprocessable()
            ->assertJson([
                'message' => 'Pathao Courier webhook event not recognized: TELEPORTED',
            ]);

        $this->assertSame(1, TrackingEvent::query()->count());

        // Canonical codes pass through unchanged.
        $this->pushWebhook($courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'PV-3',
            'event_code' => 'in_transit',
        ])->assertOk()->assertJson(['outcome' => 'created']);

        $this->assertSame('in_transit', TrackingEvent::query()
            ->where('external_event_id', 'PV-3')
            ->firstOrFail()->event_code);
    }

    public function test_provider_screen_renders_truth_and_upserts_its_configuration(): void
    {
        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', 'nosuch'))
            ->assertNotFound();

        // ReferenceDataSeeder ships the brand row with truthful defaults.
        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', 'pathao'))
            ->assertOk()
            ->assertSee('Pathao Courier')
            ->assertSee('not_configured')
            ->assertSee('BOOKING')
            ->assertSee('delivery_zone');

        // Without any configuration row the screen says so — then PUT
        // creates it with the adapter label.
        Courier::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'PATHAO')
            ->delete();

        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', 'pathao'))
            ->assertOk()
            ->assertSee('No configuration row yet');

        $this->actingAs($this->admin)
            ->put(route('couriers.provider.update', 'pathao'), [
                'configuration_status' => 'configured',
                'integration_enabled' => 1,
                'tracking_url_pattern' => 'https://pathao.example/{external_ref}',
                'webhook_secret' => 'screen-hook-99',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $courier = Courier::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'PATHAO')
            ->firstOrFail();
        $this->assertSame('Pathao Courier', $courier->name);
        $this->assertSame('configured', $courier->configuration_status);
        $this->assertTrue($courier->integration_enabled);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'record.create')
            ->where('entity_type', Courier::class)
            ->where('entity_id', $courier->id)
            ->count());

        $raw = DB::table('couriers')->where('id', $courier->id)->value('webhook_secret');
        $this->assertStringNotContainsString('screen-hook-99', (string) $raw);

        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', 'pathao'))
            ->assertOk()
            ->assertSee('configured')
            ->assertDontSee('screen-hook-99');

        // Second save updates the same row instead of duplicating it.
        $this->actingAs($this->admin)
            ->put(route('couriers.provider.update', 'pathao'), [
                'configuration_status' => 'not_configured',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $courier = $courier->fresh();
        $this->assertSame('not_configured', $courier->configuration_status);
        $this->assertFalse($courier->integration_enabled);
        $this->assertSame(
            1,
            Courier::query()->where('code', 'PATHAO')->count(),
        );
        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'record.update')
            ->where('entity_type', Courier::class)
            ->where('entity_id', $courier->id)
            ->count());
    }

    public function test_rate_probe_is_honest_over_http(): void
    {
        [$zonedDistrict, $unzonedDistrict] = $this->makeZonedAndUnzonedDistricts();

        // No configuration row yet → truthful not-configured reason.
        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', [
                'provider' => 'redx',
                'district_id' => $zonedDistrict->id,
                'weight_kg' => self::WEIGHT_KG,
            ]))
            ->assertOk()
            ->assertSee('no rate can be quoted');

        // Configured → amount from the tenant's own zones, source labeled.
        $this->actingAs($this->admin)
            ->put(route('couriers.provider.update', 'redx'), [
                'configuration_status' => 'configured',
                'integration_enabled' => 1,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', [
                'provider' => 'redx',
                'district_id' => $zonedDistrict->id,
                'weight_kg' => self::WEIGHT_KG,
            ]))
            ->assertOk()
            ->assertSee('BDT 150.00')
            ->assertSee('delivery_zone');

        // Configured but destination has no zone → truthful unavailable.
        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', [
                'provider' => 'redx',
                'district_id' => $unzonedDistrict->id,
                'weight_kg' => self::WEIGHT_KG,
            ]))
            ->assertOk()
            ->assertSee('No active delivery zone covers this destination.');

        // Unknown district → honest unknown-district reason.
        $this->actingAs($this->admin)
            ->get(route('couriers.provider.show', [
                'provider' => 'redx',
                'district_id' => 999999,
                'weight_kg' => 1,
            ]))
            ->assertOk()
            ->assertSee('Unknown district.');
    }

    public function test_provider_screen_is_gated_by_sales_delivery_configure(): void
    {
        $denied = $this->makeUser(['name' => 'No Provider Rights']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('couriers.provider.show', 'paperfly'))
            ->assertForbidden();
        $this->actingAs($denied)
            ->put(route('couriers.provider.update', 'paperfly'), [
                'configuration_status' => 'configured',
            ])
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Provider Admin']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.configure'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('couriers.provider.show', 'paperfly'))
            ->assertOk();
    }

    /** @return array{shipment_id: int, external_event_id: string, event_code: string} */
    protected function pushWebhook(Courier $courier, array $payload, ?string $signature = 'valid'): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($signature === 'valid') {
            $signature = 'sha256='.hash_hmac('sha256', $raw, (string) $courier->webhook_secret);
        }

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_ERP_SIGNATURE'] = $signature;
        }

        return $this->call(
            'POST',
            '/webhooks/couriers/'.$courier->id,
            [],
            [],
            [],
            $server,
            $raw,
        );
    }
}
