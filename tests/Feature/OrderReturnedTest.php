<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\Actions\IssueCreditNote;
use App\Domain\Returns\Actions\ReceiveReturnedGoods;
use App\Domain\Returns\SalesReturn;
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
 * 02-26 Sales › Orders › Returned: receiving the goods moves the order to
 * returned (stock comes back), the filter and its returns.view gate mirror
 * return_requested, and issuing the credit note does NOT advance the order
 * further — only the refund settles it (02-27).
 */
class OrderReturnedTest extends TestCase
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
            'code' => 'RET-REC-1',
            'sku' => 'RET-REC-SKU-1',
            'name' => 'Returned Product',
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
            'idempotency_suffix' => 'retrec-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-returned-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function issuedInvoice(): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        return $invoice->fresh();
    }

    /** Request a return and receive the goods — order lands on returned. */
    protected function returnedOrder()
    {
        $invoice = $this->issuedInvoice();
        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 2]],
        ], $this->httpRequest());

        app(ReceiveReturnedGoods::class)->handle($salesReturn, [], $this->httpRequest());

        return [
            'order' => SalesOrder::query()->findOrFail($invoice->sales_order_id),
            'return' => $salesReturn->fresh(),
            'invoice' => $invoice,
        ];
    }

    protected function userWith(array $permissions): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($permissions)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_receiving_the_goods_moves_order_to_returned_and_restores_stock(): void
    {
        $invoice = $this->issuedInvoice();
        $this->assertEquals(45.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand); // 50 − 5 issued

        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 2]],
        ], $this->httpRequest());
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        $this->assertSame('return_requested', $order->status);

        app(ReceiveReturnedGoods::class)->handle($salesReturn, [], $this->httpRequest());

        $this->assertSame('returned', $order->fresh()->status);
        $this->assertEquals(47.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand); // 45 + 2 back

        $audit = AuditEvent::query()
            ->where('action', 'returns.received')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('returned', $audit->after['order_status']);
        $this->assertTrue($audit->after['order_status_changed']);
    }

    public function test_credit_note_after_receive_leaves_order_returned(): void
    {
        ['order' => $order, 'return' => $salesReturn] = $this->returnedOrder();

        $creditNote = app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());
        $this->assertSame('issued', $creditNote->status);

        // The credit note settles the return document, not the order —
        // only the refund (02-27) moves the order to refunded.
        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_return_status_filter_lists_only_returned_orders(): void
    {
        ['order' => $returned] = $this->returnedOrder();

        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        $requested = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=returned')
            ->assertOk()
            ->assertSee($returned->fresh()->order_no)
            ->assertSee('returned')
            ->assertDontSee($requested->fresh()->order_no);
    }

    public function test_returns_viewer_can_open_returned_filter_but_not_plain_list(): void
    {
        ['order' => $returned] = $this->returnedOrder();
        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);

        $this->actingAs($viewer)
            ->get('/app/sales/orders?status=returned')
            ->assertOk()
            ->assertSee($returned->fresh()->order_no);

        $this->actingAs($viewer)->get('/app/sales/orders')->assertForbidden();
        $this->actingAs($viewer)->get('/app/sales/orders?status=cancelled')->assertForbidden();

        $denied = AuditEvent::query()
            ->where('action', 'permission.denied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('sales.orders.view', $denied->after['permission']);
    }

    public function test_returned_menu_leaf_requires_returns_view(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Returned')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/app/sales/orders?status=returned', $leaf->route);
        $this->assertSame('returns.view', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Returned');

        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Returned');

        $salesOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'sales.orders.view']);
        $this->actingAs($salesOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Returned')
            ->assertSee('Dashboard');
    }

    public function test_empty_returned_filter_is_honest(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=returned')
            ->assertOk()
            ->assertSee('No orders with status [returned] yet.');

        $this->assertSame(0, SalesReturn::query()->count());
    }
}
