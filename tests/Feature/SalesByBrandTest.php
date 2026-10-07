<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\Brand;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-115 Sales by Brand: GET /app/reports/sales/by-brand groups the same
 * invoice-line source by product brand, with brandless revenue in its own
 * honest bucket and shares that add up.
 */
class SalesByBrandTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    protected function makeBrand(string $code, string $name): Brand
    {
        return Brand::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
        ]);
    }

    public function test_lines_group_by_product_brand(): void
    {
        $acme = $this->makeBrand('BR-AC', 'Acme');
        $globex = $this->makeBrand('BR-GL', 'Globex');

        $widget = $this->makeProduct('BBR-W', 'Brand Widget', null, $acme);
        $gadget = $this->makeProduct('BBR-G', 'Brand Gadget', null, $acme);
        $thing = $this->makeProduct('BBR-T', 'Brand Thing', null, $globex);

        $this->makeInvoice($this->fyDay(7), [
            ['product_id' => $widget->id, 'qty' => 2, 'unit_price' => 150],
            ['product_id' => $gadget->id, 'qty' => 1, 'unit_price' => 50],
            ['product_id' => $thing->id, 'qty' => 5, 'unit_price' => 20],
        ]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'brand', $window);

        $byLabel = collect($report['rows'])->keyBy('label');

        $this->assertEqualsWithDelta(350.0, $byLabel['Acme']['net'], 0.0001);
        $this->assertSame(2, $byLabel['Acme']['lines']);
        $this->assertEqualsWithDelta(100.0, $byLabel['Globex']['net'], 0.0001);
        $this->assertEqualsWithDelta(450.0, $report['totals']['net'], 0.0001);
        $this->assertSame(2, $report['totals']['rows']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-brand', $window))
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Globex')
            ->assertSee('350.00')
            ->assertSee('450.00');
    }

    public function test_brandless_revenue_lands_in_its_own_bucket(): void
    {
        $acme = $this->makeBrand('BR-AC2', 'Acme II');
        $branded = $this->makeProduct('BBR-B1', 'Brand Branded', null, $acme);
        $bare = $this->makeProduct('BBR-B2', 'Brand Bare');

        $this->makeInvoice($this->fyDay(8), [
            ['product_id' => $branded->id, 'qty' => 1, 'unit_price' => 80],
            ['product_id' => $bare->id, 'qty' => 2, 'unit_price' => 30],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'brand', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $byLabel = collect($report['rows'])->keyBy('label');

        $this->assertArrayHasKey('No brand', $byLabel->all());
        $this->assertEqualsWithDelta(60.0, $byLabel['No brand']['net'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $byLabel['Acme II']['net'], 0.0001);
        $this->assertEqualsWithDelta(140.0, $report['totals']['net'], 0.0001);
    }

    public function test_row_shares_add_up_to_the_total(): void
    {
        $acme = $this->makeBrand('BR-AC3', 'Acme III');
        $globex = $this->makeBrand('BR-GL3', 'Globex III');

        $a = $this->makeProduct('BBR-S1', 'Share One', null, $acme);
        $g = $this->makeProduct('BBR-S2', 'Share Two', null, $globex);

        $this->makeInvoice($this->fyDay(9), [
            ['product_id' => $a->id, 'qty' => 3, 'unit_price' => 33],
            ['product_id' => $g->id, 'qty' => 1, 'unit_price' => 34],
        ]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'brand', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertEqualsWithDelta(100.0, (float) collect($report['rows'])->sum('share'), 0.05);
        $this->assertEqualsWithDelta(
            $report['totals']['net'],
            (float) collect($report['rows'])->sum('net'),
            0.0001,
        );
    }

    public function test_brand_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Brand Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-brand'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Brand Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-brand'))
            ->assertOk();
    }
}
