<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\ProductCategory;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-115 Sales by Category: GET /app/reports/sales/by-category groups the
 * same invoice-line source by product category, keeping uncategorised
 * revenue visible instead of hiding it.
 */
class SalesByCategoryTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    protected function makeCategory(string $code, string $name): ProductCategory
    {
        return ProductCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
        ]);
    }

    public function test_lines_group_by_product_category(): void
    {
        $phones = $this->makeCategory('CAT-PH', 'Phones');
        $accessories = $this->makeCategory('CAT-AC', 'Accessories');

        $phone = $this->makeProduct('BCAT-P', 'Category Phone', $phones);
        $case = $this->makeProduct('BCAT-C', 'Category Case', $accessories);
        $charger = $this->makeProduct('BCAT-CH', 'Category Charger', $phones);

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $phone->id, 'qty' => 2, 'unit_price' => 500],
            ['product_id' => $case->id, 'qty' => 4, 'unit_price' => 25],
            ['product_id' => $charger->id, 'qty' => 1, 'unit_price' => 60],
        ]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'category', $window);

        $byLabel = collect($report['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(1060.0, $byLabel['Phones']['net'], 0.0001);
        $this->assertSame(2, $byLabel['Phones']['lines']);
        $this->assertEqualsWithDelta(100.0, $byLabel['Accessories']['net'], 0.0001);
        $this->assertSame(1, $byLabel['Accessories']['lines']);
        $this->assertEqualsWithDelta(1160.0, $report['totals']['net'], 0.0001);
        $this->assertSame(2, $report['totals']['rows']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-category', $window))
            ->assertOk()
            ->assertSee('Phones')
            ->assertSee('Accessories')
            ->assertSee('1,060.00')
            ->assertSee('1,160.00');
    }

    public function test_uncategorised_revenue_stays_visible(): void
    {
        $phones = $this->makeCategory('CAT-PH2', 'Phones II');
        $known = $this->makeProduct('BCAT-K', 'Category Known', $phones);
        $loose = $this->makeProduct('BCAT-L', 'Category Loose');

        $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $known->id, 'qty' => 1, 'unit_price' => 100],
            ['product_id' => $loose->id, 'qty' => 3, 'unit_price' => 40],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'category', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $byLabel = collect($report['rows'])->keyBy('label');

        $this->assertArrayHasKey('Uncategorised', $byLabel->all());
        $this->assertEqualsWithDelta(120.0, $byLabel['Uncategorised']['net'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $byLabel['Phones II']['net'], 0.0001);
        $this->assertEqualsWithDelta(220.0, $report['totals']['net'], 0.0001);
    }

    public function test_window_and_status_scope_the_category_totals(): void
    {
        $phones = $this->makeCategory('CAT-PH3', 'Phones III');
        $product = $this->makeProduct('BCAT-S', 'Category Scoped', $phones);

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 9, 'unit_price' => 100],
        ], issue: false);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'category', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['invoices']);
        $this->assertEqualsWithDelta(200.0, $report['totals']['net'], 0.0001);

        $outside = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'category', [
            'date_from' => $this->fyDay(40),
            'date_to' => $this->fyDay(60),
        ]);

        $this->assertSame(0, $outside['totals']['invoices']);
        $this->assertSame(0.0, $outside['totals']['net']);
    }

    public function test_category_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Category Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-category'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Category Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-category'))
            ->assertOk();
    }
}
