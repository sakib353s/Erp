<?php

namespace Tests\Feature;

use App\Domain\Reporting\CustomReportBuilder;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-120 scope enforcement: company + the running user's branch scope
 * apply on EVERY generated query — stored definitions, saved filters and
 * explicit column/filter choices can never widen what a user may read
 * (Rule 5 / D6).
 */
class CustomReportScopeEnforcementTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    /** A shadow company_id for isolation tests (never a real tenant). */
    private function shadowCompanyId(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Reports Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_company_scope_excludes_foreign_rows(): void
    {
        $product = $this->makeProduct('CSE-1', 'Scope Product');
        $invoice = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);

        $shadowId = $this->shadowCompanyId();
        DB::table('invoices')->insert([
            'company_id' => $shadowId,
            'document_type_id' => $invoice->document_type_id,
            'invoice_no' => 'SHADOW-INV-99',
            'invoice_date' => $this->fyDay(5),
            'status' => 'issued',
            'grand_total' => 999,
        ]);

        $result = app(CustomReportBuilder::class)->run(
            $this->admin,
            'invoices',
            ['invoice_no'],
            ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(10)],
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertSame($invoice->invoice_no, $result['rows'][0]->invoice_no);
        $this->assertNotContains(
            'SHADOW-INV-99',
            array_column($result['rows'], 'invoice_no'),
        );
    }

    public function test_branch_scoped_user_sees_only_accessible_branch_rows(): void
    {
        $depot = $this->makeExtraBranch('CSE2', 'Scope Depot');
        $product = $this->makeProduct('CSE-2', 'Branch Scope Product');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 5, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $scoped = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Scoped Reporter']);

        $scopedResult = app(CustomReportBuilder::class)->run(
            $scoped,
            'invoices',
            ['invoice_no', 'branch_id'],
        );
        $this->assertSame(1, $scopedResult['row_count']);
        $this->assertNotContains(
            $depotInvoice->invoice_no,
            array_column($scopedResult['rows'], 'invoice_no'),
        );

        $allResult = app(CustomReportBuilder::class)->run(
            $this->admin,
            'invoices',
            ['invoice_no', 'branch_id'],
        );
        $this->assertSame(2, $allResult['row_count']);
        $this->assertContains(
            $depotInvoice->invoice_no,
            array_column($allResult['rows'], 'invoice_no'),
        );
    }

    public function test_explicit_branch_filter_cannot_reach_another_branch(): void
    {
        $depot = $this->makeExtraBranch('CSE3', 'Blocked Depot');
        $product = $this->makeProduct('CSE-3', 'Blocked Scope Product');

        $depotInvoice = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 4, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $scoped = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Blocked Reporter']);

        $result = app(CustomReportBuilder::class)->run(
            $scoped,
            'invoices',
            ['invoice_no', 'branch_id'],
            ['branch_id' => $depot->id],
        );

        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['rows']);
    }

    public function test_invoice_lines_scope_applies_through_the_invoice_join(): void
    {
        $depot = $this->makeExtraBranch('CSE4', 'Line Depot');
        $product = $this->makeProduct('CSE-4', 'Line Scope Product');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
            ['product_id' => $product->id, 'qty' => 1, 'unit_price' => 50],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 7, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $scoped = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Line Reporter']);

        $scopedResult = app(CustomReportBuilder::class)->run(
            $scoped,
            'invoice_lines',
            ['line_no', 'qty'],
        );
        // 2 lines from the HQ invoice only — the depot line never leaks.
        $this->assertSame(2, $scopedResult['row_count']);
        $qtys = array_map(fn ($row) => (float) $row->qty, $scopedResult['rows']);
        $this->assertNotContains(7.0, $qtys);

        $allResult = app(CustomReportBuilder::class)->run(
            $this->admin,
            'invoice_lines',
            ['line_no', 'qty'],
        );
        $this->assertSame(3, $allResult['row_count']);
    }

    public function test_saved_definition_applies_the_running_users_scope_not_the_creators(): void
    {
        $depot = $this->makeExtraBranch('CSE5', 'Creator Depot');
        $product = $this->makeProduct('CSE-5', 'Creator Scope Product');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 6, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        // Saved by the unrestricted admin (sees every branch).
        $definition = ReportDefinition::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'branch-invoices',
            'name' => 'Branch invoices',
            'source' => 'invoices',
            'columns' => ['invoice_no', 'branch_id'],
            'filters' => [],
            'created_by' => $this->admin->id,
        ]);

        $scoped = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Definition Runner']);

        $result = app(CustomReportBuilder::class)->run(
            $scoped,
            $definition->source,
            $definition->columns,
            $definition->filters,
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertNotContains(
            $depotInvoice->invoice_no,
            array_column($result['rows'], 'invoice_no'),
        );
    }

    public function test_cross_company_definition_cannot_be_run(): void
    {
        $foreign = ReportDefinition::query()->create([
            'company_id' => $this->shadowCompanyId(),
            'code' => 'foreign-report',
            'name' => 'Foreign report',
            'source' => 'invoices',
            'columns' => ['invoice_no'],
            'filters' => [],
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.reports.custom.runs'), ['definition_id' => $foreign->id])
            ->assertNotFound();

        $this->assertSame(0, ReportRun::query()->count());
    }
}
