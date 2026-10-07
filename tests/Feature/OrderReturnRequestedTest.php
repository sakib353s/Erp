<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Returns\Actions\CreateReturnRequest;
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
 * 02-24 Sales › Orders › Return Requested: ?status=return_requested is the
 * returns team's window into sales orders (returns.view OR
 * sales.orders.view), the request moves a completed order to
 * return_requested through the state machine, and the menu leaf renders
 * only for returns.view holders.
 */
class OrderReturnRequestedTest extends TestCase
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
            'code' => 'RET-1',
            'sku' => 'RET-SKU-1',
            'name' => 'Return Request Product',
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
            'idempotency_suffix' => 'retreq-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-return-requested-test', 'POST', [], [], [], [
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

    protected function userWith(array $permissions): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($permissions)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_return_request_moves_completed_order_to_return_requested(): void
    {
        $invoice = $this->issuedInvoice();
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        $this->assertSame('completed', $order->status);

        $return = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 2]],
            'notes' => 'Damaged on arrival',
        ], $this->httpRequest());

        $this->assertSame('requested', $return->status);
        $this->assertSame('return_requested', $order->fresh()->status);

        $audit = AuditEvent::query()
            ->where('action', 'returns.request_created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('return_requested', $audit->after['order_status']);
        $this->assertTrue($audit->after['order_status_changed']);
    }

    public function test_return_status_filter_lists_only_return_requested_orders(): void
    {
        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());

        $control = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($control, $this->httpRequest());

        $returnOrder = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk()
            ->assertSee($returnOrder->order_no)
            ->assertSee('return_requested')
            ->assertDontSee($control->fresh()->order_no);
    }

    public function test_returns_viewer_can_open_return_status_filter_but_not_plain_orders_list(): void
    {
        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());

        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);

        $this->actingAs($viewer)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk()
            ->assertSee(SalesOrder::query()->findOrFail($invoice->sales_order_id)->order_no);

        // The plain list and non-return statuses stay sales.orders.view only.
        $this->actingAs($viewer)->get('/app/sales/orders')->assertForbidden();
        $this->actingAs($viewer)->get('/app/sales/orders?status=pending')->assertForbidden();

        $denied = AuditEvent::query()
            ->where('action', 'permission.denied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('sales.orders.view', $denied->after['permission']);
        $this->assertSame('missing permission: sales.orders.view', $denied->reason);
    }

    public function test_sales_orders_viewer_keeps_access_to_return_status_filter(): void
    {
        $salesUser = $this->userWith(['portal.erp.access', 'dashboard.view', 'sales.orders.view']);

        $this->actingAs($salesUser)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk();

        $this->actingAs($salesUser)
            ->get('/app/sales/orders')
            ->assertOk();
    }

    public function test_return_requested_menu_leaf_requires_returns_view(): void
    {
        $leaf = \App\Domain\Foundation\MenuItem::query()
            ->where('label', 'Return Requested')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/app/sales/orders?status=return_requested', $leaf->route);
        $this->assertSame('returns.view', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Return Requested')
            ->assertSee('status=return_requested');

        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Return Requested');

        $salesOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'sales.orders.view']);
        $this->actingAs($salesOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Return Requested');
    }

    public function test_returns_module_link_shows_on_return_status_rows(): void
    {
        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view', 'sales.orders.view']);

        $this->actingAs($viewer)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk()
            ->assertSee(route('sales.returns.index'))
            ->assertSee($order->order_no);

        // Without sales.orders.view the row never links into the order
        // detail page (the link would only 403).
        $returnsOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($returnsOnly)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk()
            ->assertSee(route('sales.returns.index'))
            ->assertDontSee('/app/sales/orders/'.$order->id.'"');
    }

    public function test_empty_return_requested_filter_is_honest(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=return_requested')
            ->assertOk()
            ->assertSee('No orders with status [return_requested] yet.');

        $this->assertSame(0, SalesReturn::query()->count());
    }
}
