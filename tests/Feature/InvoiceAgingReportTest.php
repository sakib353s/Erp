<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Reporting\AgingReportService;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
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
 * 02-64 Invoice aging report: GET /app/reports/sales/invoice-aging. Buckets
 * are a strict partition of open AR sourced from invoices themselves.
 */
class InvoiceAgingReportTest extends TestCase
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
            'code' => 'AGE-1',
            'sku' => 'AGE-SKU-1',
            'name' => 'Aging Probe Product',
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
            'idempotency_suffix' => 'age-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__invoice-aging', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeInvoice(string $dueDate, array $overrides = []): Invoice
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
            array_merge(['due_date' => $dueDate], $overrides['payload'] ?? []),
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

    public function test_buckets_partition_every_open_balance(): void
    {
        $current = $this->makeInvoice(now()->addDays(20)->toDateString());
        $d10 = $this->makeInvoice(now()->subDays(10)->toDateString());
        $d40 = $this->makeInvoice(now()->subDays(40)->toDateString());
        $d70 = $this->makeInvoice(now()->subDays(70)->toDateString());
        $d100 = $this->makeInvoice(now()->subDays(100)->toDateString());

        // Closed documents never enter AR.
        $paid = $this->makeInvoice(now()->subDays(30)->toDateString(), [
            'status' => 'paid',
            'due_amount' => '0',
        ]);
        $void = $this->makeInvoice(now()->subDays(30)->toDateString(), [
            'status' => 'void',
            'due_amount' => '0',
        ]);

        $report = app(AgingReportService::class)->forCompany($this->admin->company_id);

        $this->assertSame('1-30', $report['rows']->firstWhere('invoice_id', $d10->id)['bucket']);
        $this->assertSame('31-60', $report['rows']->firstWhere('invoice_id', $d40->id)['bucket']);
        $this->assertSame('61-90', $report['rows']->firstWhere('invoice_id', $d70->id)['bucket']);
        $this->assertSame('91+', $report['rows']->firstWhere('invoice_id', $d100->id)['bucket']);
        $this->assertSame('current', $report['rows']->firstWhere('invoice_id', $current->id)['bucket']);

        $this->assertSame(5, $report['totals']['invoices']);
        $this->assertEqualsWithDelta(
            (float) $current->fresh()->due_amount
                + (float) $d10->fresh()->due_amount
                + (float) $d40->fresh()->due_amount
                + (float) $d70->fresh()->due_amount
                + (float) $d100->fresh()->due_amount,
            $report['totals']['amount'],
            0.0001,
        );
        $this->assertEqualsWithDelta(
            $report['totals']['amount'],
            array_sum($report['buckets']),
            0.0001,
        );
        $this->assertArrayNotHasKey($paid->id, $report['rows']->pluck('invoice_id', 'invoice_id'));
        $this->assertArrayNotHasKey($void->id, $report['rows']->pluck('invoice_id', 'invoice_id'));

        $this->actingAs($this->admin)
            ->get(route('sales.reports.invoice-aging'))
            ->assertOk()
            ->assertSee($d100->invoice_no)
            ->assertDontSee($paid->invoice_no)
            ->assertDontSee($void->invoice_no)
            ->assertSee(number_format($report['totals']['amount'], 2));
    }

    public function test_bucket_boundaries_are_exact(): void
    {
        $this->assertSame('1-30', AgingReportService::bucketFor(1));
        $this->assertSame('1-30', AgingReportService::bucketFor(30));
        $this->assertSame('31-60', AgingReportService::bucketFor(31));
        $this->assertSame('31-60', AgingReportService::bucketFor(60));
        $this->assertSame('61-90', AgingReportService::bucketFor(61));
        $this->assertSame('61-90', AgingReportService::bucketFor(90));
        $this->assertSame('91+', AgingReportService::bucketFor(91));
        $this->assertSame('91+', AgingReportService::bucketFor(400));
    }

    public function test_as_of_date_rebalances_the_report(): void
    {
        $invoice = $this->makeInvoice(now()->addDays(5)->toDateString());

        $today = app(AgingReportService::class)->forCompany($this->admin->company_id);
        $this->assertSame('current', $today['rows']->firstWhere('invoice_id', $invoice->id)['bucket']);
        $this->assertSame($today['totals']['amount'], $today['buckets']['current']);

        $later = app(AgingReportService::class)->forCompany(
            $this->admin->company_id,
            now()->addDays(45)->toDateString(),
        );
        $row = $later['rows']->firstWhere('invoice_id', $invoice->id);
        $this->assertSame('31-60', $row['bucket']);
        $this->assertSame(40, $row['days_overdue']);
        $this->assertSame($later['totals']['amount'], $later['buckets']['31-60']);
    }

    public function test_branch_filter_scopes_balances(): void
    {
        $invoice = $this->makeInvoice(now()->subDays(12)->toDateString());

        $scoped = app(AgingReportService::class)->forCompany(
            $this->admin->company_id,
            null,
            (int) $this->defaultBranch()->id,
        );
        $this->assertSame(1, $scoped['totals']['invoices']);
        $this->assertSame($invoice->invoice_no, $scoped['rows']->first()['invoice_no']);

        $otherBranch = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'AGE-BR2',
            'name' => 'Aging Second Branch',
        ]);

        $empty = app(AgingReportService::class)->forCompany(
            $this->admin->company_id,
            null,
            (int) $otherBranch->id,
        );
        $this->assertSame(0, $empty['totals']['invoices']);
        $this->assertSame(0.0, $empty['totals']['amount']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.invoice-aging', ['branch_id' => $otherBranch->id]))
            ->assertOk()
            ->assertDontSee($invoice->invoice_no)
            ->assertSee('No open balances.');
    }

    public function test_draft_and_zero_balance_invoices_never_enter_ar(): void
    {
        $draft = $this->makeInvoice(now()->subDays(9)->toDateString(), ['status' => 'draft']);
        $settled = $this->makeInvoice(now()->subDays(9)->toDateString(), [
            'status' => 'paid',
            'due_amount' => '0',
        ]);

        $report = app(AgingReportService::class)->forCompany($this->admin->company_id);

        $this->assertSame(0, $report['totals']['invoices']);
        $this->assertSame(0.0, $report['totals']['amount']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.invoice-aging'))
            ->assertOk()
            ->assertSee('No open balances.')
            ->assertDontSee($draft->invoice_no)
            ->assertDontSee($settled->invoice_no);
    }

    public function test_aging_report_requires_the_reports_view_permission(): void
    {
        $this->makeInvoice(now()->subDays(6)->toDateString());

        $denied = $this->makeUser(['name' => 'No Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.invoice-aging'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.invoice-aging'))
            ->assertOk();
    }
}
