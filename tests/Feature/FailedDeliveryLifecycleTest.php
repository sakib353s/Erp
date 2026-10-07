<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\FailedDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
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
 * 02-96 Failed delivery management at GET /app/sales/delivery/failed
 * behind sales.delivery.view with retry/return/reship behind
 * sales.delivery.shipments.create:
 *
 *  - a tracking observation that moves the shipment to delivery_failed
 *    opens the attempt row (attempt 1, verbatim courier reason);
 *  - retry puts the same shipment back out (no stock movement);
 *  - return reverses exactly the shipment's own dispatch (TRANSIT_IN
 *    mirrors TRANSIT_OUT, 100/0 restored) — refused with a truthful
 *    reason when the invoice already issued stock, and never a
 *    customer notice;
 *  - reship returns the stock, closes the failed shipment (cancelled
 *    so its lines free the ordered remainder), and creates the
 *    replacement through CreateShipment;
 *  - a later failure opens the next attempt; resolved records are
 *    final; company scoping and permission gates hold; no customer
 *    notifications are produced by the recovery actions.
 */
class FailedDeliveryLifecycleTest extends TestCase
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
            'code' => 'FD-1',
            'sku' => 'FD-SKU-1',
            'name' => 'Failed Delivery Product',
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
            'idempotency_suffix' => 'fd-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'FDC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Failed Delivery Customer',
            'phone' => '01766666666',
            'address_line1' => 'House 5, Road 9, Dhanmondi',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'FDX',
            'name' => 'Failed Delivery Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__failed-delivery', 'POST', [], [], [], [
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

    protected function makeShipment(SalesOrder $order, int $qty = 5): Shipment
    {
        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        return Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
    }

    protected function dispatch(Shipment $shipment): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    /** Record the courier's failure observation; opens the attempt row. */
    protected function failShipment(Shipment $shipment, string $reason = 'Recipient unavailable'): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.tracking.store', $shipment), [
                'event_code' => 'delivery_failed',
                'description' => $reason,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERY_FAILED, $shipment->fresh()->status);
    }

    public function test_delivery_failure_opens_a_failure_record(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $this->failShipment($shipment, 'Recipient unavailable at the address');

        $this->assertSame(Shipment::STATUS_DELIVERY_FAILED, $shipment->fresh()->status);
        $this->assertSame('in_transit', $order->fresh()->status); // no order hop for a failure

        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(FailedDelivery::STATUS_OPEN, $failure->status);
        $this->assertSame(1, (int) $failure->attempt_no);
        $this->assertSame('Recipient unavailable at the address', $failure->reason);
        $this->assertSame((int) $order->id, (int) $failure->sales_order_id);
        $this->assertSame((int) $this->courier->id, (int) $failure->courier_id);
        $this->assertNotNull($failure->failed_at);

        $this->assertSame(
            1,
            TrackingEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('event_code', 'delivery_failed')
                ->count(),
        );
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.tracking_event_ingested')->count(),
        );
    }

    public function test_failed_screen_lists_open_records_and_gates_access(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('No failed deliveries — every dispatched shipment is on track.');

        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment, 'Customer refused the parcel');

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('Shipment #'.$shipment->id)
            ->assertSee($order->order_no)
            ->assertSee('Customer refused the parcel')
            ->assertSee('Retry')
            ->assertSee('Return')
            ->assertSee('Reship');

        $denied = $this->makeUser(['name' => 'No Delivery View']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.shipments.create'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.failed.index'))
            ->assertForbidden();

        $granted = $this->makeUser(['name' => 'Delivery Viewer']);
        $granted->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($granted);

        $this->actingAs($granted)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('Customer refused the parcel');
    }

    public function test_retry_puts_the_shipment_back_out_for_delivery(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $movementsBefore = StockMovement::query()->count();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertRedirect(route('sales.delivery.failed.index'))
            ->assertSessionHas('status');

        $fresh = $failure->fresh();
        $this->assertSame(FailedDelivery::STATUS_RETRIED, $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame((int) $this->admin->id, (int) $fresh->resolved_by);
        $this->assertSame(Shipment::STATUS_OUT_FOR_DELIVERY, $shipment->fresh()->status);
        $this->assertSame('in_transit', $order->fresh()->status); // order untouched

        // The goods never came back — no stock movement on retry.
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->in_transit, 0.0001);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.failed_delivery_retried')->count(),
        );

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('No failed deliveries — every dispatched shipment is on track.');
    }

    public function test_retry_is_refused_once_the_record_is_resolved(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertSessionHasErrors('failed_delivery');
        $this->assertStringContainsString(
            'already resolved as [retried]',
            (string) session('errors')->get('failed_delivery')[0],
        );
        $this->assertSame(FailedDelivery::STATUS_RETRIED, $failure->fresh()->status);

        // Even with the row reopened, the shipment must be failed to retry.
        DB::table('failed_deliveries')->where('id', $failure->id)->update(['status' => 'open']);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertSessionHasErrors('failed_delivery');
        $this->assertStringContainsString(
            'not awaiting recovery',
            (string) session('errors')->get('failed_delivery')[0],
        );
        $this->assertSame(Shipment::STATUS_OUT_FOR_DELIVERY, $shipment->fresh()->status);
    }

    public function test_second_failure_opens_the_next_attempt(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment, 'First failure');

        $first = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $first))
            ->assertSessionHasNoErrors();

        $this->failShipment($shipment, 'Second failure');

        $rows = FailedDelivery::query()
            ->where('shipment_id', $shipment->id)
            ->orderBy('attempt_no')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]->attempt_no);
        $this->assertSame(FailedDelivery::STATUS_RETRIED, $rows[0]->status);
        $this->assertSame(2, (int) $rows[1]->attempt_no);
        $this->assertSame(FailedDelivery::STATUS_OPEN, $rows[1]->status);
        $this->assertSame('Second failure', $rows[1]->reason);

        // The screen lists only the open attempt.
        $this->actingAs($this->admin)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('Second failure')
            ->assertDontSee('First failure');
    }

    public function test_return_restores_stock_without_gl_or_notifications(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $journalsBefore = JournalEntry::query()->count();
        $notificationsBefore = Notification::query()->count();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.return', $failure))
            ->assertRedirect(route('sales.delivery.failed.index'))
            ->assertSessionHas('status');

        // STK reverse: this shipment's own dispatch, exactly mirrored.
        $return = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_TRANSIT_IN)
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->firstOrFail();
        $this->assertSame('shipment_returned', $return->source_event);
        $this->assertEqualsWithDelta(5.0, (float) $return->qty_signed, 0.0001);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        $fresh = $failure->fresh();
        $this->assertSame(FailedDelivery::STATUS_RETURNED, $fresh->status);
        $this->assertSame(Shipment::STATUS_DELIVERY_FAILED, $shipment->fresh()->status);
        $this->assertSame('in_transit', $order->fresh()->status);

        // ACCT untouched, and the recovery itself notified nobody.
        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame($notificationsBefore, Notification::query()->count());

        $audit = AuditEvent::query()
            ->where('action', 'sales.failed_delivery_returned')
            ->firstOrFail();
        $this->assertSame(1, (int) $audit->after['stock_reversed']);
        $this->assertNull($audit->after['stock_reason']);
    }

    public function test_return_is_refused_when_the_invoice_already_issued_stock(): void
    {
        $order = $this->makeConfirmedOrder();
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment); // invoice issued first — dispatch posts no stock
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $movementsBefore = StockMovement::query()->count();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.return', $failure))
            ->assertSessionHasErrors('failed_delivery');
        $this->assertStringContainsString(
            'sales return flow',
            (string) session('errors')->get('failed_delivery')[0],
        );

        $this->assertSame(FailedDelivery::STATUS_OPEN, $failure->fresh()->status);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_IN)->count(),
        );
    }

    public function test_reship_returns_stock_closes_and_creates_a_replacement(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.reship', $failure))
            ->assertRedirect(route('sales.delivery.failed.index'))
            ->assertSessionHas('status');

        // Stock: failed dispatch reversed back into the warehouse.
        $this->assertSame(
            1,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_IN)->count(),
        );
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        // Old shipment closed, replacement created for the same order.
        $this->assertSame(Shipment::STATUS_CANCELLED, $shipment->fresh()->status);
        $replacement = Shipment::query()
            ->where('sales_order_id', $order->id)
            ->where('id', '!=', $shipment->id)
            ->firstOrFail();
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $replacement->status);
        $this->assertSame((int) $this->courier->id, (int) $replacement->courier_id);
        $this->assertSame(
            [(int) $this->product->id, 5.0],
            $replacement->lines->map(fn ($line) => [(int) $line->product_id, (float) $line->qty])->first(),
        );

        $fresh = $failure->fresh();
        $this->assertSame(FailedDelivery::STATUS_RESHIPPED, $fresh->status);

        $audit = AuditEvent::query()
            ->where('action', 'sales.failed_delivery_reshipped')
            ->firstOrFail();
        $this->assertSame((int) $replacement->id, (int) $audit->after['new_shipment_id']);
        $this->assertSame(1, (int) $audit->after['stock_reversed']);

        // The remainder was freed: the replacement dispatches normally.
        $this->dispatch($replacement);
        $this->assertSame(Shipment::STATUS_DISPATCHED, $replacement->fresh()->status);
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $balance->in_transit, 0.0001);
    }

    public function test_action_routes_require_shipments_create(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);
        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();

        $viewer = $this->makeUser(['name' => 'View Only Recovery']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        foreach (['retry', 'return', 'reship'] as $action) {
            $this->actingAs($viewer)
                ->post(route('sales.delivery.failed.'.$action, $failure))
                ->assertForbidden();
        }
        $this->assertSame(FailedDelivery::STATUS_OPEN, $failure->fresh()->status);

        $operator = $this->makeUser(['name' => 'Recovery Operator']);
        $operator->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.shipments.create'])->id);
        app(PermissionCatalog::class)->invalidate($operator);

        $this->actingAs($operator)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertRedirect(route('sales.delivery.failed.index'));
        $this->assertSame(FailedDelivery::STATUS_RETRIED, $failure->fresh()->status);
    }

    public function test_foreign_failure_record_is_not_reachable(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Failed Ltd',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourierId = DB::table('couriers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW',
            'name' => 'Shadow Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCustomerId = DB::table('customers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDW-C1',
            'name' => 'Shadow Customer',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'customer_id' => $shadowCustomerId,
            'order_no' => 'FD-SHADOW',
            'status' => 'in_transit',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowShipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $shadowId,
            'sales_order_id' => $shadowOrderId,
            'courier_id' => $shadowCourierId,
            'status' => Shipment::STATUS_DELIVERY_FAILED,
            'dispatched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowFailureId = DB::table('failed_deliveries')->insertGetId([
            'company_id' => $shadowId,
            'shipment_id' => $shadowShipmentId,
            'attempt_no' => 1,
            'status' => 'open',
            'reason' => 'Shadow failure',
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shadow = FailedDelivery::query()->findOrFail($shadowFailureId);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $shadow))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.return', $shadow))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.reship', $shadow))
            ->assertNotFound();

        $this->assertSame('open', DB::table('failed_deliveries')->where('id', $shadowFailureId)->value('status'));
        $this->assertSame(
            Shipment::STATUS_DELIVERY_FAILED,
            DB::table('shipments')->where('id', $shadowShipmentId)->value('status'),
        );
    }

    public function test_recovery_actions_send_no_notifications(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->failShipment($shipment);

        // The tracking ingest may notify the internal order creator (02-90) —
        // that is the ONLY notification the failure flow produces.
        $afterFailure = Notification::query()->count();

        $failure = FailedDelivery::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.retry', $failure))
            ->assertSessionHasNoErrors();

        $this->assertSame($afterFailure, Notification::query()->count());

        // Fresh failure on the same shipment, then the stock-moving recovery.
        $this->failShipment($shipment, 'Still undeliverable');
        $second = FailedDelivery::query()
            ->where('shipment_id', $shipment->id)
            ->where('attempt_no', 2)
            ->firstOrFail();
        $afterSecondFailure = Notification::query()->count();

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.failed.return', $second))
            ->assertSessionHasNoErrors();

        $this->assertSame($afterSecondFailure, Notification::query()->count());
        $this->assertSame(
            0,
            Notification::query()->where('event_type', 'like', '%customer%')->count(),
        );
    }

    public function test_delivered_shipments_never_appear_on_the_failed_screen(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        // A successful delivery: no failure row exists for it.
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.tracking.store', $shipment), [
                'event_code' => 'delivered',
                'description' => 'Left with the guard',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame(0, FailedDelivery::query()->count());

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.failed.index'))
            ->assertOk()
            ->assertSee('No failed deliveries — every dispatched shipment is on track.')
            ->assertDontSee('Shipment #'.$shipment->id);
    }
}
