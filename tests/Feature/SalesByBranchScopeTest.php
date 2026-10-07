<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Reporting\SalesBreakdownReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalesBreakdown;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-116 Sales by Branch: GET /app/reports/sales/by-branch groups invoices per
 * branch and enforces branch scope — a scoped user only ever compares the
 * branches they may access (Rule 5 / D6).
 */
class SalesByBranchScopeTest extends TestCase
{
    use BuildsSalesBreakdown;
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalesBreakdown();
    }

    public function test_branch_rows_group_invoices_and_an_all_branch_user_sees_every_branch(): void
    {
        $hq = $this->defaultBranch();
        $depot = $this->makeExtraBranch('QXZ', 'Qux Zulu Depot');
        $product = $this->makeProduct('BQB-1', 'Branch Dim Product');

        $hqInvoice = $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 50],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $window = ['date_from' => $this->fyDay(1), 'date_to' => $this->fyDay(20)];
        $report = app(SalesBreakdownReport::class)
            ->forCompany($this->admin->company_id, 'branch', $window);

        $this->assertSame('invoices', $report['source']);

        $rowHq = collect($report['rows'])->firstWhere('key', $hq->id);
        $rowDepot = collect($report['rows'])->firstWhere('key', $depot->id);

        $this->assertNotNull($rowHq);
        $this->assertNotNull($rowDepot);
        $this->assertSame(1, $rowHq['invoices']);
        $this->assertEqualsWithDelta(300.0, $rowHq['gross'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $rowDepot['gross'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $report['totals']['gross'], 0.0001);

        $this->assertSame($hqInvoice->branch_id, $hq->id);

        $this->actingAs($this->admin)
            ->get(route('sales.reports.by-branch', $window))
            ->assertOk()
            ->assertSee($hq->name)
            ->assertSee('Qux Zulu Depot')
            ->assertSee('400.00');
    }

    public function test_a_scoped_user_only_sees_rows_for_branches_they_may_access(): void
    {
        $hq = $this->defaultBranch();
        $depot = $this->makeExtraBranch('QXY', 'Qux Yankee Depot');
        $product = $this->makeProduct('BQB-2', 'Branch Scoped Product');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 3, 'unit_price' => 100],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 5, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $limited = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Scoped Viewer']);
        $limited->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
            'branches.compare',
        ])->id);
        app(PermissionCatalog::class)->invalidate($limited);

        $this->actingAs($limited)
            ->get(route('sales.reports.by-branch', [
                'date_from' => $this->fyDay(1),
                'date_to' => $this->fyDay(20),
            ]))
            ->assertOk()
            ->assertSee($hq->name)
            ->assertSee('300.00')
            ->assertDontSee('Qux Yankee Depot')
            ->assertDontSee('500.00');

        $report = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'branch', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
            'branch_ids' => $limited->accessibleBranchIds(),
        ]);

        $this->assertSame([$hq->id], collect($report['rows'])->pluck('key')->all());
        $this->assertEqualsWithDelta(300.0, $report['totals']['gross'], 0.0001);
        $this->assertSame(1, $report['totals']['invoices']);
    }

    public function test_branch_scope_totals_exclude_inaccessible_branches(): void
    {
        $depot = $this->makeExtraBranch('QXW', 'Qux Whiskey Depot');
        $product = $this->makeProduct('BQB-3', 'Branch Totals Product');

        $this->makeInvoice($this->fyDay(5), [
            ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 100],
        ]);
        $depotInvoice = $this->makeInvoice($this->fyDay(6), [
            ['product_id' => $product->id, 'qty' => 7, 'unit_price' => 100],
        ]);
        $this->tagInvoice($depotInvoice, ['branch_id' => $depot->id]);

        $limited = $this->makeUser(['branch_scope' => 'assigned', 'name' => 'Scoped Totals']);

        $scoped = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'branch', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
            'branch_ids' => $limited->accessibleBranchIds(),
        ]);
        $unscoped = app(SalesBreakdownReport::class)->forCompany($this->admin->company_id, 'branch', [
            'date_from' => $this->fyDay(1),
            'date_to' => $this->fyDay(20),
        ]);

        $this->assertEqualsWithDelta(200.0, $scoped['totals']['gross'], 0.0001);
        $this->assertSame(1, $scoped['totals']['invoices']);
        $this->assertEqualsWithDelta(900.0, $unscoped['totals']['gross'], 0.0001);
        $this->assertSame(2, $unscoped['totals']['invoices']);
    }

    public function test_by_branch_route_requires_reports_view_and_branch_compare(): void
    {
        $viewOnly = $this->makeUser(['name' => 'Compare Without Branches']);
        $viewOnly->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
        ])->id);
        app(PermissionCatalog::class)->invalidate($viewOnly);

        $this->actingAs($viewOnly)
            ->get(route('sales.reports.by-branch'))
            ->assertForbidden();

        $denied = $this->makeUser(['name' => 'No Branch Report Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.reports.by-branch'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Branch Report Viewer']);
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.reports.view',
            'branches.compare',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.reports.by-branch'))
            ->assertOk();
    }
}
