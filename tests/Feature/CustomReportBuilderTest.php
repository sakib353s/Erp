<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\CustomReportBuilder;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportRun;
use App\Domain\Reporting\ScheduledReport;
use App\Domain\Reporting\ScheduledReportService;
use App\Domain\Sales\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-120 Custom Sales Report: whitelist-driven builder, DB-driven
 * definitions, saved filters, schedules + audited runs, run history.
 */
class CustomReportBuilderTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Invoice $first;

    protected Invoice $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();

        $product = $this->makeProduct('CCR-1', 'Custom Report Product');

        $this->first = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $this->second = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
    }

    private function makeDefinition(array $overrides = []): ReportDefinition
    {
        return ReportDefinition::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'open-invoices',
            'name' => 'Open invoices',
            'source' => 'invoices',
            'columns' => ['invoice_no', 'invoice_date', 'grand_total'],
            'filters' => [],
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_run_returns_whitelisted_columns_equal_to_source(): void
    {
        $result = app(CustomReportBuilder::class)->run(
            $this->admin,
            'invoices',
            ['invoice_no', 'invoice_date', 'grand_total'],
            ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(10)],
        );

        $this->assertSame(2, $result['row_count']);
        $this->assertSame(2, $result['returned']);
        $this->assertFalse($result['truncated']);

        $invoiceNos = array_column($result['rows'], 'invoice_no');
        $this->assertContains($this->first->invoice_no, $invoiceNos);
        $this->assertContains($this->second->invoice_no, $invoiceNos);

        $firstRow = collect($result['rows'])->firstWhere('invoice_no', $this->first->invoice_no);
        $this->assertEqualsWithDelta(200.0, (float) $firstRow->grand_total, 0.0001);

        $this->actingAs($this->admin)
            ->post(route('sales.reports.custom.run'), [
                'source' => 'invoices',
                'columns' => ['invoice_no', 'grand_total'],
                'filters' => [
                    'date_from' => $this->fyDay(1),
                    'date_to' => $this->fyDay(10),
                ],
            ])
            ->assertOk()
            ->assertSee($this->first->invoice_no)
            ->assertSee('2 matching row(s)');
    }

    public function test_run_filters_by_date_window(): void
    {
        $result = app(CustomReportBuilder::class)->run(
            $this->admin,
            'invoices',
            ['invoice_no'],
            ['date_from' => $this->fyDay(5), 'date_to' => $this->fyDay(5)],
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertSame($this->first->invoice_no, $result['rows'][0]->invoice_no);
    }

    public function test_run_rejects_unknown_column_and_source(): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.reports.custom.run'), [
                'source' => 'invoices',
                'columns' => ['password'],
            ])
            ->assertSessionHasErrors('columns');

        $this->actingAs($this->admin)
            ->post(route('sales.reports.custom.run'), [
                'source' => 'employees',
                'columns' => ['invoice_no'],
            ])
            ->assertSessionHasErrors('source');
    }

    public function test_definitions_persist_only_whitelisted_columns_and_run(): void
    {
        $this->actingAs($this->admin);

        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.definitions'), [
                'code' => 'ar-open',
                'name' => 'Open AR',
                'source' => 'invoices',
                'columns' => ['invoice_no', 'due_amount'],
                'filters' => ['status' => 'issued'],
            ])
            ->assertRedirect(route('sales.reports.custom'));

        $definition = ReportDefinition::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'ar-open')
            ->firstOrFail();
        $this->assertSame(['invoice_no', 'due_amount'], $definition->columns);
        $this->assertSame(['status' => 'issued'], $definition->filters);

        $this->actingAs($this->admin)
            ->post(route('sales.reports.custom.runs'), ['definition_id' => $definition->id])
            ->assertRedirect();

        $run = ReportRun::query()->where('report_definition_id', $definition->id)->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->row_count);
        $this->assertNotNull($run->snapshot);
        $this->assertSame('invoice_no', $run->snapshot['columns'][0]['key']);

        // A column outside the whitelist is rejected server-side.
        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.definitions'), [
                'code' => 'evil-report',
                'name' => 'Evil',
                'source' => 'invoices',
                'columns' => ['secret'],
            ])
            ->assertSessionHasErrors('columns');

        $this->assertDatabaseMissing('report_definitions', ['code' => 'evil-report']);
    }

    public function test_saved_filters_validate_against_the_whitelist_and_apply(): void
    {
        $this->actingAs($this->admin);

        $definition = $this->makeDefinition();

        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.saved-filters'), [
                'definition_id' => $definition->id,
                'name' => 'Day five only',
                'payload' => [
                    'date_from' => $this->fyDay(5),
                    'date_to' => $this->fyDay(5),
                ],
            ])
            ->assertRedirect(route('sales.reports.custom'));

        $saved = $definition->savedFilters()->where('name', 'Day five only')->firstOrFail();
        $this->assertSame($this->fyDay(5), $saved->payload['date_from']);

        $result = app(CustomReportBuilder::class)->run(
            $this->admin,
            $definition->source,
            $definition->columns,
            $saved->payload,
        );
        $this->assertSame(1, $result['row_count']);

        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.saved-filters'), [
                'definition_id' => $definition->id,
                'name' => 'Bad filter',
                'payload' => ['raw_sql' => '1=1'],
            ])
            ->assertSessionHasErrors('payload');
    }

    public function test_schedule_runs_due_with_audit_and_advances_next_run(): void
    {
        $this->actingAs($this->admin);

        $definition = $this->makeDefinition(['code' => 'scheduled-ar']);

        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.schedules'), [
                'definition_id' => $definition->id,
                'name' => 'Daily AR',
                'frequency' => 'daily',
            ])
            ->assertRedirect(route('sales.reports.custom'));

        $schedule = ScheduledReport::query()
            ->where('report_definition_id', $definition->id)
            ->firstOrFail();
        $this->assertSame('daily', $schedule->frequency);
        $this->assertTrue($schedule->is_active);
        $this->assertNotNull($schedule->next_run_at);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'sales.report_scheduled')
                ->where('entity_type', 'scheduled_report')
                ->where('entity_id', $schedule->id)
                ->exists(),
        );

        $executed = app(ScheduledReportService::class)->runDue();
        $this->assertSame(1, $executed);

        $run = ReportRun::query()->where('scheduled_report_id', $schedule->id)->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->row_count);
        $this->assertSame($this->admin->id, $run->triggered_by);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'sales.report_run')
                ->where('entity_type', 'report_run')
                ->where('entity_id', $run->id)
                ->exists(),
        );

        $schedule->refresh();
        $this->assertNotNull($schedule->last_run_at);
        $this->assertTrue($schedule->next_run_at->isFuture());

        // The advanced schedule is not due again immediately.
        $this->assertSame(0, app(ScheduledReportService::class)->runDue());

        $this->from(route('sales.reports.custom'))
            ->post(route('sales.reports.custom.schedules'), [
                'definition_id' => $definition->id,
                'name' => 'Bad cadence',
                'frequency' => 'hourly',
            ])
            ->assertSessionHasErrors('frequency');
    }

    public function test_history_and_scope_note_render_on_the_builder_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.reports.custom'))
            ->assertOk()
            ->assertSee('Custom Sales Report')
            ->assertSee('Build & run')
            ->assertSee('your scope: all branches')
            ->assertSee('Saved report definitions')
            ->assertSee('Recent runs');
    }

    public function test_routes_require_the_reports_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'No Reports Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('sales.reports.custom'))->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.reports.custom.run'), ['source' => 'invoices', 'columns' => ['invoice_no']])
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.reports.custom.schedules'), ['definition_id' => 1, 'name' => 'x', 'frequency' => 'daily'])
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Report Viewer']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.reports.view'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('sales.reports.custom'))->assertOk();
    }
}
