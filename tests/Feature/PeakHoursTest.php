<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\PeakHoursQuery;
use App\Domain\Sales\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-118 Peak Hours Analysis: GET /app/reports/sales/peak-hours renders an
 * hour-of-day histogram of invoice creation timestamps, always with the
 * period and the sample size (BI honesty: zeros are real gaps, ties resolve
 * to the earliest hour, no peak without a sample).
 */
class PeakHoursTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    private function invoiceAt(string $invoiceDate, string $createdAt, bool $issue = true): Invoice
    {
        $product = $this->makeProduct('BPH-'.uniqid(), 'Peak Product '.$invoiceDate);

        $invoice = $this->makeInvoice($invoiceDate, [
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 100],
        ], $issue);

        $invoice->forceFill(['created_at' => $createdAt])->save();

        return $invoice->refresh();
    }

    public function test_histogram_counts_invoices_by_creation_hour(): void
    {
        $this->invoiceAt($this->fyDay(5), $this->fyDay(5).' 10:30:00');
        $this->invoiceAt($this->fyDay(6), $this->fyDay(6).' 10:05:00');
        $this->invoiceAt($this->fyDay(7), $this->fyDay(7).' 14:20:00');

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(PeakHoursQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertCount(24, $report['buckets']);
        $this->assertSame(3, $report['totals']['invoices']);

        $hour10 = $report['buckets']->firstWhere('hour', 10);
        $hour14 = $report['buckets']->firstWhere('hour', 14);

        $this->assertSame(2, $hour10['count']);
        $this->assertEqualsWithDelta(66.67, $hour10['share'], 0.01);
        $this->assertSame(1, $hour14['count']);
        $this->assertSame(0, $report['buckets']->firstWhere('hour', 3)['count']);

        $this->assertSame(10, $report['peak']['hour']);
        $this->assertSame(2, $report['peak']['count']);
        $this->assertSame('Asia/Dhaka', $report['filters']['timezone']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.peak-hours', $window))
            ->assertOk()
            ->assertSee('Peak Hours Analysis')
            ->assertSee('10:00–11:00')
            ->assertSee('3 invoices')
            ->assertSee('· peak');
    }

    public function test_window_filters_by_creation_timestamp(): void
    {
        $this->invoiceAt($this->fyDay(5), $this->fyDay(5).' 11:00:00');
        $this->invoiceAt($this->fyDay(8), $this->fyDay(30).' 11:00:00');

        $report = app(PeakHoursQuery::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['invoices']);
        $this->assertSame(1, $report['buckets']->firstWhere('hour', 11)['count']);
        $this->assertSame(11, $report['peak']['hour']);
    }

    public function test_draft_and_void_invoices_are_excluded(): void
    {
        $draft = $this->invoiceAt($this->fyDay(5), $this->fyDay(5).' 09:00:00', issue: false);
        $this->assertSame('draft', $draft->status);

        $void = $this->invoiceAt($this->fyDay(6), $this->fyDay(6).' 09:30:00');
        $void->forceFill(['status' => 'void'])->save();

        $this->invoiceAt($this->fyDay(7), $this->fyDay(7).' 09:15:00');

        $report = app(PeakHoursQuery::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(1, $report['totals']['invoices']);
        $this->assertSame(1, $report['buckets']->firstWhere('hour', 9)['count']);
    }

    public function test_ties_resolve_to_the_earliest_peak_hour(): void
    {
        $this->invoiceAt($this->fyDay(5), $this->fyDay(5).' 16:40:00');
        $this->invoiceAt($this->fyDay(6), $this->fyDay(6).' 16:10:00');
        $this->invoiceAt($this->fyDay(7), $this->fyDay(7).' 09:30:00');
        $this->invoiceAt($this->fyDay(8), $this->fyDay(8).' 09:05:00');

        $report = app(PeakHoursQuery::class)->forCompany($this->admin->company_id, [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertSame(4, $report['totals']['invoices']);
        $this->assertSame(9, $report['peak']['hour']);
        $this->assertSame(2, $report['peak']['count']);
    }

    public function test_empty_window_reports_zero_sample_without_a_peak(): void
    {
        $this->invoiceAt($this->fyDay(5), $this->fyDay(5).' 10:00:00');

        $window = ['date_from' => $this->fyDay(40), 'date_to' => $this->fyDay(60)];
        $report = app(PeakHoursQuery::class)
            ->forCompany($this->admin->company_id, $window);

        $this->assertSame(0, $report['totals']['invoices']);
        $this->assertNull($report['peak']);
        $this->assertCount(24, $report['buckets']);
        $this->assertSame(0, $report['buckets']->sum('count'));
        $this->assertSame(0.0, $report['buckets']->first()['share']);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.peak-hours', $window))
            ->assertOk()
            ->assertSee('no peak is claimed');
    }

    public function test_peak_hours_route_requires_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Peak Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.peak-hours'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Peak Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.peak-hours'))
            ->assertOk();
    }
}
