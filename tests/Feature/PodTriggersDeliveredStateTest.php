<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\ProofOfDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
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
use App\Domain\Sales\Actions\CancelOrder;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-95b POD triggers the delivered state: shipment dispatched →
 * delivered, order walked to delivered through OrderStateMachine
 * only (never backwards — completed stays completed, cancelled stays
 * cancelled), a delivered tracking event + truthful in-app
 * notification to the order creator, linked delivery challans follow.
 *
 * Stock/GL/warranty stay at their configured stages (dispatch
 * TRANSIT_OUT, invoice issue TRANSIT_CLEAR/SALES_OUT + COGS; warranty
 * activation remains Phase L): POD itself posts zero movements and
 * zero journal entries — delivery-stage STK/ACCT is never
 * fabricated — and the invoice-after-delivery flow still clears
 * transit exactly once.
 */
class PodTriggersDeliveredStateTest extends TestCase
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
            'code' => 'POD-1',
            'sku' => 'POD-SKU-1',
            'name' => 'POD Product',
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
            'idempotency_suffix' => 'pod-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PODC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'POD Customer',
            'phone' => '01755555555',
            'address_line1' => 'House 9, Road 12, Gulshan',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PODX',
            'name' => 'POD Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pod', 'POST', [], [], [], [
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

    protected function recordPod(Shipment $shipment, string $receiver = 'Delivery Receiver'): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('sales.delivery.pod.store', $shipment), [
                'receiver_name' => $receiver,
            ]);
    }

    public function test_pod_moves_the_shipment_and_order_to_delivered_with_notification(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->assertSame('in_transit', $order->fresh()->status);

        $this->recordPod($shipment, 'Karim Uddin')
            ->assertRedirect(route('sales.delivery.pod.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);

        $event = TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('event_code', 'delivered')
            ->firstOrFail();
        $this->assertSame('manual', $event->source);

        $notification = Notification::query()
            ->where('event_type', 'sales.shipment_tracking')
            ->where('user_id', $this->admin->id)
            ->firstOrFail();
        $this->assertStringContainsString($order->order_no, $notification->title);
        $this->assertStringContainsString('Delivered', $notification->title);
    }

    public function test_pod_posts_no_stock_and_no_journal_entries(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $movementsBefore = StockMovement::query()->count();
        $journalsBefore = JournalEntry::query()->count();
        $balanceBefore = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();

        $this->recordPod($shipment);

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());

        $balanceAfter = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta((float) $balanceBefore->on_hand, (float) $balanceAfter->on_hand, 0.0001);
        $this->assertEqualsWithDelta((float) $balanceBefore->in_transit, (float) $balanceAfter->in_transit, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $balanceAfter->in_transit, 0.0001); // still in transit until invoice, per config

        // The POD audit carries no stock fields — nothing was posted here.
        $audit = AuditEvent::query()
            ->where('action', 'sales.pod_recorded')
            ->firstOrFail();
        $this->assertArrayNotHasKey('stock_posted', $audit->after);
        $this->assertArrayNotHasKey('posted_lines', $audit->after);
    }

    public function test_invoice_after_pod_still_clears_transit_exactly_once(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        $this->recordPod($shipment);
        $this->assertSame('delivered', $order->fresh()->status);

        // Invoicing a delivered order is the configured ACCT/STK stage.
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $this->assertSame(
            1,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)->count(),
        );
        $clear = StockMovement::query()
            ->where('movement_type', StockMovement::TYPE_TRANSIT_CLEAR)
            ->firstOrFail();
        $this->assertEqualsWithDelta(-5.0, (float) $clear->qty_signed, 0.0001);
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_SALES_OUT)->count(),
        );

        $balance = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(95.0, (float) $balance->on_hand, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $balance->in_transit, 0.0001);

        $this->assertSame(
            1,
            JournalEntry::query()->where('source_event', 'sales_cost')->count(),
        );

        $fresh = $invoice->fresh();
        $this->assertSame('issued', $fresh->status);
        $this->assertFalse((bool) $fresh->warranty_flag); // warranty activation remains Phase L
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_pod_after_invoice_keeps_the_order_completed_and_adds_nothing(): void
    {
        $order = $this->makeConfirmedOrder();
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());
        $this->assertSame('completed', $order->fresh()->status);

        $movementsBefore = StockMovement::query()->count();
        $journalsBefore = JournalEntry::query()->count();

        $this->recordPod($shipment)->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status); // never moves backwards
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame(1, ProofOfDelivery::query()->count());
    }

    public function test_linked_delivery_challans_follow_the_pod(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $dispatchedChallan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [], $this->httpRequest());
        DB::table('delivery_challans')->where('id', $dispatchedChallan->id)->update(['status' => 'dispatched']);

        $readyChallan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [], $this->httpRequest());
        DB::table('delivery_challans')->where('id', $readyChallan->id)->update(['status' => 'ready']);

        $draftChallan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [], $this->httpRequest());

        $cancelledChallan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [], $this->httpRequest());
        DB::table('delivery_challans')->where('id', $cancelledChallan->id)->update(['status' => 'cancelled']);

        $this->recordPod($shipment);

        $this->assertSame('delivered', $dispatchedChallan->fresh()->status);
        $this->assertNotNull($dispatchedChallan->fresh()->delivered_at);
        $this->assertSame('delivered', $readyChallan->fresh()->status);
        $this->assertSame('draft', $draftChallan->fresh()->status);
        $this->assertSame('cancelled', $cancelledChallan->fresh()->status);

        $this->assertSame(
            2,
            AuditEvent::query()->where('action', 'sales.delivery_challan_delivered')->count(),
        );
    }

    public function test_pending_shipment_refuses_pod_and_the_order_is_untouched(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order); // pending_dispatch — never dispatched

        $this->recordPod($shipment)
            ->assertSessionHasErrors('proof');

        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(0, TrackingEvent::query()->count());
    }

    public function test_failed_delivery_shipment_refuses_pod(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);
        DB::table('shipments')->where('id', $shipment->id)->update([
            'status' => Shipment::STATUS_DELIVERY_FAILED,
        ]);

        $this->recordPod($shipment)
            ->assertSessionHasErrors('proof');
        $this->assertStringContainsString(
            'from status [delivery_failed]',
            (string) session('errors')->get('proof')[0],
        );

        $this->assertSame(Shipment::STATUS_DELIVERY_FAILED, $shipment->fresh()->status);
        $this->assertSame(0, ProofOfDelivery::query()->count());
        $this->assertSame(0, TrackingEvent::query()->count());
    }

    public function test_cancelled_order_is_not_advanced_but_the_shipment_still_delivers(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        app(CancelOrder::class)->handle($order->fresh(), 'Customer cancelled', $this->httpRequest());
        $this->assertSame('cancelled', $order->fresh()->status);

        $movementsBefore = StockMovement::query()->count();
        $journalsBefore = JournalEntry::query()->count();

        $this->recordPod($shipment)->assertSessionHasNoErrors();

        $this->assertSame(Shipment::STATUS_DELIVERED, $shipment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status); // blocked state never advances
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());
    }

    public function test_double_pod_leaves_exactly_one_state_change(): void
    {
        $order = $this->makeConfirmedOrder();
        $shipment = $this->makeShipment($order);
        $this->dispatch($shipment);

        $this->recordPod($shipment, 'First Receiver')->assertSessionHasNoErrors();
        $this->recordPod($shipment, 'Second Receiver')
            ->assertSessionHasErrors('proof');

        $this->assertSame(1, ProofOfDelivery::query()->count());
        $this->assertSame('First Receiver', ProofOfDelivery::query()->firstOrFail()->receiver_name);
        $this->assertSame(
            1,
            TrackingEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('event_code', 'delivered')
                ->count(),
        );
        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(
            1,
            Notification::query()
                ->where('event_type', 'sales.shipment_tracking')
                ->count(),
        );
    }
}
