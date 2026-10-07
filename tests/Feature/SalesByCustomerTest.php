<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-116 Sales by Customer: GET /app/reports/sales/by-customer aggregates
 * issued/partial/paid invoices per customer (count, gross, paid, due).
 */
class SalesByCustomerTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    public function test_customer_rows_equal_the_invoice_totals(): void
    {
        $alpha = $this->makeCustomer('BCA-1', 'Breakdown Customer Alpha');
        $beta = $this->makeCustomer('BCA-2', 'Breakdown Customer Beta');
        $product = $this->makeProduct('BPC-1', 'Customer Dim Product');

        $first = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $this->tagInvoice($first, ['customer_id' => $alpha->id]);

        $second = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 50],
        ]);
        $this->tagInvoice($second, ['customer_id' => $beta->id]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'customer', $window);

        $this->assertSame('invoices', $report['source']);

        $rowAlpha = collect($report['rows'])->firstWhere('label', 'Breakdown Customer Alpha');
        $rowBeta = collect($report['rows'])->firstWhere('label', 'Breakdown Customer Beta');

        $this->assertSame(1, $rowAlpha['invoices']);
        $this->assertEqualsWithDelta(300.0, $rowAlpha['gross'], 0.0001);
        $this->assertEqualsWithDelta(300.0, $rowAlpha['due'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $rowAlpha['paid'], 0.0001);

        $this->assertEqualsWithDelta(100.0, $rowBeta['gross'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $report['totals']['gross'], 0.0001);
        $this->assertSame(2, $report['totals']['invoices']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-customer', $window))
            ->assertOk()
            ->assertSee('Breakdown Customer Alpha')
            ->assertSee('Breakdown Customer Beta')
            ->assertSee('300.00')
            ->assertSee('400.00');
    }

    public function test_invoices_without_a_customer_land_in_the_untagged_bucket(): void
    {
        $product = $this->makeProduct('BPC-2', 'Customer Dim Ungrouped');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 4, 'unit_price' => 100],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'customer', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('No customer', $report['rows']->first()['label']);
        $this->assertEqualsWithDelta(400.0, $report['totals']['gross'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-customer', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(20),
            ]))
            ->assertOk()
            ->assertSee('No customer');
    }

    public function test_window_filter_excludes_invoices_outside_the_dates(): void
    {
        $product = $this->makeProduct('BPC-3', 'Customer Dim Windowed');

        $inside = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->tagInvoice($inside, ['customer_id' => $this->makeCustomer('BCA-3', 'In Window')->id]);

        $outside = $this->makeInvoice($this->fyDay(30), [
            ['product_id' => $product->id, 'qty' => 10, 'unit_price' => 50],
        ]);
        $this->tagInvoice($outside, ['customer_id' => $this->makeCustomer('BCA-4', 'Out of Window')->id]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'customer', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('In Window', $report['rows']->first()['label']);
        $this->assertEqualsWithDelta(200.0, $report['totals']['gross'], 0.0001);
    }

    public function test_by_customer_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Customer Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-customer'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Customer Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-customer'))
            ->assertOk();
    }
}
