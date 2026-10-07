<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-116 Sales by Employee: GET /app/reports/sales/by-employee aggregates
 * issued/partial/paid invoices per salesperson (invoices.sales_person_id).
 */
class SalesByEmployeeTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    public function test_employee_rows_group_invoices_by_sales_person(): void
    {
        $rahim = $this->makeEmployee('BEM-1', 'Rahim Uddin');
        $karim = $this->makeEmployee('BEM-2', 'Karim Chowdhury');
        $product = $this->makeProduct('BPE-1', 'Employee Dim Product');

        $first = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $this->tagInvoice($first, ['sales_person_id' => $rahim->id]);

        $second = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 250],
        ]);
        $this->tagInvoice($second, ['sales_person_id' => $karim->id]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'employee', $window);

        $this->assertSame('invoices', $report['source']);

        $rowRahim = collect($report['rows'])->firstWhere('label', 'Rahim Uddin');
        $rowKarim = collect($report['rows'])->firstWhere('label', 'Karim Chowdhury');

        $this->assertSame(1, $rowRahim['invoices']);
        $this->assertEqualsWithDelta(300.0, $rowRahim['gross'], 0.0001);
        $this->assertEqualsWithDelta(250.0, $rowKarim['gross'], 0.0001);
        $this->assertEqualsWithDelta(550.0, $report['totals']['gross'], 0.0001);
        $this->assertSame(2, $report['totals']['invoices']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-employee', $window))
            ->assertOk()
            ->assertSee('Rahim Uddin')
            ->assertSee('Karim Chowdhury')
            ->assertSee('550.00');
    }

    public function test_invoices_without_a_sales_person_land_in_the_unassigned_bucket(): void
    {
        $product = $this->makeProduct('BPE-2', 'Employee Dim Ungrouped');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 75],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'employee', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('Unassigned', $report['rows']->first()['label']);
        $this->assertEqualsWithDelta(150.0, $report['totals']['gross'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-employee', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(20),
            ]))
            ->assertOk()
            ->assertSee('Unassigned');
    }

    public function test_window_filter_excludes_invoices_outside_the_dates(): void
    {
        $product = $this->makeProduct('BPE-3', 'Employee Dim Windowed');
        $employee = $this->makeEmployee('BEM-3', 'Window Walker');

        $inside = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->tagInvoice($inside, ['sales_person_id' => $employee->id]);

        $outside = $this->makeInvoice($this->fyDay(30), [
            ['product_id' => $product->id, 'qty' => 10, 'unit_price' => 50],
        ]);
        $this->tagInvoice($outside, ['sales_person_id' => $employee->id]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'employee', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('Window Walker', $report['rows']->first()['label']);
        $this->assertSame(1, $report['totals']['invoices']);
        $this->assertEqualsWithDelta(200.0, $report['totals']['gross'], 0.0001);
    }

    public function test_by_employee_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Employee Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-employee'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Employee Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-employee'))
            ->assertOk();
    }
}
