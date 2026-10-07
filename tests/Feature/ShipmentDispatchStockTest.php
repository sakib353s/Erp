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
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-89c Dispatch stock stage: DispatchShipment posts TRANSIT_OUT per
 * stocked line from the order's warehouse (on_hand → in_transit,
 * layer FIFO), walks the order chain to in_transit, audits
 * sales.shipment_dispatched — and refuses to fabricate movements when
 * the order has no warehouse or the invoice already issued the stock.
 * Dispatch-then-issue clears the transit exactly once (TRANSIT_OUT +
 * TRANSIT_CLEAR, never a second SALES_OUT); invoice-then-dispatch
 * issues from stock only once too. Double dispatch is refused and
 * every route is permission-gated.
 */
class ShipmentDispatchStockTest extends TestCase
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
            'code' => 'DSP-1',
            'sku' => 'DSP-SKU-1',
            'name' => 'Dispatch Product',
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
            'idempotency_suffix' => 'dsp-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DSPC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Dispatch Customer',
            'phone' => '01733333333',
            'address_line1' => 'House 4, Road 7, Uttara',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DSPX',
            'name' => 'Dispatch Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__dispatch-shipment', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** @param array<int, array{product_id: int, qty: float|int}> $lines */
    protected function makeConfirmedOrder(array $lines): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => array_map(
                fn (array $line) => $line + ['unit_price' => 150],
                $lines,
            ),
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    protected function makeShipment(SalesOrder $order, array $lines): Shipment
    {
        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => $lines,
        ])->assertSessionHasNoErrors();

        return Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
    }

    public function test_dispatch_posts_transit_out_and_advances_the_order(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);
        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect()
            ->assertSessionHas('status');

        $shipment = $shipment->fresh();
        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->status);
        $this->assertNotNull($shipment->dispatched_at);

        $movement = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->firstOrFail();
        $this->assertSame((int) $this->product->id, (int) $movement->product_id);
        $this->assertEqualsWithDelta(-5.0, (float) $movement->qty_signed, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $movement->unit_cost, 0.0001);

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->in_transit, 0.0001);

        $this->assertSame('in_transit', $order->fresh()->status);

        $audit = AuditEvent::query()
            ->where('action', 'sales.shipment_dispatched')
            ->where('entity_id', $shipment->id)
            ->firstOrFail();
        $this->assertTrue($audit->after['stock_posted']);
        $this->assertSame(1, (int) $audit->after['posted_lines']);
        $this->assertNull($audit->after['stock_reason']);
    }

    public function test_dispatch_without_warehouse_skips_stock_with_truthful_reason(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);
        SalesOrder::query()->whereKey($order->id)->update(['warehouse_id' => null]);
        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect();

        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)->count(),
        );

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        $audit = AuditEvent::query()
            ->where('action', 'sales.shipment_dispatched')
            ->where('entity_id', $shipment->id)
            ->firstOrFail();
        $this->assertFalse($audit->after['stock_posted']);
        $this->assertSame('Order has no warehouse configured.', $audit->after['stock_reason']);

        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
        $this->assertSame('in_transit', $order->fresh()->status);
    }

    public function test_non_stock_line_is_skipped_while_stocked_lines_post(): void
    {
        $nonStocked = app(CreateProduct::class)->handle([
            'code' => 'DSP-NS',
            'sku' => 'DSP-SKU-NS',
            'name' => 'Non Stocked Service',
            'cost_method' => 'fifo',
            'standard_cost' => 0,
            'is_stocked' => false,
            'is_active' => true,
        ], $this->httpRequest());

        // Reservation reads the balance cache on confirm even for
        // non-managed SKUs, so give it a row to hold against.
        StockBalance::query()->create([
            'company_id' => $this->admin->company_id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $nonStocked->id,
            'on_hand' => 10,
        ]);

        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 3],
            ['product_id' => $nonStocked->id, 'qty' => 2],
        ]);
        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 3],
            ['product_id' => $nonStocked->id, 'qty' => 2],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect();

        $movements = StockMovement::query()->where('source_type', 'shipment')->get();
        $this->assertCount(1, $movements);
        $this->assertSame((int) $this->product->id, (int) $movements->first()->product_id);

        $audit = AuditEvent::query()
            ->where('action', 'sales.shipment_dispatched')
            ->where('entity_id', $shipment->id)
            ->firstOrFail();
        $this->assertTrue($audit->after['stock_posted']);
        $this->assertSame(1, (int) $audit->after['posted_lines']);
    }

    public function test_dispatch_after_invoice_never_reissues_stock(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $balanceAfterIssue = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balanceAfterIssue->on_hand, 0.0001);

        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect();

        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)->count(),
        );

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        $audit = AuditEvent::query()
            ->where('action', 'sales.shipment_dispatched')
            ->where('entity_id', $shipment->id)
            ->firstOrFail();
        $this->assertFalse($audit->after['stock_posted']);
        $this->assertSame('Invoice already issued stock for this order.', $audit->after['stock_reason']);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_invoice_after_dispatch_clears_transit_exactly_once(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());

        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 5],
        ]);
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect();

        $balanceAfterDispatch = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balanceAfterDispatch->on_hand, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $balanceAfterDispatch->in_transit, 0.0001);

        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $this->assertSame(
            1,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)->count(),
        );
        $clear = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_TRANSIT_CLEAR)
            ->firstOrFail();
        $this->assertEqualsWithDelta(-5.0, (float) $clear->qty_signed, 0.0001);
        $this->assertSame((int) $invoice->id, (int) $clear->source_id);
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_SALES_OUT)->count(),
        );

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_double_dispatch_is_refused(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 2],
        ]);
        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 2],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertSessionHasErrors('shipment');

        $this->assertStringContainsString(
            'cannot be dispatched from status',
            (string) session('errors')->first('shipment'),
        );

        $this->assertSame(
            1,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)->count(),
        );
    }

    public function test_dispatch_and_index_routes_respect_permissions(): void
    {
        $order = $this->makeConfirmedOrder([
            ['product_id' => $this->product->id, 'qty' => 2],
        ]);
        $shipment = $this->makeShipment($order, [
            ['product_id' => $this->product->id, 'qty' => 2],
        ]);

        $denied = $this->makeUser(['name' => 'Dispatch Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.shipments.index'))->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertForbidden();

        $viewer = $this->makeUser(['name' => 'Dispatch List User']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.shipments'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)->get(route('sales.shipments.index'))->assertOk();
        $this->actingAs($viewer)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertForbidden();

        $dispatcher = $this->makeUser(['name' => 'Dispatch Creator']);
        $dispatcher->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.delivery.shipments.create',
        ])->id);
        app(PermissionCatalog::class)->invalidate($dispatcher);

        $this->actingAs($dispatcher)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect();

        $this->assertSame(Shipment::STATUS_DISPATCHED, $shipment->fresh()->status);
    }
}
