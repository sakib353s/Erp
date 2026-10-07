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
use App\Domain\Sales\Actions\FlagEmployeeAsSalesPerson;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SetSalesTarget;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesTarget;
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
 * Sales team (02-78…02-80): sales-person flag on employees, period
 * targets, achievement from attributed invoices, permission gates.
 */
class SalesTeamTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Employee $employee;

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

        $this->employee = Employee::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'SP-001',
            'first_name' => 'Sadia',
            'last_name' => 'Rahman',
            'full_name' => 'Sadia Rahman',
            'designation' => 'Sales Executive',
            'employment_status' => 'active',
            'status' => 'active',
            'is_salesperson' => false,
        ]);

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'TEAM-1',
            'sku' => 'TEAM-SKU-1',
            'name' => 'Team Product',
            'cost_method' => 'fifo',
            'standard_cost' => 60,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 50],
            ],
            'idempotency_suffix' => 'team-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__team-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    public function test_flag_employee_as_sales_person(): void
    {
        $this->assertFalse((bool) $this->employee->is_salesperson);

        $fresh = app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());
        $this->assertTrue((bool) $fresh->is_salesperson);
        $this->assertSame(1, Employee::query()->salespersons()->count());

        $back = app(FlagEmployeeAsSalesPerson::class)->handle($fresh, false, $this->httpRequest());
        $this->assertFalse((bool) $back->is_salesperson);
        $this->assertSame(0, Employee::query()->salespersons()->count());
    }

    public function test_set_sales_target_upserts_by_period_window(): void
    {
        $at = '2026-09-15';

        $first = app(SetSalesTarget::class)->handle([
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
            'target_amount' => 100000,
            'at' => $at,
        ], $this->httpRequest());

        $this->assertSame('monthly', $first->period_type);
        $this->assertEquals('2026-09-01', $first->period_start->toDateString());
        $this->assertEquals('2026-09-30', $first->period_end->toDateString());
        $this->assertEquals(100000.0, (float) $first->target_amount);

        $second = app(SetSalesTarget::class)->handle([
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
            'target_amount' => 150000,
            'at' => $at,
        ], $this->httpRequest());

        $this->assertSame($first->id, $second->id); // upsert same window
        $this->assertEquals(150000.0, (float) $second->fresh()->target_amount);
        $this->assertSame(1, SalesTarget::query()->count());

        // Different period type is a separate row
        app(SetSalesTarget::class)->handle([
            'employee_id' => $this->employee->id,
            'period_type' => 'daily',
            'target_amount' => 5000,
            'at' => $at,
        ], $this->httpRequest());
        $this->assertSame(2, SalesTarget::query()->count());

        try {
            app(SetSalesTarget::class)->handle([
                'employee_id' => $this->employee->id,
                'period_type' => 'weekly',
                'target_amount' => 1,
            ], $this->httpRequest());
            $this->fail('Expected period type RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('daily, monthly, or yearly', $e->getMessage());
        }

        try {
            app(SetSalesTarget::class)->handle([
                'employee_id' => 999999,
                'period_type' => 'monthly',
                'target_amount' => 1,
            ], $this->httpRequest());
            $this->fail('Expected employee RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Employee not found', $e->getMessage());
        }
    }

    public function test_target_achievement_computes_from_attributed_invoices(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());

        app(SetSalesTarget::class)->handle([
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
            'target_amount' => 1000,
            'at' => now()->toDateString(),
        ], $this->httpRequest());

        // Issue an invoice attributed to this sales person
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'sales_person_id' => $this->employee->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 100],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $invoice->refresh();
        $this->assertSame($this->employee->id, (int) $invoice->sales_person_id);

        $bounds = SalesTarget::boundsFor('monthly', now()->toDateString());
        $actual = Invoice::query()
            ->where('sales_person_id', $this->employee->id)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereDate('invoice_date', '>=', $bounds['period_start'])
            ->whereDate('invoice_date', '<=', $bounds['period_end'])
            ->sum('grand_total');

        $this->assertEquals(500.0, (float) $actual); // 5 × 100

        $user = $this->makeUser();
        $role = $this->roleWith([
            'portal.erp.access',
            'sales.team.view',
            'sales.team.targets',
            'sales.team.create',
        ]);
        $user->roles()->attach($role->id);
        app(PermissionCatalog::class)->invalidate($user);

        $response = $this->actingAs($user)
            ->get('/app/sales/team/achievement?period_type=monthly&at='.now()->toDateString())
            ->assertOk();

        $response->assertSee('Sadia Rahman');
        $response->assertSee('1,000.00'); // target
        $response->assertSee('500.00');   // actual
        $response->assertSee('50.0%');    // achievement pct
    }

    public function test_sales_team_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/team')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/team', [
            'employee_id' => $this->employee->id,
            'is_salesperson' => 1,
        ])->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/targets')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/team/targets', [
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
            'target_amount' => 10,
        ])->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/achievement')->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.team.view',
            'sales.team.create',
            'sales.team.targets',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/team')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/targets')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/achievement')->assertOk();

        $this->actingAs($user)->post('/app/sales/team', [
            'employee_id' => $this->employee->id,
            'is_salesperson' => 1,
        ])->assertRedirect();
        $this->assertTrue((bool) $this->employee->fresh()->is_salesperson);

        $this->actingAs($user)->post('/app/sales/team/targets', [
            'employee_id' => $this->employee->id,
            'period_type' => 'yearly',
            'target_amount' => 12000,
            'at' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame(1, SalesTarget::query()->count());
    }

    public function test_structural_seeders_ship_no_fake_sales_team_rows(): void
    {
        $this->assertSame(0, Employee::query()->salespersons()->count());
        $this->assertSame(0, SalesTarget::query()->count());
    }
}
