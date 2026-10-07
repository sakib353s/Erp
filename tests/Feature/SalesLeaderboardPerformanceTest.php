<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\People\Employee;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SetSalesTarget;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Queries\LeaderboardQuery;
use App\Domain\Sales\Queries\SalesPerformanceQuery;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Sales leaderboard + performance (02-82 / 02-83): real invoice
 * aggregates, target variance, honest field-visit unavailability,
 * permission gates, no fake rows.
 */
class SalesLeaderboardPerformanceTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Employee $employee;

    protected Employee $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->employee = $this->makeEmployee('SP-LB1', 'Sadia', 'Rahman', true);
        $this->second = $this->makeEmployee('SP-LB2', 'Nafis', 'Ahmed', true);

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'LB-1',
            'sku' => 'LB-SKU-1',
            'name' => 'Leaderboard Product',
            'cost_method' => 'fifo',
            'standard_cost' => 60,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 500, 'unit_cost' => 50],
            ],
            'idempotency_suffix' => 'lb-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function makeEmployee(string $code, string $first, string $last, bool $isSales): Employee
    {
        return Employee::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $first.' '.$last,
            'designation' => 'Sales Executive',
            'employment_status' => 'active',
            'status' => 'active',
            'is_salesperson' => $isSales,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__lb-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function issueInvoiceFor(Employee $salesPerson, int $qty, float $unitPrice): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'sales_person_id' => $salesPerson->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => $unitPrice],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        return $invoice->fresh();
    }

    public function test_leaderboard_ranks_by_attributed_revenue(): void
    {
        // Sadia: 5×100 = 500; Nafis: 10×100 = 1000 → Nafis rank 1
        $this->issueInvoiceFor($this->employee, 5, 100);
        $this->issueInvoiceFor($this->second, 10, 100);

        $report = app(LeaderboardQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
            now()->toDateString(),
        );

        $this->assertSame(2, $report['rows']->count());
        $this->assertSame(2, $report['totals']['invoice_count']); // one invoice each
        $this->assertEquals(1500.0, $report['totals']['revenue']);
        $this->assertSame(2, $report['sample_size']);

        $top = $report['rows'][0];
        $this->assertSame(1, $top['rank']);
        $this->assertSame($this->second->id, $top['employee']->id);
        $this->assertEquals(1000.0, $top['revenue']);

        $secondRow = $report['rows'][1];
        $this->assertSame(2, $secondRow['rank']);
        $this->assertSame($this->employee->id, $secondRow['employee']->id);
        $this->assertEquals(500.0, $secondRow['revenue']);
    }

    public function test_performance_combines_target_actual_and_honest_field_visit_state(): void
    {
        app(SetSalesTarget::class)->handle([
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
            'target_amount' => 1000,
            'at' => now()->toDateString(),
        ], $this->httpRequest());

        $this->issueInvoiceFor($this->employee, 5, 100); // 500 actual

        $report = app(SalesPerformanceQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
            now()->toDateString(),
        );

        $this->assertTrue($report['field_visits_available']);
        $this->assertStringContainsString('field_visits', $report['field_visits_note']);
        $this->assertSame(2, $report['rows']->count()); // both flagged salespersons

        $withTarget = $report['rows']->firstWhere('employee.id', $this->employee->id);
        $this->assertTrue($withTarget['has_target']);
        $this->assertEquals(1000.0, $withTarget['target_amount']);
        $this->assertEquals(500.0, $withTarget['revenue']);
        $this->assertEquals(-500.0, $withTarget['variance']);
        $this->assertEquals(50.0, $withTarget['pct']);
        $this->assertSame(1, $withTarget['invoice_count']); // one issued invoice

        $withoutTarget = $report['rows']->firstWhere('employee.id', $this->second->id);
        $this->assertFalse($withoutTarget['has_target']);
        $this->assertEquals(0.0, $withoutTarget['target_amount']);
        $this->assertNull($withoutTarget['pct']);
    }

    public function test_leaderboard_and_performance_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/team/leaderboard')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/performance')->assertForbidden();

        $full = $this->roleWith(['portal.erp.access', 'sales.team.view']);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->issueInvoiceFor($this->employee, 5, 100);

        $this->actingAs($user)
            ->get('/app/sales/team/leaderboard?period_type=monthly&at='.now()->toDateString())
            ->assertOk()
            ->assertSee('Sales Leaderboard')
            ->assertSee('Sadia Rahman')
            ->assertSee('500.00');

        $this->actingAs($user)
            ->get('/app/sales/team/performance?period_type=monthly&at='.now()->toDateString())
            ->assertOk()
            ->assertSee('Sales Performance')
            ->assertSee('Sadia Rahman')
            ->assertSee('500.00')
            ->assertSee('field_visits');
    }

    public function test_empty_period_shows_honest_empty_state(): void
    {
        // Flag no one with sales; empty future period
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'sales.team.view']);
        $user->roles()->attach($role->id);
        app(PermissionCatalog::class)->invalidate($user);

        // Unflag salespersons so roster is empty when no sales exist
        Employee::query()->update(['is_salesperson' => false]);

        $future = now()->addYears(3)->toDateString();
        $report = app(LeaderboardQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
            $future,
        );
        $this->assertSame(0, $report['rows']->count());
        $this->assertSame(0, $report['totals']['invoice_count']);

        $this->actingAs($user)
            ->get('/app/sales/team/leaderboard?period_type=monthly&at='.$future)
            ->assertOk()
            ->assertSee('no fake data');
    }

    public function test_no_fake_structural_rows_for_queries(): void
    {
        // Structure only: no invoices attributed yet
        $report = app(LeaderboardQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
            now()->toDateString(),
        );
        $this->assertSame(0, $report['totals']['invoice_count']);
        $this->assertEquals(0.0, $report['totals']['revenue']);
        $this->assertSame(0, Invoice::query()->whereNotNull('sales_person_id')->count());
    }
}
