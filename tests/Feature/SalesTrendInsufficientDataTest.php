<?php

namespace Tests\Feature;

use App\Domain\Inventory\Product;
use App\Domain\Reporting\TrendQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-119 honesty gate: fewer than 7 observation days yields an
 * "insufficient" state — no slope, no direction — while the method and
 * the threshold stay exposed.
 */
class SalesTrendInsufficientDataTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
        $this->product = $this->makeProduct('BTR-2', 'Sparse Trend Product');
    }

    private function invoicesOn(string $day, int $qty): void
    {
        $this->makeInvoice($day, [
            ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 100],
        ]);
    }

    public function test_short_window_claims_no_trend(): void
    {
        foreach (range(1, 5) as $offset) {
            $this->invoicesOn($this->fyDay($offset), 1);
        }

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(5)];
        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $trend = $report['trend'];
        $this->assertSame('insufficient', $trend['status']);
        $this->assertNull($trend['slope']);
        $this->assertNull($trend['direction']);
        $this->assertSame(5, $trend['sample']);
        $this->assertStringContainsString('5 observation day(s) of 7 required', $trend['reason']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.trend', $window))
            ->assertOk()
            ->assertSee('Not enough data')
            ->assertSee('No trend direction is claimed');
    }

    public function test_sparse_history_claims_no_trend(): void
    {
        $this->invoicesOn($this->fyDay(2), 5);
        $this->invoicesOn($this->fyDay(9), 5);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertSame('insufficient', $report['trend']['status']);
        $this->assertSame(2, $report['trend']['sample']);
        $this->assertSame(2, $report['totals']['observation_days']);
        $this->assertNull($report['trend']['direction']);
    }

    public function test_empty_window_reports_zeros_without_a_trend(): void
    {
        $window = ['date_from' => $this->fyDay(40), 'date_to' => $this->fyDay(54)];
        $report = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertCount(15, $report['series']);
        $this->assertSame(0, $report['totals']['observation_days']);
        $this->assertSame(0, $report['totals']['invoices']);
        $this->assertSame(0.0, (float) $report['totals']['revenue']);
        $this->assertSame('insufficient', $report['trend']['status']);
        $this->assertSame(0, $report['trend']['sample']);
        $this->assertNull($report['trend']['direction']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.trend', $window))
            ->assertOk()
            ->assertSee('Not enough data');
    }

    public function test_method_and_threshold_stay_exposed_when_insufficient(): void
    {
        $this->invoicesOn($this->fyDay(3), 2);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(10)];
        $trend = app(TrendQuery::class)
            ->forCompany($this->admin->company_id, $window)['trend'];

        $this->assertSame('least_squares', $trend['method']);
        $this->assertSame(7, $trend['min_sample']);
        $this->assertSame(1, $trend['sample']);
        $this->assertSame(10, $trend['points']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.trend', $window))
            ->assertOk()
            ->assertSee('least_squares')
            ->assertSee('min sample');
    }
}
