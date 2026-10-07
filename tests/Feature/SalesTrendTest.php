<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Inventory\Product;
use App\Domain\Reporting\TrendQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-119 Sales Trend: GET /app/reports/sales/trend serves a daily revenue
 * series materialized from invoices into bi_metrics_daily, with a
 * least-squares direction behind a min-sample gate (method/sample exposed).
 */
class SalesTrendTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
        $this->product = $this->makeProduct('BTR-1', 'Trend Product');
    }

    private function invoicesOn(string $day, int $qty): void
    {
        $this->makeInvoice($day, [
            ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 100],
        ]);
    }

    public function test_series_equals_daily_invoice_revenue(): void
    {
        foreach (range(5, 11) as $offset) {
            $this->invoicesOn($this->fyDay($offset), $offset - 4); // 100, 200 … 700
        }

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertCount(20, $report['series']);

        $day5 = $report['series']->firstWhere('date', $this->fyDay(5));
        $day11 = $report['series']->firstWhere('date', $this->fyDay(11));
        $day15 = $report['series']->firstWhere('date', $this->fyDay(15));

        $this->assertEqualsWithDelta(100.0, $day5['revenue'], 0.0001);
        $this->assertSame(1, $day5['invoices']);
        $this->assertEqualsWithDelta(700.0, $day11['revenue'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $day15['revenue'], 0.0001);
        $this->assertSame(0, $day15['invoices']);

        $this->assertSame(20, $report['totals']['days']);
        $this->assertSame(7, $report['totals']['observation_days']);
        $this->assertSame(7, $report['totals']['invoices']);
        $this->assertEqualsWithDelta(2800.0, $report['totals']['revenue'], 0.0001);

        $trend = $report['trend'];
        $this->assertSame('ok', $trend['status']);
        $this->assertSame('least_squares', $trend['method']);
        $this->assertSame(7, $trend['min_sample']);
        $this->assertSame(7, $trend['sample']);
        $this->assertSame(20, $trend['points']);
        // A short rise followed by 9 quiet days really does slope down over
        // the whole window — the honest reading, not a popularity claim.
        $this->assertSame('down', $trend['direction']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.trend', $window))
            ->assertOk()
            ->assertSee('Sales Trend')
            ->assertSee('least_squares')
            ->assertSee('Downward')
            ->assertSee('2,800.00');
    }

    public function test_sustained_growth_reports_an_upward_direction(): void
    {
        $window = ['date_from' => $this->fyDay(5), 'date_to' => $this->fyDay(11)];
        foreach (range(5, 11) as $offset) {
            $this->invoicesOn($this->fyDay($offset), $offset - 4); // 100, 200 … 700
        }

        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $trend = $report['trend'];
        $this->assertSame('ok', $trend['status']);
        $this->assertSame(7, $trend['sample']);
        $this->assertSame('up', $trend['direction']);
        $this->assertGreaterThan(0, $trend['slope']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.trend', $window))
            ->assertOk()
            ->assertSee('Upward');
    }

    public function test_daily_totals_materialize_into_bi_metrics_daily(): void
    {
        $this->invoicesOn($this->fyDay(5), 2);
        $this->invoicesOn($this->fyDay(6), 3);
        $this->invoicesOn($this->fyDay(6), 1);

        app(TrendQuery::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $revenue = DB::table('bi_metrics_daily')
            ->where('company_id', $this->admin->company_id)
            ->where('metric', 'invoice_revenue')
            ->where('metric_date', $this->fyDay(5))
            ->first();
        $this->assertNotNull($revenue);
        $this->assertNull($revenue->branch_id);
        $this->assertEqualsWithDelta(200.0, (float) $revenue->value, 0.0001);

        $count6 = DB::table('bi_metrics_daily')
            ->where('company_id', $this->admin->company_id)
            ->where('metric', 'invoice_count')
            ->where('metric_date', $this->fyDay(6))
            ->first();
        $this->assertNotNull($count6);
        $this->assertEqualsWithDelta(2.0, (float) $count6->value, 0.0001);

        $this->assertSame(
            4,
            DB::table('bi_metrics_daily')->where('company_id', $this->admin->company_id)->count(),
        );
    }

    public function test_window_filters_the_series_and_the_materialized_rows(): void
    {
        $this->invoicesOn($this->fyDay(5), 4);
        $this->invoicesOn($this->fyDay(30), 9);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertCount(20, $report['series']);
        $this->assertSame(1, $report['totals']['observation_days']);
        $this->assertEqualsWithDelta(400.0, $report['totals']['revenue'], 0.0001);

        $this->assertDatabaseMissing('bi_metrics_daily', [
            'company_id' => $this->admin->company_id,
            'metric' => 'invoice_revenue',
            'metric_date' => $this->fyDay(30),
        ]);
    }

    public function test_falling_series_reports_a_downward_direction(): void
    {
        foreach (range(5, 11) as $offset) {
            $this->invoicesOn($this->fyDay($offset), 12 - $offset); // 700 … 100
        }

        $report = app(TrendQuery::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame('ok', $report['trend']['status']);
        $this->assertSame('down', $report['trend']['direction']);
        $this->assertLessThan(0, $report['trend']['slope']);
    }

    public function test_flat_series_reports_a_flat_direction(): void
    {
        // Every point in the window carries the same revenue: a real flat
        // series (a plateau surrounded by zero-days would rightly slope down).
        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(7)];
        foreach (range(1, 7) as $offset) {
            $this->invoicesOn($this->fyDay($offset), 3);
        }

        $report = app(TrendQuery::class)->forCompany($this->admin->company_id, $window);

        $this->assertSame('ok', $report['trend']['status']);
        $this->assertSame(7, $report['trend']['sample']);
        $this->assertSame('flat', $report['trend']['direction']);
        $this->assertEqualsWithDelta(0.0, $report['trend']['slope'], 0.000001);
    }

    public function test_trend_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Trend Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.trend'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Trend Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.trend'))
            ->assertOk();
    }
}
