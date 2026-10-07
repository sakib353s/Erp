<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\ShipmentLine;
use App\Domain\Foundation\Services\PermissionCatalog;
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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-89a CreateShipment at GET|POST /app/sales/shipments: courier
 * handoff rows with explicit product/qty lines. CourierPort decides
 * assigned+external_ref (configured) vs pending_dispatch (truthful
 * local row) — dispatched_at only moves at DispatchShipment. Guards:
 * cancelled/refunded/returned refused, product must be on the order,
 * qty within the remaining ordered quantity. GET needs
 * sales.delivery.shipments; mutations sales.delivery.shipments.create.
 */
class CreateShipmentTest extends TestCase
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
            'code' => 'SHP-1',
            'sku' => 'SHP-SKU-1',
            'name' => 'Shipment Product',
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
            'idempotency_suffix' => 'shp-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SHPC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Shipment Customer',
            'phone' => '01711111111',
            'address_line1' => 'House 1, Road 2, Dhanmondi',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SHPX',
            'name' => 'Shipment Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__shipment', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 5, array $orderOverrides = []): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle(array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $orderOverrides), $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    public function test_create_shipment_stays_pending_dispatch_until_dispatched(): void
    {
        $order = $this->makeConfirmedOrder(5);

        $response = $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ]);

        $response->assertRedirect(route('sales.shipments.index'));
        $response->assertSessionHas('status');

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->status);
        $this->assertNull($shipment->external_ref);
        $this->assertNull($shipment->dispatched_at);

        $line = ShipmentLine::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame((int) $this->product->id, (int) $line->product_id);
        $this->assertEqualsWithDelta(3.0, (float) $line->qty, 0.0001);

        $this->assertSame(1, AuditEvent::query()
            ->where('action', 'sales.shipment_created')
            ->where('entity_id', $shipment->id)
            ->count());

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_configured_courier_is_assigned_with_external_ref(): void
    {
        $courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CFGX',
            'name' => 'Configured Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ]);
        $order = $this->makeConfirmedOrder(5);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5],
            ],
        ])->assertRedirect();

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_ASSIGNED, $shipment->status);
        $this->assertSame('CFGX-'.$order->order_no, $shipment->external_ref);
        $this->assertNull($shipment->dispatched_at);
    }

    public function test_product_must_be_on_the_order(): void
    {
        $other = app(CreateProduct::class)->handle([
            'code' => 'SHP-2',
            'sku' => 'SHP-SKU-2',
            'name' => 'Other Product',
            'cost_method' => 'fifo',
            'standard_cost' => 50,
            'is_stocked' => false,
            'is_active' => true,
        ], $this->httpRequest());
        $order = $this->makeConfirmedOrder(5);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $other->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('order');

        $this->assertStringContainsString(
            'not on this order',
            (string) session('errors')->first('order'),
        );
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_quantity_is_capped_by_the_remaining_ordered_quantity(): void
    {
        $order = $this->makeConfirmedOrder(5);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 6],
            ],
        ])->assertSessionHasErrors('order');

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ])->assertSessionHasErrors('order');

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2],
            ],
        ])->assertSessionHasNoErrors();

        $total = ShipmentLine::query()
            ->whereIn('shipment_id', Shipment::query()->where('sales_order_id', $order->id)->select('id'))
            ->sum('qty');
        $this->assertEqualsWithDelta(5.0, (float) $total, 0.0001);
    }

    public function test_cancelled_and_refunded_orders_are_refused(): void
    {
        $order = $this->makeConfirmedOrder(5);
        SalesOrder::query()->whereKey($order->id)->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('order');

        $this->assertStringContainsString(
            'cannot be shipped',
            (string) session('errors')->first('order'),
        );

        SalesOrder::query()->whereKey($order->id)->update(['status' => 'refunded']);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('order');

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_foreign_courier_and_order_are_rejected(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Shipments Ltd',
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
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'order_no' => 'SHADOW-1',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = $this->makeConfirmedOrder(5);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $shadowCourier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('courier_id');

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $shadowOrderId,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors('order_id');

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_shipment_routes_respect_view_and_create_permissions(): void
    {
        $order = $this->makeConfirmedOrder(5);

        $denied = $this->makeUser(['name' => 'Shipment Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.shipments.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertForbidden();

        $viewer = $this->makeUser(['name' => 'Shipment List User']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.shipments'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)->get(route('sales.shipments.index'))->assertOk();

        $this->actingAs($viewer)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertForbidden();

        $creator = $this->makeUser(['name' => 'Shipment Creator']);
        $creator->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.delivery.shipments',
            'sales.delivery.shipments.create',
        ])->id);
        app(PermissionCatalog::class)->invalidate($creator);

        $this->actingAs($creator)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->assertRedirect(route('sales.shipments.index'));

        $this->assertSame(1, Shipment::query()->count());
    }
}
