<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Notification\Notification;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-90a Tracking events at GET|POST
 * /app/sales/shipments/{id}/tracking: manual operator observations
 * recorded verbatim, shipment/order statuses moved ONLY forward along
 * validated transitions (never from pending/assigned, never backwards,
 * completed orders never regress), a truthful in-app status-update
 * notification to the order creator, the
 * sales.tracking_event_ingested audit, and the view/create permission
 * split (sales.delivery.view reads, sales.delivery.shipments.create
 * records).
 */
class TrackingEventTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Customer $customer;

    protected Courier $courier;

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
            'code' => 'TRK-1',
            'sku' => 'TRK-SKU-1',
            'name' => 'Tracking Product',
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
            'idempotency_suffix' => 'trk-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'TRKC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Tracking Customer',
            'phone' => '01744444444',
            'address_line1' => 'House 3, Road 11, Banani',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'TRKX',
            'name' => 'Tracking Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__tracking-event', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 3): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    protected function makeShipment(SalesOrder $order): Shipment
    {
        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ])->assertSessionHasNoErrors();

        return Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
    }

    protected function dispatch(Shipment $shipment): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertSessionHasNoErrors();
    }

    public function test_manual_event_advances_statuses_and_notifies_the_creator(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $this->actingAs($this->admin)->post(
            route('sales.shipments.tracking.store', $shipment),
            [
                'event_code' => 'out_for_delivery',
                'description' => 'Rider picked up from Banani hub',
                'location' => 'Banani',
            ],
        )->assertRedirect(route('sales.shipments.tracking', $shipment));

        $event = TrackingEvent::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('out_for_delivery', $event->event_code);
        $this->assertSame(TrackingEvent::SOURCE_MANUAL, $event->source);
        $this->assertSame((int) $this->admin->id, (int) $event->actor_id);
        $this->assertSame((int) $this->courier->id, (int) $event->courier_id);
        $this->assertSame('Rider picked up from Banani hub', $event->description);

        $this->assertSame(Shipment::STATUS_OUT_FOR_DELIVERY, $shipment->fresh()->status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        $audit = AuditEvent::query()
            ->where('action', 'sales.tracking_event_ingested')
            ->where('entity_id', $event->id)
            ->firstOrFail();
        $this->assertTrue($audit->after['shipment_status_changed']);
        $this->assertTrue($audit->after['order_status_changed']);
        $this->assertSame('manual', $audit->after['source']);

        $notification = Notification::query()
            ->where('event_type', 'sales.shipment_tracking')
            ->where('user_id', $this->admin->id)
            ->firstOrFail();
        $this->assertStringContainsString($order->order_no, $notification->title);
        $this->assertStringContainsString('Out for delivery', $notification->title);
    }

    public function test_delivered_walks_forward_and_never_moves_backwards(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'delivered',
            'location' => 'Dhanmondi depot',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'out_for_delivery',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TrackingEvent::query()->where('shipment_id', $shipment->id)->count());
        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_undispatched_shipment_records_events_without_status_changes(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'in_transit',
            'description' => 'Courier scanned the label',
        ])->assertSessionHasNoErrors();

        $event = TrackingEvent::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('in_transit', $event->event_code);

        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status);

        $audit = AuditEvent::query()
            ->where('action', 'sales.tracking_event_ingested')
            ->where('entity_id', $event->id)
            ->firstOrFail();
        $this->assertFalse($audit->after['shipment_status_changed']);
        $this->assertFalse($audit->after['order_status_changed']);
    }

    public function test_completed_order_never_regresses_on_a_delivery_event(): void
    {
        $order = $this->makeConfirmedOrder();
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
        $this->assertSame('completed', $order->fresh()->status);

        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'delivered',
        ])->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_unknown_codes_and_foreign_shipments_are_rejected(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'teleported',
        ])->assertSessionHasErrors('event_code');

        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Tracking Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourier = Courier::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
        $shadowShipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $shadowId,
            'sales_order_id' => DB::table('sales_orders')->insertGetId([
                'company_id' => $shadowId,
                'order_no' => 'SHDW-1',
                'order_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'courier_id' => $shadowCourier->id,
            'status' => 'dispatched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.tracking.store', $shadowShipmentId))
            ->assertNotFound();

        $this->assertSame(0, TrackingEvent::query()->count());
    }

    public function test_tracking_page_renders_timeline_and_gates_the_form(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);

        $this->actingAs($this->admin)->post(route('sales.shipments.tracking.store', $shipment), [
            'event_code' => 'info_received',
            'description' => 'Label created with the courier',
        ])->assertSessionHasNoErrors();

        $viewer = $this->makeUser(['name' => 'Tracking Viewer']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)
            ->get(route('sales.shipments.tracking', $shipment))
            ->assertOk()
            ->assertSee('Label created with the courier')
            ->assertDontSee('Record tracking event');

        $denied = $this->makeUser(['name' => 'No Delivery Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.shipments.tracking', $shipment))
            ->assertForbidden();
    }

    public function test_tracking_routes_respect_view_and_create_permissions(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);

        $viewer = $this->makeUser(['name' => 'Timeline Reader']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)
            ->post(route('sales.shipments.tracking.store', $shipment), ['event_code' => 'in_transit'])
            ->assertForbidden();

        $recorder = $this->makeUser(['name' => 'Timeline Writer']);
        $recorder->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.delivery.shipments.create',
        ])->id);
        app(PermissionCatalog::class)->invalidate($recorder);

        $this->actingAs($recorder)
            ->get(route('sales.shipments.tracking', $shipment))
            ->assertForbidden();

        $this->actingAs($recorder)
            ->post(route('sales.shipments.tracking.store', $shipment), ['event_code' => 'in_transit'])
            ->assertRedirect(route('sales.shipments.tracking', $shipment));

        $this->assertSame(1, TrackingEvent::query()->count());
    }
}
