<?php

namespace Tests\Feature;

use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Reporting\SalesSummaryReport;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use Carbon\Carbon;
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
 * 02-114 Sales summary report: GET /app/reports/sales/summary. Totals are
 * direct aggregates of invoices and posted receipts over the filter window.
 */
class SalesSummaryReportTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected string $fyStart;

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

        $fy = FiscalYear::query()
            ->where('company_id', $this->admin->company_id)
            ->where('is_current', true)
            ->firstOrFail();
        $this->fyStart = $fy->starts_on->toDateString();

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'SUM-1',
            'sku' => 'SUM-SKU-1',
            'name' => 'Summary Probe Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 500, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'sum-open-'.uniqid(),
        ], $this->httpRequest());
    }

    /** Date N days after the fiscal year start — always inside an open posting period. */
    protected function fyDay(int $days): string
    {
        return Carbon::parse($this->fyStart)->addDays($days)->toDateString();
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__sales-summary', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeInvoice(string $invoiceDate, array $overrides = []): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle(
            $order->fresh(),
            array_merge(
                ['invoice_date' => $invoiceDate, 'due_date' => $invoiceDate],
                $overrides['payload'] ?? [],
            ),
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

    public function test_totals_equal_the_invoices_in_the_window(): void
    {
        $first = $this->makeInvoice($this->fyDay(10));
        $second = $this->makeInvoice($this->fyDay(20), ['status' => 'paid', 'due_amount' => '0']);

        $report = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(30),
        ]);

        $expectedGross = (float) $first->fresh()->grand_total + (float) $second->fresh()->grand_total;

        $this->assertSame(2, $report['totals']['invoices']);
        $this->assertEqualsWithDelta($expectedGross, $report['totals']['gross'], 0.0001);
        $this->assertEqualsWithDelta(
            (float) $first->fresh()->paid_amount + (float) $second->fresh()->paid_amount,
            $report['totals']['paid'],
            0.0001,
        );
        $this->assertEqualsWithDelta(
            (float) $first->fresh()->due_amount + (float) $second->fresh()->due_amount,
            $report['totals']['due'],
            0.0001,
        );

        // The rendered screen shows exactly those aggregates.
        $this->actingAs($this->admin)
            ->get(route('sales.reports.summary', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(30),
            ]))
            ->assertOk()
            ->assertSee($first->invoice_no)
            ->assertSee($second->invoice_no)
            ->assertSee(number_format($report['totals']['gross'], 2));
    }

    public function test_date_range_excludes_invoices_outside_the_window(): void
    {
        $inWindow = $this->makeInvoice($this->fyDay(10));
        $before = $this->makeInvoice($this->fyDay(40));

        $report = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['invoices']);
        $this->assertSame($inWindow->invoice_no, $report['rows']->first()['invoice_no']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.summary', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(20),
            ]))
            ->assertOk()
            ->assertSee($inWindow->invoice_no)
            ->assertDontSee($before->invoice_no);
    }

    public function test_status_and_customer_filters_scope_the_totals(): void
    {
        $issued = $this->makeInvoice($this->fyDay(10));
        $paid = $this->makeInvoice($this->fyDay(12), ['status' => 'paid', 'due_amount' => '0']);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];

        $paidOnly = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, $window + [
            'status' => 'paid',
        ]);
        $this->assertSame(1, $paidOnly['totals']['invoices']);
        $this->assertSame($paid->invoice_no, $paidOnly['rows']->first()['invoice_no']);

        $none = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, $window + [
            'customer_id' => 999999,
        ]);
        $this->assertSame(0, $none['totals']['invoices']);
        $this->assertSame(0.0, $none['totals']['gross']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.summary', $window + ['customer_id' => 999999]))
            ->assertOk()
            ->assertDontSee($issued->invoice_no)
            ->assertDontSee($paid->invoice_no)
            ->assertSee('No invoices in this window.');
    }

    public function test_collections_follow_the_payment_method_and_window(): void
    {
        $invoice = $this->makeInvoice($this->fyDay(10));
        $due = (float) $invoice->fresh()->due_amount;

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => $due / 2,
            'method' => 'bank',
            'paid_at' => $this->fyDay(14),
        ], $this->httpRequest());

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => $due / 2,
            'method' => 'cash',
            'paid_at' => $this->fyDay(30),
        ], $this->httpRequest());

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(16)];

        $bankWindow = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, $window + [
            'method' => 'bank',
        ]);
        $this->assertSame(1, $bankWindow['payments']['count']);
        $this->assertEqualsWithDelta($due / 2, $bankWindow['payments']['amount'], 0.0001);

        $cashWindow = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, $window + [
            'method' => 'cash',
        ]);
        $this->assertSame(0, $cashWindow['payments']['count']);
        $this->assertSame(0.0, $cashWindow['payments']['amount']);

        $allInWindow = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, $window);
        $this->assertSame(1, $allInWindow['payments']['count']);

        $this->assertSame(
            2,
            Payment::query()->where('company_id', $this->admin->company_id)->count(),
        );
    }

    public function test_empty_window_reports_zero_without_synthetic_rows(): void
    {
        $this->makeInvoice($this->fyDay(10));

        $report = app(SalesSummaryReport::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(200),
            'date_to' => $this->fyDay(230),
        ]);

        $this->assertSame(0, $report['totals']['invoices']);
        $this->assertSame(0.0, $report['totals']['gross']);
        $this->assertSame([], $report['rows']->all());
        $this->assertSame([], $report['by_status']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.summary', [
                'date_from' => $this->fyDay(200),
                'date_to' => $this->fyDay(230),
            ]))
            ->assertOk()
            ->assertSee('No invoices in this window.')
            ->assertSee(number_format(0.0, 2));
    }

    public function test_summary_screen_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Summary Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.summary'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Summary Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.summary'))
            ->assertOk();
    }
}
