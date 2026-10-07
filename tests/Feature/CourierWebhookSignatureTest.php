<?php

namespace Tests\Feature;

use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-90b Courier tracking webhook at POST /webhooks/couriers/{id}: the
 * HMAC-SHA256 signature over the RAW body (X-ERP-Signature) is the only
 * capability — missing/invalid signatures and couriers without a
 * configured signing secret are rejected with 401 before any parsing,
 * unknown couriers/shipments 404, malformed payloads 422, and a
 * redelivered external event id collapses to `duplicate` with a single
 * row. Webhook ingest is unauthenticated by design: no session identity
 * is ever assumed.
 */
class CourierWebhookSignatureTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Customer $customer;

    protected Courier $courier;

    protected const SECRET = 'whsec_test_secret_123';

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
            'code' => 'WHK-1',
            'sku' => 'WHK-SKU-1',
            'name' => 'Webhook Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'whk-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'WHKC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Webhook Customer',
            'phone' => '01755555555',
            'address_line1' => 'House 7, Road 2, Mirpur',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'WHKX',
            'name' => 'Webhook Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
            'webhook_secret' => self::SECRET,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__webhook', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeDispatchedShipment(): Shipment
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2],
            ],
        ])->assertSessionHasNoErrors();

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertSessionHasNoErrors();

        return $shipment->fresh();
    }

    /** Push a raw JSON body with an HMAC signature (default: correct). */
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

    public function test_valid_signature_records_the_event_and_advances_status(): void
    {
        $shipment = $this->makeDispatchedShipment();

        $response = $this->pushWebhook($this->courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-1001',
            'event_code' => 'out_for_delivery',
            'description' => 'Left the sorting hub',
            'location' => 'Mirpur hub',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
        ]);

        $response->assertOk()->assertJson(['outcome' => 'created']);

        $event = TrackingEvent::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(TrackingEvent::SOURCE_WEBHOOK, $event->source);
        $this->assertNull($event->actor_id);
        $this->assertSame('EVT-1001', $event->external_event_id);
        $this->assertSame('wh:'.$this->courier->id.':EVT-1001', $event->idempotency_key);
        $this->assertSame('Left the sorting hub', $event->description);

        $this->assertSame(Shipment::STATUS_OUT_FOR_DELIVERY, $shipment->fresh()->status);
        $this->assertSame('out_for_delivery', SalesOrder::query()
            ->whereKey($shipment->sales_order_id)
            ->value('status'));
    }

    public function test_missing_or_invalid_signatures_are_rejected_before_parsing(): void
    {
        $shipment = $this->makeDispatchedShipment();
        $payload = [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-2001',
            'event_code' => 'delivered',
        ];

        $this->pushWebhook($this->courier, $payload, null)->assertUnauthorized();
        $this->pushWebhook($this->courier, $payload, 'sha256=deadbeef')->assertUnauthorized();
        $this->pushWebhook($this->courier, $payload, 'wrong-prefix='.hash_hmac(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR),
            self::SECRET,
        ))->assertUnauthorized();

        $this->assertSame(0, TrackingEvent::query()->count());
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }

    public function test_courier_without_a_signing_secret_is_never_verifiable(): void
    {
        $secretless = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'NOSEC',
            'name' => 'Secretless Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ]);
        $shipment = $this->makeDispatchedShipment();

        $response = $this->pushWebhook($secretless, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-3001',
            'event_code' => 'delivered',
        ]);

        $response->assertUnauthorized();
        $this->assertStringContainsString(
            'signing secret is not configured',
            (string) $response->json('message'),
        );
        $this->assertSame(0, TrackingEvent::query()->count());
    }

    public function test_unknown_courier_or_foreign_shipment_is_not_found(): void
    {
        $shipment = $this->makeDispatchedShipment();

        $this->pushWebhook($this->courier, [
            'shipment_id' => 99999999,
            'external_event_id' => 'EVT-4001',
            'event_code' => 'delivered',
        ])->assertNotFound();

        // Same shipment id, different courier's webhook — never ingestable.
        $otherCourier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'OTHR',
            'name' => 'Other Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
            'webhook_secret' => self::SECRET,
        ]);

        $this->pushWebhook($otherCourier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-4002',
            'event_code' => 'delivered',
        ])->assertNotFound();

        $this->pushWebhook($this->courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => '',
            'event_code' => 'delivered',
        ])->assertUnprocessable();

        $this->assertSame(0, TrackingEvent::query()->count());
    }

    public function test_redelivered_external_event_collapses_to_duplicate(): void
    {
        $shipment = $this->makeDispatchedShipment();
        $payload = [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-5001',
            'event_code' => 'delivered',
        ];

        $first = $this->pushWebhook($this->courier, $payload);
        $first->assertOk()->assertJson(['outcome' => 'created']);

        $second = $this->pushWebhook($this->courier, $payload);
        $second->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->assertSame(
            $first->json('tracking_event_id'),
            $second->json('tracking_event_id'),
        );

        $this->assertSame(1, TrackingEvent::query()->where('shipment_id', $shipment->id)->count());
    }

    public function test_invalid_event_code_fails_validation_without_side_effects(): void
    {
        $shipment = $this->makeDispatchedShipment();

        $this->pushWebhook($this->courier, [
            'shipment_id' => $shipment->id,
            'external_event_id' => 'EVT-6001',
            'event_code' => 'teleported',
        ])->assertUnprocessable();

        $this->assertSame(0, TrackingEvent::query()->count());
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }
}
