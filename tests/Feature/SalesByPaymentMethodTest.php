<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\SalesBreakdownReport;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-117 Sales by Payment Method: GET /app/reports/sales/by-method
 * aggregates posted inbound receipts per method (cash/bank/cheque/mobile).
 */
class SalesByPaymentMethodTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    /** @return array{0: Invoice, 1: Payment} */
    private function paidInvoice(
        string $invoiceDate,
        string $payDate,
        int $qty,
        string $method,
    ): array {
        $product = $this->makeProduct('BPM-'.uniqid(), 'Method Product '.$method);

        $invoice = $this->makeInvoice($invoiceDate, [
            ['product_id' => $product->id, 'qty' => $qty, 'unit_price' => 100],
        ]);

        $payment = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => $qty * 100,
            'method' => $method,
            'paid_at' => $payDate,
        ], $this->httpRequest());

        return [$invoice, $payment];
    }

    public function test_payment_rows_group_posted_receipts_by_method(): void
    {
        $this->paidInvoice($this->fyDay(5), $this->fyDay(7), 3, 'cash');
        $this->paidInvoice($this->fyDay(6), $this->fyDay(8), 2, 'mobile');

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'method', $window);

        $this->assertSame('payments', $report['source']);

        $cash = collect($report['rows'])->firstWhere('label', 'Cash');
        $mobile = collect($report['rows'])->firstWhere('label', 'Mobile');

        $this->assertSame(1, $cash['payments']);
        $this->assertEqualsWithDelta(300.0, $cash['amount'], 0.0001);
        $this->assertEqualsWithDelta(60.0, $cash['share'], 0.01);

        $this->assertEqualsWithDelta(200.0, $mobile['amount'], 0.0001);
        $this->assertEqualsWithDelta(40.0, $mobile['share'], 0.01);

        $this->assertSame(2, $report['totals']['payments']);
        $this->assertEqualsWithDelta(500.0, $report['totals']['amount'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-method', $window))
            ->assertOk()
            ->assertSee('Sales by Payment Method')
            ->assertSee('Cash')
            ->assertSee('Mobile')
            ->assertSee('500.00');
    }

    public function test_voided_and_outbound_payments_are_excluded(): void
    {
        $product = $this->makeProduct('BPM-EXCL', 'Method Excluded');
        $invoice = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 6, 'unit_price' => 100],
        ]);

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'cash',
            'paid_at' => $this->fyDay(7),
        ], $this->httpRequest());

        $voided = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'method' => 'mobile',
            'paid_at' => $this->fyDay(7),
        ], $this->httpRequest());
        $voided->forceFill(['status' => 'void'])->save();

        $outbound = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'method' => 'bank',
            'paid_at' => $this->fyDay(7),
        ], $this->httpRequest());
        $outbound->forceFill(['direction' => 'out'])->save();

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'method', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['payments']);
        $this->assertEqualsWithDelta(100.0, $report['totals']['amount'], 0.0001);
        $this->assertCount(1, $report['rows']);
        $this->assertSame('Cash', $report['rows']->first()['label']);
    }

    public function test_window_filter_excludes_receipts_outside_the_dates(): void
    {
        $this->paidInvoice($this->fyDay(5), $this->fyDay(7), 1, 'cash');
        $this->paidInvoice($this->fyDay(8), $this->fyDay(30), 5, 'cash');

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'method', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['payments']);
        $this->assertEqualsWithDelta(100.0, $report['totals']['amount'], 0.0001);
    }

    public function test_empty_window_reports_zero_without_synthetic_rows(): void
    {
        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'method', $window);

        $this->assertSame(0, $report['totals']['payments']);
        $this->assertSame(0.0, $report['totals']['amount']);
        $this->assertSame([], $report['rows']->all());

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-method', $window))
            ->assertOk()
            ->assertSee('No receipts in this window.');
    }

    public function test_by_method_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Method Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-method'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Method Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-method'))
            ->assertOk();
    }
}
