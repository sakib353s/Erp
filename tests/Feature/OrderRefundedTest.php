<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\Actions\IssueCreditNote;
use App\Domain\Returns\Actions\ProcessRefund;
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
 * 02-27 Sales › Orders › Refunded: the refund settles the order (from
 * returned OR straight from return_requested when the credit note was
 * issued before the goods came back — stock then honestly stays out).
 * The filter is the money window: returns.refunds.view only, and the
 * menu leaf follows the same key.
 */
class OrderRefundedTest extends TestCase
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
            'code' => 'RET-REF-1',
            'sku' => 'RET-REF-SKU-1',
            'name' => 'Refunded Product',
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
            'idempotency_suffix' => 'retref-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-refunded-test', 'POST', [], [], [], [
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

    protected function refund(SalesReturn $salesReturn)
    {
        return app(ProcessRefund::class)->handle($salesReturn, [
            'method' => 'cash',
            'idempotency_key' => 'refund-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function userWith(array $permissions): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($permissions)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_refund_after_receive_and_credit_marks_order_refunded(): void
    {
        $invoice = $this->issuedInvoice();
        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 2]],
        ], $this->httpRequest());

        app(ReceiveReturnedGoods::class)->handle($salesReturn, [], $this->httpRequest());
        app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());

        $refund = $this->refund($salesReturn->fresh());
        $this->assertSame('posted', $refund->status);

        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        $this->assertSame('refunded', $order->status);

        $audit = AuditEvent::query()
            ->where('action', 'returns.refund_processed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('refunded', $audit->after['order_status']);

        $entry = JournalEntry::query()->findOrFail($refund->journal_entry_id);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
    }

    public function test_credit_before_receive_refund_settles_order_without_stock_return(): void
    {
        $invoice = $this->issuedInvoice();
        $this->assertEquals(45.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);

        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 2]],
        ], $this->httpRequest());
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);
        $this->assertSame('return_requested', $order->status);

        // Credit note straight from the request (returns blade path) —
        // the goods never came back.
        app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());
        $this->refund($salesReturn->fresh());

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertEquals(45.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        $this->assertSame(
            0,
            StockMovement::query()->where('movement_type', StockMovement::TYPE_SALES_RETURN)->count()
        );
    }

    public function test_refunded_filter_requires_returns_refunds_view(): void
    {
        $invoice = $this->issuedInvoice();
        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());
        $this->refund($salesReturn->fresh());
        $order = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $returnsOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($returnsOnly)->get('/app/sales/orders?status=refunded')->assertForbidden();

        $denied = AuditEvent::query()
            ->where('action', 'permission.denied')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('returns.refunds.view', $denied->after['permission']);

        $refundsUser = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.refunds.view']);
        $this->actingAs($refundsUser)
            ->get('/app/sales/orders?status=refunded')
            ->assertOk()
            ->assertSee($order->fresh()->order_no);
    }

    public function test_refunded_status_filter_lists_only_refunded_orders(): void
    {
        $invoice = $this->issuedInvoice();
        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => 1]],
        ], $this->httpRequest());
        app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());
        $this->refund($salesReturn->fresh());
        $refunded = SalesOrder::query()->findOrFail($invoice->sales_order_id);

        $control = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($control, $this->httpRequest());

        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=refunded')
            ->assertOk()
            ->assertSee($refunded->fresh()->order_no)
            ->assertDontSee($control->fresh()->order_no);
    }

    public function test_refunded_menu_leaf_requires_returns_refunds_view(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Refunded')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/app/sales/orders?status=refunded', $leaf->route);
        $this->assertSame('returns.refunds.view', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Refunded');

        $refundsUser = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.refunds.view']);
        $this->actingAs($refundsUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Refunded');

        // returns.view alone is the operations window — the money leaf
        // stays hidden without returns.refunds.view.
        $returnsOnly = $this->userWith(['portal.erp.access', 'dashboard.view', 'returns.view']);
        $this->actingAs($returnsOnly)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Return Requested')
            ->assertDontSee('Refunded');
    }

    public function test_empty_refunded_filter_is_honest(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/sales/orders?status=refunded')
            ->assertOk()
            ->assertSee('No orders with status [refunded] yet.');

        $this->assertSame(0, SalesReturn::query()->count());
    }
}
