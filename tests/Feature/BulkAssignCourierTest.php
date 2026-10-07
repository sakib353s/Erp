<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
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
 * 02-06 Bulk Assign Courier: per-order isolation with partial failure
 * reporting (cancelled/missing orders fail, the rest land), shipment +
 * rider rows written per order, dispatch reference only through
 * CourierPort, bulk audit row, courier/company scoping of the payload,
 * and the sales.delivery.assign permission gate on route and screen.
 */
class BulkAssignCourierTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

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
            'code' => 'BKC-1',
            'sku' => 'BKC-SKU-1',
            'name' => 'Bulk Assign Product',
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
            'idempotency_suffix' => 'bka-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-assign', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeOrder(int $qty = 5): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    protected function makeCourier(array $overrides = []): Courier
    {
        return Courier::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'CR-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Probe Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ], $overrides));
    }

    public function test_bulk_assign_creates_shipments_rider_and_dispatch_ref(): void
    {
        $courier = $this->makeCourier(['code' => 'PKDX']);
        $first = $this->makeOrder(3);
        $second = $this->makeOrder(4);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$first->id, $second->id],
                'courier_id' => $courier->id,
                'rider_name' => 'Rahim Uddin',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('2 assigned', $status);
        $this->assertStringContainsString('0 failed', $status);

        $shipments = Shipment::query()->orderBy('id')->get();
        $this->assertCount(2, $shipments);

        foreach ($shipments as $shipment) {
            $this->assertSame(Shipment::STATUS_ASSIGNED, $shipment->status);
            $this->assertNotNull($shipment->external_ref);
            $this->assertNotNull($shipment->dispatched_at);
            $this->assertSame($courier->id, $shipment->courier_id);
            $this->assertSame($this->admin->id, $shipment->assigned_by);
        }

        $this->assertSame(
            'PKDX-'.strtoupper($first->order_no),
            $shipments[0]->external_ref,
        );

        $this->assertSame(2, RiderAssignment::query()->count());
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.order_bulk_assign_courier')->count(),
        );
    }

    public function test_bulk_assign_reports_partial_failures(): void
    {
        $courier = $this->makeCourier();
        $good = $this->makeOrder(3);
        $cancelled = $this->makeOrder(3);
        $cancelled->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$good->id, $cancelled->id, 99999999],
                'courier_id' => $courier->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 assigned', $status);
        $this->assertStringContainsString('2 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('Order is cancelled', $messages);
        $this->assertStringContainsString('Order not found', $messages);

        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame($good->id, Shipment::query()->firstOrFail()->sales_order_id);
    }

    public function test_bulk_assign_requires_and_company_scopes_the_courier(): void
    {
        $order = $this->makeOrder(3);

        $this->actingAs($this->admin)
            ->from(route('sales.orders.index'))
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
            ])
            ->assertSessionHasErrors('courier_id');

        $foreign = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow courier co',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignCourier = DB::table('couriers')->insertGetId([
            'company_id' => $foreign,
            'code' => 'SHD-CR',
            'name' => 'Shadow courier',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->from(route('sales.orders.index'))
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $foreignCourier,
            ])
            ->assertSessionHasErrors('courier_id');

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_bulk_assign_rejects_inactive_couriers_per_order(): void
    {
        $courier = $this->makeCourier(['is_active' => false]);
        $order = $this->makeOrder(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $courier->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_assign_route_requires_the_sales_delivery_assign_permission(): void
    {
        $courier = $this->makeCourier();
        $order = $this->makeOrder(3);

        $denied = $this->makeUser(['name' => 'Order Viewer Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $courier->id,
            ])
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Dispatch Clerk']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.assign'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $courier->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_orders_screen_offers_the_courier_select_only_with_permission(): void
    {
        $this->makeCourier(['code' => 'STEAD', 'name' => 'Steadfast']);

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Assign courier')
            ->assertSee('STEAD — Steadfast');

        $viewer = $this->makeUser(['name' => 'Read-only']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Assign courier');
    }

    public function test_assignment_never_touches_foreign_orders(): void
    {
        $courier = $this->makeCourier();
        $order = $this->makeOrder(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id, 424242],
                'courier_id' => $courier->id,
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            Shipment::query()->where('sales_order_id', $order->id)->count(),
        );
        $this->assertSame(1, Shipment::query()->count());
        $this->assertSame(
            0,
            Shipment::query()->where('sales_order_id', 424242)->count(),
        );
    }
}
