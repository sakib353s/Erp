<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\District;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-117 Sales by Area/Zone: GET /app/reports/sales/by-zone maps every
 * invoice's customer district through delivery_zone_district into the
 * delivery zone rows (multi-zone districts claim the lowest zone id).
 */
class SalesByZoneTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    public function test_zone_rows_group_invoices_by_the_customer_district_zone(): void
    {
        [$dhaka, $chattogram] = District::query()->orderBy('id')->take(2)->get();

        $zoneA = $this->makeZone('BZA', 'Banani Zone', [$dhaka->id]);
        $zoneB = $this->makeZone('BZB', 'Agrabad Zone', [$chattogram->id]);

        $customerA = $this->makeCustomer('BZA-1', 'Zone Customer A', $dhaka->id);
        $customerB = $this->makeCustomer('BZB-1', 'Zone Customer B', $chattogram->id);

        $product = $this->makeProduct('BPZ-1', 'Zone Dim Product');

        $first = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $this->tagInvoice($first, ['customer_id' => $customerA->id]);

        $second = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100],
        ]);
        $this->tagInvoice($second, ['customer_id' => $customerB->id]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'zone', $window);

        $this->assertSame('invoices', $report['source']);

        $rowA = collect($report['rows'])->firstWhere('label', 'Banani Zone');
        $rowB = collect($report['rows'])->firstWhere('label', 'Agrabad Zone');

        $this->assertSame($zoneA->id, $rowA['key']);
        $this->assertSame($zoneB->id, $rowB['key']);
        $this->assertEqualsWithDelta(300.0, $rowA['gross'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $rowB['gross'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $report['totals']['gross'], 0.0001);
        $this->assertSame(2, $report['totals']['invoices']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-zone', $window))
            ->assertOk()
            ->assertSee('Sales by Area/Zone')
            ->assertSee('Banani Zone')
            ->assertSee('Agrabad Zone')
            ->assertSee('400.00');
    }

    public function test_customers_without_a_zoned_district_land_in_the_no_zone_bucket(): void
    {
        $unzonedDistrict = District::query()->orderByDesc('id')->first();
        $noDistrict = $this->makeCustomer('BZU-1', 'Districtless Customer');

        $zonedCustomer = $this->makeCustomer('BZU-2', 'Unzoned District Customer', $unzonedDistrict->id);

        $product = $this->makeProduct('BPZ-2', 'Zone Ungrouped Product');

        $first = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->tagInvoice($first, ['customer_id' => $noDistrict->id]);

        $second = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100],
        ]);
        $this->tagInvoice($second, ['customer_id' => $zonedCustomer->id]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'zone', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('No zone', $report['rows']->first()['label']);
        $this->assertEqualsWithDelta(300.0, $report['totals']['gross'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-zone', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(20),
            ]))
            ->assertOk()
            ->assertSee('No zone');
    }

    public function test_window_filter_excludes_invoices_outside_the_dates(): void
    {
        [$dhaka] = District::query()->orderBy('id')->take(1)->get();
        $zone = $this->makeZone('BZC', 'Window Zone', [$dhaka->id]);
        $customer = $this->makeCustomer('BZC-1', 'Window Zone Customer', $dhaka->id);
        $product = $this->makeProduct('BPZ-3', 'Zone Windowed Product');

        $inside = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->tagInvoice($inside, ['customer_id' => $customer->id]);

        $outside = $this->makeInvoice($this->fyDay(30), [
            ['product_id' => $product->id, 'qty' => 10, 'unit_price' => 50],
        ]);
        $this->tagInvoice($outside, ['customer_id' => $customer->id]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'zone', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($zone->id, $report['rows']->first()['key']);
        $this->assertEqualsWithDelta(200.0, $report['totals']['gross'], 0.0001);
    }

    public function test_a_district_in_several_zones_counts_once_at_the_lowest_zone(): void
    {
        [$shared] = District::query()->orderBy('id')->take(1)->get();

        $low = $this->makeZone('BZL', 'Low Zone', [$shared->id]);
        $high = $this->makeZone('BZH', 'High Zone', [$shared->id]);
        $this->assertLessThan($high->id, $low->id);

        $customer = $this->makeCustomer('BZM-1', 'Multi Zone Customer', $shared->id);
        $product = $this->makeProduct('BPZ-4', 'Zone Multi Product');

        $invoice = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $this->tagInvoice($invoice, ['customer_id' => $customer->id]);

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'zone', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertCount(1, $report['rows']);
        $this->assertSame($low->id, $report['rows']->first()['key']);
        $this->assertSame('Low Zone', $report['rows']->first()['label']);
        $this->assertEqualsWithDelta(300.0, $report['totals']['gross'], 0.0001);
        $this->assertSame(1, $report['totals']['invoices']);
    }

    public function test_by_zone_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Zone Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-zone'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Zone Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-zone'))
            ->assertOk();
    }
}
