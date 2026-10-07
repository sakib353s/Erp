<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-115 Sales by Product: GET /app/reports/sales/by-product aggregates
 * real invoice lines (issued/partial/paid) into per-product rows.
 */
class SalesByProductTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    public function test_product_rows_equal_the_invoice_line_totals(): void
    {
        $alpha = $this->makeProduct('BPK-1', 'Breakdown Alpha');
        $beta = $this->makeProduct('BPK-2', 'Breakdown Beta');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $alpha->id, 'qty' => 3, 'unit_price' => 100, 'discount' => 20],
            ['product_id' => $beta->id, 'qty' => 2, 'unit_price' => 50],
        ]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'product', $window);

        $rowAlpha = collect($report['rows'])->firstWhere('label', 'Breakdown Alpha');
        $rowBeta = collect($report['rows'])->firstWhere('label', 'Breakdown Beta');

        $this->assertSame($alpha->sku, $rowAlpha['code']);
        $this->assertEqualsWithDelta(3.0, $rowAlpha['qty'], 0.0001);
        $this->assertEqualsWithDelta(280.0, $rowAlpha['net'], 0.0001); // 3×100 − 20 discount
        $this->assertSame(1, $rowAlpha['invoices']);

        $this->assertEqualsWithDelta(100.0, $rowBeta['net'], 0.0001);
        $this->assertEqualsWithDelta(380.0, $report['totals']['net'], 0.0001);
        $this->assertSame(3.0 + 2.0, $report['totals']['qty']);
        $this->assertSame(1, $report['totals']['invoices']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-product', $window))
            ->assertOk()
            ->assertSee($alpha->sku)
            ->assertSee($beta->sku)
            ->assertSee('280.00')
            ->assertSee('380.00');
    }

    public function test_window_filter_excludes_lines_outside_the_dates(): void
    {
        $alpha = $this->makeProduct('BPK-3', 'Breakdown Windowed');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $alpha->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $this->makeInvoice($this->fyDay(30), [
            ['product_id' => $alpha->id, 'qty' => 10, 'unit_price' => 10],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'product', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertEqualsWithDelta(300.0, $report['totals']['net'], 0.0001);
        $this->assertSame(1, $report['totals']['invoices']);
    }

    public function test_unissued_invoices_contribute_nothing(): void
    {
        $alpha = $this->makeProduct('BPK-4', 'Breakdown Draft');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $alpha->id, 'qty' => 4, 'unit_price' => 100],
        ], issue: false);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'product', $window);

        $this->assertSame(0, $report['totals']['invoices']);
        $this->assertSame(0.0, $report['totals']['net']);
        $this->assertSame([], $report['rows']->all());

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-product', $window))
            ->assertOk()
            ->assertSee('No revenue lines in this window.')
            ->assertDontSee($alpha->sku);
    }

    public function test_unknown_dimension_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'territory');
    }

    public function test_breakdown_routes_require_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Breakdown Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-product'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Breakdown Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-product'))
            ->assertOk();
    }
}
