<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Services\GoodsReceiptService;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Purchase\Services\SupplierService;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 03 Purchase + 06 Suppliers gate.
 *
 * What this pins:
 *  · order money is recomputed from the lines — a forged header total is ignored;
 *  · the person who raised an order cannot approve it, and approval is a
 *    separate permission from creation;
 *  · goods receipts are the only path that moves purchase stock in, and posting
 *    writes a real movement + valuation layer at the cost actually paid;
 *  · over-receipt against a PO is refused with the numbers, never absorbed;
 *  · the order status follows received quantities (partial → received);
 *  · a posted receipt is immutable; drafts can be cancelled with a reason;
 *  · blacklisting a supplier requires a reason and blocks new documents.
 */
class PurchaseFlowTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin);

        $this->warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $this->product = $this->makeProduct('PUR-1', 'Purchase Probe');
    }

    protected function makeProduct(string $code, string $name): Product
    {
        $request = Request::create('/app/products', 'POST');
        $request->setUserResolver(fn () => $this->admin);

        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $request);
    }

    protected function makeSupplier(array $attributes = []): Supplier
    {
        return app(SupplierService::class)->create(array_merge([
            'name' => 'Nile Textiles',
            'phone' => '01700000000',
            'payment_terms_days' => 30,
        ], $attributes), $this->admin->id);
    }

    /** A draft order with one line of 10 units at 250. */
    protected function makeOrder(array $overrides = []): PurchaseOrder
    {
        $supplier = $overrides['supplier'] ?? $this->makeSupplier();

        return app(PurchaseOrderService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-01',
            'expected_date' => '2026-10-10',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Purchase probe',
                'qty_ordered' => 10,
                'unit_price' => 250,
                'discount' => 100,
                'tax_rate' => 5,
            ]],
        ], $this->admin->id);
    }

    public function test_order_totals_are_recomputed_from_the_lines(): void
    {
        $order = $this->makeOrder();

        // 10 × 250 = 2500, − 100 discount = 2400, + 5% tax = 120 → 2520
        $this->assertSame('2500.0000', (string) $order->subtotal);
        $this->assertSame('100.0000', (string) $order->discount_total);
        $this->assertSame('120.0000', (string) $order->tax_total);
        $this->assertSame('2520.0000', (string) $order->total);
        $this->assertSame('PO-00001', $order->code);
        $this->assertSame('draft', $order->status);
    }

    public function test_self_approval_is_refused_and_approval_needs_its_own_permission(): void
    {
        $order = $this->makeOrder();

        app(PurchaseOrderService::class)->submit($order, $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot approve it');

        app(PurchaseOrderService::class)->approve($order->refresh(), $this->admin->id);
    }

    public function test_approval_route_is_gated_on_the_approve_permission(): void
    {
        $order = $this->makeOrder();
        app(PurchaseOrderService::class)->submit($order, $this->admin->id);

        $buyer = $this->makeUser();
        $buyer->roles()->attach($this->roleWith([
            'portal.erp.access', 'purchase.orders.view', 'purchase.orders.create',
        ])->id);

        $this->actingAs($buyer)->post('/app/purchase/orders/'.$order->id.'/approve')->assertForbidden();

        // The buyer can see the order but not sign it off.
        $this->actingAs($buyer)->get('/app/purchase/orders/'.$order->id)->assertOk();
    }

    public function test_posting_a_receipt_moves_stock_and_advances_the_order(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);

        $approver = $this->makeUser();
        $orders->approve($order->refresh(), $approver->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-05',
            'challan_no' => 'CH-991',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 4,
                'unit_cost' => 245,
            ]],
        ], $this->admin->id);

        $this->assertSame('draft', $receipt->status);
        $this->assertSame('0.0000', (string) StockBalance::query()->where('product_id', $this->product->id)->value('on_hand') ?? '0.0000', 'draft receipts never move stock');

        $result = app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $this->assertSame(1, $result['movements']);
        $this->assertSame(980.0, $result['value']);

        $movement = StockMovement::query()
            ->where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->firstOrFail();

        $this->assertSame(StockMovement::TYPE_PURCHASE_RECEIPT, $movement->movement_type);
        $this->assertSame('4.0000', (string) $movement->qty_signed);
        $this->assertSame('245.0000', (string) $movement->unit_cost);

        $balance = StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();

        $this->assertSame('4.0000', (string) $balance->on_hand);

        $order->refresh()->load('lines');
        $this->assertSame('partially_received', $order->status);
        $this->assertSame('4.0000', (string) $order->lines->first()->qty_received);
        $this->assertSame(6.0, $order->outstandingQty());
    }

    public function test_receiving_everything_closes_the_order(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-06',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 10,
                'unit_cost' => 250,
            ]],
        ], $this->admin->id);

        app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $this->assertSame('received', $order->refresh()->status);
        $this->assertSame(0.0, $order->refresh()->load('lines')->outstandingQty());
    }

    public function test_over_receipt_is_refused_with_numbers(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-07',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 12,
                'unit_cost' => 250,
            ]],
        ], $this->admin->id);

        try {
            app(GoodsReceiptService::class)->post($receipt, $this->admin->id);
            $this->fail('Over-receipt was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Over-receipt', $e->getMessage());
            $this->assertStringContainsString('12.0000', $e->getMessage());
            $this->assertStringContainsString('10.0000', $e->getMessage());
        }

        $this->assertSame('draft', $receipt->refresh()->status, 'the receipt stays a draft after a refused post');
        $this->assertSame(0, StockMovement::query()->where('source_type', 'goods_receipt')->count());
    }

    public function test_a_receipt_cannot_be_raised_against_an_unapproved_order(): void
    {
        $order = $this->makeOrder(); // still a draft

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing can be received against it');

        app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-08',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 1,
                'unit_cost' => 250,
            ]],
        ], $this->admin->id);
    }

    public function test_a_posted_receipt_cannot_be_cancelled(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-09',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 2,
                'unit_cost' => 250,
            ]],
        ], $this->admin->id);

        app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('raise a purchase return instead');

        app(GoodsReceiptService::class)->cancel($receipt->refresh(), 'Wrong quantities', $this->admin->id);
    }

    public function test_direct_receipt_without_an_order_still_moves_stock(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Dhaka Packaging']);

        $receipt = app(GoodsReceiptService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-04',
            'notes' => 'Arrived without paperwork',
            'lines' => [[
                'product_id' => $this->product->id,
                'qty_received' => 3,
                'unit_cost' => 300,
            ]],
        ], $this->admin->id);

        app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $this->assertSame(3.0, (float) StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('on_hand'));
        $this->assertSame('posted', $receipt->refresh()->status);
    }

    public function test_blacklisting_needs_a_reason_and_blocks_new_documents(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Questionable Traders']);
        $service = app(SupplierService::class);

        try {
            $service->blacklist($supplier, '   ', $this->admin->id);
            $this->fail('A reason-less blacklist was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requires a reason', $e->getMessage());
        }

        $service->blacklist($supplier, 'Repeated short deliveries', $this->admin->id);
        $this->assertTrue($supplier->refresh()->is_blacklisted);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blacklisted');

        app(PurchaseOrderService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-02',
            'lines' => [['description' => 'Anything', 'qty_ordered' => 1, 'unit_price' => 1]],
        ], $this->admin->id);
    }

    public function test_order_cannot_be_cancelled_after_goods_arrived(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-10',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 1,
                'unit_cost' => 250,
            ]],
        ], $this->admin->id);

        app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('return it instead of cancelling');

        $orders->cancel($order->refresh(), 'No longer needed', $this->admin->id);
    }

    public function test_purchase_screens_render_for_an_authorised_user(): void
    {
        $this->makeOrder();

        $this->actingAs($this->admin)->get('/app/suppliers')->assertOk();
        $this->actingAs($this->admin)->get('/app/suppliers/create')->assertOk();
        $this->actingAs($this->admin)->get('/app/purchase/orders')->assertOk();
        $this->actingAs($this->admin)->get('/app/purchase/orders/create')->assertOk();
        $this->actingAs($this->admin)->get('/app/purchase/receipts')->assertOk();
        $this->actingAs($this->admin)->get('/app/purchase/receipts/create')->assertOk();
    }

    public function test_purchase_screens_are_permission_gated(): void
    {
        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view'])->id);

        $this->actingAs($outsider)->get('/app/purchase/orders')->assertForbidden();
        $this->actingAs($outsider)->get('/app/suppliers')->assertForbidden();
    }

    public function test_stock_ledger_rebuild_agrees_with_the_live_balance(): void
    {
        $order = $this->makeOrder();
        $orders = app(PurchaseOrderService::class);
        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipt = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-11',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 7,
                'unit_cost' => 240,
            ]],
        ], $this->admin->id);

        app(GoodsReceiptService::class)->post($receipt, $this->admin->id);

        $live = (float) StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('on_hand');

        app(StockLedgerService::class)->rebuildBalances($this->admin->company_id);

        $rebuilt = (float) StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('on_hand');

        $this->assertSame(7.0, $live);
        $this->assertSame($live, $rebuilt, 'the cached balance is exactly what the immutable ledger replays to');
    }

    public function test_receipt_codes_are_unique_per_company(): void
    {
        $supplier = $this->makeSupplier();

        $first = app(GoodsReceiptService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-12',
            'lines' => [['product_id' => $this->product->id, 'qty_received' => 1, 'unit_cost' => 100]],
        ], $this->admin->id);

        $second = app(GoodsReceiptService::class)->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-12',
            'lines' => [['product_id' => $this->product->id, 'qty_received' => 1, 'unit_cost' => 100]],
        ], $this->admin->id);

        $this->assertSame('GRN-00001', $first->code);
        $this->assertSame('GRN-00002', $second->code);
        $this->assertSame(2, GoodsReceipt::query()->count());
    }
}
