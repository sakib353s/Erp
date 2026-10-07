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
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
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
 * 02-25 Sales › Orders › Return Approved: the approved state sits between
 * the request and the goods receipt. The approve ACTION belongs to 07-04
 * (PLANNED); until then the state is driven through the order state
 * machine directly — no fake approval endpoint is shipped. The filter,
 * its permission gate and the menu leaf follow the returns team
 * (returns.view) exactly like return_requested.
 */
class OrderReturnApprovedTest extends TestCase
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
            'code' => 'RET-APR-1',
            'sku' => 'RET-APR-SKU-1',
            'name' => 'Return Approved Product',
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
            'idempotency_suffix' => 'retappr-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-return-approved-test', 'POST', [], [], [], [
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

    /** Request a return, then drive the machine to return_approved. */
    protected function approvedOrder(): SalesOrder
    {
        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());

        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        app(OrderStateMachine::class)->transition($order, 'return_approved');

        return $order->fresh();
    }

    protected function userWith(array $permissions): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($permissions)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_state_machine_reaches_return_approved_from_the_request(): void
    {
        $machine = app(OrderStateMachine::class);

        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        $this->assertSame('return_requested', $order->status);

        // 07-04 (ApprovalEngine/Workflow) is PLANNED — the approval itself
        // is driven through the machine until that slice ships.
        $order = $machine->transition($order, 'return_approved');
        $this->assertSame('return_approved', $order->status);

        $this->assertTrue($machine->canTransition('return_approved', 'returned'));
        $this->assertTrue($machine->canTransition('return_approved', 'refunded'));
        $this->assertFalse($machine->canTransition('return_approved', 'pending'));
    }

    public function test_return_status_filter_lists_only_return_approved_orders(): void
    {
        $approved = $this->approvedOrder();

        $invoice = $this->issuedInvoice();
        app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        $requested = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=return_approved')
            ->assertOk()
            ->assertSee($approved->order_no)
            ->assertSee('return_approved')
            ->assertDontSee($requested->fresh()->order_no);
    }

    public function test_returns_viewer_can_open_return_approved_filter_but_not_plain_list(): void
    {
        $approved = $this->approvedOrder();
        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);

        $this->actingAs($viewer)
            ->get('/app/sales/orders?status=return_approved')
            ->assertOk()
            ->assertSee($approved->order_no);

        $this->actingAs($viewer)->get('/app/sales/orders')->assertForbidden();
        $this->actingAs($viewer)->get('/app/sales/orders?status=pending')->assertForbidden();

        $denied = AuditEvent::query()
            ->where('action', 'permission.denied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('sales.orders.view', $denied->after['permission']);
    }

    public function test_return_approved_menu_leaf_requires_returns_view(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Return Approved')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/app/sales/orders?status=return_approved', $leaf->route);
        $this->assertSame('returns.view', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Return Approved');

        $viewer = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Return Approved');

        $salesOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'sales.orders.view']);
        $this->actingAs($salesOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Return Approved')
            ->assertSee('Dashboard');
    }

    public function test_empty_return_approved_filter_is_honest(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=return_approved')
            ->assertOk()
            ->assertSee('No orders with status [return_approved] yet.');

        $this->assertSame(0, SalesReturn::query()->count());
    }
}
