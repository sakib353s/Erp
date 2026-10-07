<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Shipment;
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
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-89b Bulk shipment at POST /app/sales/shipments/bulk: one shipment
 * per selected order with that order's remaining lines, per-order
 * isolation (a cancelled or fully-shipped order never blocks the
 * rest), counts in the status line + failure messages in errors, the
 * sales.shipment_bulk_created audit, company-scoped order/courier
 * validation, and sales.delivery.shipments.create on every mutation.
 */
class BulkShipmentTest extends TestCase
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
            'code' => 'BS-1',
            'sku' => 'BS-SKU-1',
            'name' => 'Bulk Shipment Product',
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
            'idempotency_suffix' => 'bs-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'BSC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Bulk Customer',
            'phone' => '01722222222',
            'address_line1' => 'House 9, Road 5, Gulshan',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'BSCX',
            'name' => 'Bulk Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-shipment', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 5): SalesOrder
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

    /** Shipped-bulk failure messages, tolerant of ViewErrorBag and the normalized array shape. */
    private function shipmentFailures(): array
    {
        $errors = session('errors');

        if ($errors instanceof ViewErrorBag) {
            return (array) $errors->first('shipments');
        }

        return (array) (data_get($errors, 'default.messages.shipments')
            ?? data_get($errors, 'shipments')
            ?? []);
    }

    public function test_bulk_creates_one_shipment_per_order_with_audit(): void
    {
        $first = $this->makeConfirmedOrder(3);
        $second = $this->makeConfirmedOrder(4);

        $response = $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$first->id, $second->id],
            'courier_id' => $this->courier->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('2 requested', $status);
        $this->assertStringContainsString('2 created', $status);
        $this->assertStringContainsString('0 failed', $status);

        $this->assertSame(2, Shipment::query()->count());

        $firstShipment = Shipment::query()->where('sales_order_id', $first->id)->firstOrFail();
        $this->assertEqualsWithDelta(3.0, (float) $firstShipment->lines->first()->qty, 0.0001);
        $secondShipment = Shipment::query()->where('sales_order_id', $second->id)->firstOrFail();
        $this->assertEqualsWithDelta(4.0, (float) $secondShipment->lines->first()->qty, 0.0001);

        $audit = AuditEvent::query()->where('action', 'sales.shipment_bulk_created')->firstOrFail();
        $this->assertSame(2, (int) $audit->after['counts']['created']);
        $this->assertSame(0, (int) $audit->after['counts']['failed']);
    }

    public function test_partial_failure_keeps_the_other_orders_shipped(): void
    {
        $good = $this->makeConfirmedOrder(3);
        $cancelled = $this->makeConfirmedOrder(4);
        SalesOrder::query()->whereKey($cancelled->id)->update(['status' => 'cancelled']);

        $response = $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$good->id, $cancelled->id],
            'courier_id' => $this->courier->id,
        ]);

        $response->assertRedirect();
        $status = (string) session('status');
        $this->assertStringContainsString('1 created', $status);
        $this->assertStringContainsString('1 failed', $status);

        $failures = $this->shipmentFailures();
        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('cannot be shipped', $failures[0]);

        $this->assertSame(1, Shipment::query()->count());
        $this->assertNotNull(
            Shipment::query()->where('sales_order_id', $good->id)->first(),
        );
    }

    public function test_empty_selection_and_missing_courier_are_validation_errors(): void
    {
        $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [],
            'courier_id' => $this->courier->id,
        ])->assertSessionHasErrors(['order_ids']);

        $order = $this->makeConfirmedOrder(3);

        $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$order->id],
        ])->assertSessionHasErrors(['courier_id']);

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_foreign_and_missing_orders_fail_validation_before_any_shipment(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Bulk Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrder = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'order_no' => 'SHADOW-B1',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = $this->makeConfirmedOrder(3);

        $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$order->id, $shadowOrder, 99999999],
            'courier_id' => $this->courier->id,
        ])->assertSessionHasErrors(['order_ids.1', 'order_ids.2']);

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_fully_shipped_order_fails_alone_without_blocking_the_others(): void
    {
        $shipped = $this->makeConfirmedOrder(3);
        $fresh = $this->makeConfirmedOrder(2);

        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $shipped->id,
            'courier_id' => $this->courier->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$shipped->id, $fresh->id],
            'courier_id' => $this->courier->id,
        ]);

        $status = (string) session('status');
        $this->assertStringContainsString('1 created', $status);
        $this->assertStringContainsString('1 failed', $status);

        $failures = $this->shipmentFailures();
        $this->assertStringContainsString('Nothing left to ship', $failures[0]);

        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(1, Shipment::query()->where('sales_order_id', $shipped->id)->count());
        $this->assertSame(1, Shipment::query()->where('sales_order_id', $fresh->id)->count());
    }

    public function test_bulk_route_respects_permissions(): void
    {
        $order = $this->makeConfirmedOrder(3);

        $denied = $this->makeUser(['name' => 'Bulk Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$order->id],
            'courier_id' => $this->courier->id,
        ])->assertForbidden();

        $viewer = $this->makeUser(['name' => 'Bulk List User']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.shipments'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$order->id],
            'courier_id' => $this->courier->id,
        ])->assertForbidden();

        $creator = $this->makeUser(['name' => 'Bulk Creator']);
        $creator->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.delivery.shipments.create',
        ])->id);
        app(PermissionCatalog::class)->invalidate($creator);

        $this->actingAs($creator)->post(route('sales.shipments.bulk'), [
            'order_ids' => [$order->id],
            'courier_id' => $this->courier->id,
        ])->assertRedirect();

        $this->assertSame(1, Shipment::query()->count());
    }
}
