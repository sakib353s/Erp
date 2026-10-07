<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Queries\InvoiceQuery;
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
 * 02-63 Overdue invoices: GET /app/sales/invoices?overdue=1 driven by
 * InvoiceQuery@overdue (due date vs today in the tenant timezone, open
 * balance only, issued/partial only).
 */
class OverdueInvoicesQueryTest extends TestCase
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
            'code' => 'OVD-1',
            'sku' => 'OVD-SKU-1',
            'name' => 'Overdue Probe Product',
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
            'idempotency_suffix' => 'ovd-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__overdue-invoices', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeOrder(array $overrides = []): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides), $this->httpRequest());
    }

    /** Invoice with a fixed due date; status/due_amount steered for the case. */
    protected function makeInvoice(string $dueDate, array $overrides = []): Invoice
    {
        $order = $this->makeOrder();
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle(
            $order->fresh(),
            ['due_date' => $dueDate],
            $this->httpRequest(),
        );

        $status = $overrides['status'] ?? 'issued';
        if (in_array($status, ['issued', 'partial', 'paid'], true)) {
            $invoice = app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
        }

        $invoice->forceFill([
            'status' => $status,
            'due_amount' => array_key_exists('due_amount', $overrides)
                ? $overrides['due_amount']
                : $invoice->due_amount,
        ])->save();

        return $invoice->refresh();
    }

    public function test_overdue_filter_returns_only_open_invoices_past_their_due_date(): void
    {
        $overdue = $this->makeInvoice(now()->subDays(10)->toDateString());
        $future = $this->makeInvoice(now()->addDays(10)->toDateString());
        $paid = $this->makeInvoice(now()->subDays(20)->toDateString(), [
            'status' => 'paid',
            'due_amount' => '0',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertOk()
            ->assertSee($overdue->invoice_no)
            ->assertDontSee($future->invoice_no)
            ->assertDontSee($paid->invoice_no);

        // Without the facet the ledger still lists everything.
        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index'))
            ->assertOk()
            ->assertSee($overdue->invoice_no)
            ->assertSee($future->invoice_no)
            ->assertSee($paid->invoice_no);
    }

    public function test_partially_settled_invoices_stay_overdue_until_the_balance_clears(): void
    {
        $partial = $this->makeInvoice(now()->subDays(3)->toDateString(), [
            'status' => 'partial',
            'due_amount' => '250.0000',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertOk()
            ->assertSee($partial->invoice_no);
    }

    public function test_draft_invoices_are_never_reported_overdue(): void
    {
        $draft = $this->makeInvoice(now()->subDays(5)->toDateString(), ['status' => 'draft']);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertOk()
            ->assertDontSee($draft->invoice_no)
            ->assertSee('No overdue invoices.');

        $this->assertSame(
            0,
            app(InvoiceQuery::class)->overdue(Invoice::query()->whereKey($draft->id))->count(),
        );
    }

    public function test_overdue_rows_show_whole_days_past_the_due_date(): void
    {
        $overdue = $this->makeInvoice(now()->subDays(7)->toDateString());

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertOk()
            ->assertSee($overdue->invoice_no)
            ->assertSee('7d overdue');
    }

    public function test_overdue_filter_combines_with_the_status_facet(): void
    {
        $overdue = $this->makeInvoice(now()->subDays(4)->toDateString());

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.index', ['overdue' => 1, 'status' => 'paid']))
            ->assertOk()
            ->assertDontSee($overdue->invoice_no)
            ->assertSee('No overdue invoices.');
    }

    public function test_overdue_screen_requires_the_invoice_view_permission(): void
    {
        $this->makeInvoice(now()->subDays(6)->toDateString());

        $denied = $this->makeUser(['name' => 'No Invoice Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Invoice Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.invoices.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.invoices.index', ['overdue' => 1]))
            ->assertOk();
    }
}
