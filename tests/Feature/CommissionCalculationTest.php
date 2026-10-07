<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\People\Employee;
use App\Domain\Sales\Actions\CommissionCalculator;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\FlagEmployeeAsSalesPerson;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\PayCommission;
use App\Domain\Sales\CommissionCalculation;
use App\Domain\Sales\CommissionPayment;
use App\Domain\Sales\CommissionRule;
use App\Domain\Sales\Invoice;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
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
 * Sales commission (02-81): percent rules from real attributed invoice
 * revenue, accrual GL, payment with optional approval WF, no fake rows.
 */
class CommissionCalculationTest extends TestCase
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
            'code' => 'SP-C1',
            'first_name' => 'Nadia',
            'last_name' => 'Islam',
            'full_name' => 'Nadia Islam',
            'designation' => 'Sales Executive',
            'employment_status' => 'active',
            'status' => 'active',
            'is_salesperson' => false,
        ]);

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'COMM-1',
            'sku' => 'COMM-SKU-1',
            'name' => 'Commission Product',
            'cost_method' => 'fifo',
            'standard_cost' => 40,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 30],
            ],
            'idempotency_suffix' => 'comm-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__commission-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function issueInvoice(float $unitPrice = 200, int $qty = 5): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'sales_person_id' => $this->employee->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => $unitPrice],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        return $invoice->fresh();
    }

    public function test_percent_rule_computes_from_attributed_invoice_revenue(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());
        $this->issueInvoice(200, 5); // 1000

        $rule = app(CommissionCalculator::class)->createRule([
            'employee_id' => $this->employee->id,
            'name' => 'Nadia 5%',
            'rule_type' => 'percent_of_revenue',
            'rate' => 5,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $calc = app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'commission_rule_id' => $rule->id,
            'period_type' => 'monthly',
            'at' => now()->toDateString(),
        ], $this->httpRequest());

        $this->assertSame('accrued', $calc->status);
        $this->assertEquals(1000.0, (float) $calc->base_amount);
        $this->assertEquals(50.0, (float) $calc->commission_amount); // 5% of 1000
        $this->assertNotNull($calc->journal_entry_id);

        // Accrual: Dr 5240 expense 50, Cr 2130 payable 50
        $entry = JournalEntry::query()->findOrFail($calc->journal_entry_id);
        $expense = Account::query()->where('company_id', $this->admin->company_id)->where('code', '5240')->firstOrFail();
        $payable = Account::query()->where('company_id', $this->admin->company_id)->where('code', '2130')->firstOrFail();

        $this->assertEquals(50.0, (float) $entry->lines()->where('account_id', $expense->id)->where('dc', 'debit')->sum('amount'));
        $this->assertEquals(50.0, (float) $entry->lines()->where('account_id', $payable->id)->where('dc', 'credit')->sum('amount'));
        $this->assertEquals(
            (float) $entry->lines()->where('dc', 'debit')->sum('amount'),
            (float) $entry->lines()->where('dc', 'credit')->sum('amount'),
        );

        // Upsert same window recalculates, does not duplicate
        $again = app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'commission_rule_id' => $rule->id,
            'period_type' => 'monthly',
            'at' => now()->toDateString(),
        ], $this->httpRequest());
        $this->assertSame($calc->id, $again->id);
        $this->assertSame(1, CommissionCalculation::query()->count());
    }

    public function test_pay_commission_without_workflow_posts_immediately(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());
        $this->issueInvoice(100, 10); // 1000

        $rule = app(CommissionCalculator::class)->createRule([
            'employee_id' => $this->employee->id,
            'name' => 'Fixed 80',
            'rule_type' => 'fixed_per_period',
            'fixed_amount' => 80,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $calc = app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'commission_rule_id' => $rule->id,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        // No commission payment WF → immediate paid + GL
        $payment = app(PayCommission::class)->handle($calc, [
            'method' => 'cash',
            'idempotency_key' => 'pay-fixed-1',
        ], $this->httpRequest());

        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->journal_entry_id);
        $this->assertSame('paid', $calc->fresh()->status);

        // Settle: Dr 2130 payable, Cr 1110 cash
        $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $payable = Account::query()->where('company_id', $this->admin->company_id)->where('code', '2130')->firstOrFail();
        $cash = Account::query()->where('company_id', $this->admin->company_id)->where('code', '1110')->firstOrFail();

        $this->assertEquals(80.0, (float) $entry->lines()->where('account_id', $payable->id)->where('dc', 'debit')->sum('amount'));
        $this->assertEquals(80.0, (float) $entry->lines()->where('account_id', $cash->id)->where('dc', 'credit')->sum('amount'));
        $this->assertEquals(
            (float) $entry->lines()->where('dc', 'debit')->sum('amount'),
            (float) $entry->lines()->where('dc', 'credit')->sum('amount'),
        );

        // Idempotency: same key returns same payment
        $same = app(PayCommission::class)->handle($calc->fresh(), [
            'method' => 'cash',
            'idempotency_key' => 'pay-fixed-1',
        ], $this->httpRequest());
        $this->assertSame($payment->id, $same->id);
        $this->assertSame(1, CommissionPayment::query()->count());

        // Double pay without idempotency refused
        try {
            app(PayCommission::class)->handle($calc->fresh(), ['method' => 'cash'], $this->httpRequest());
            $this->fail('Expected already paid RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already paid', $e->getMessage());
        }
    }

    public function test_pay_commission_gated_by_workflow_until_approved(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());
        $this->issueInvoice(50, 20); // 1000 → 10%

        $role = $this->roleWith([]);
        $approver = $this->makeUser();
        $approver->roles()->attach($role->id);

        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'commission_payment',
            'action' => 'pay',
            'name' => 'Commission payment approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        $rule = app(CommissionCalculator::class)->createRule([
            'employee_id' => $this->employee->id,
            'name' => 'Nadia 10%',
            'rule_type' => 'percent_of_revenue',
            'rate' => 10,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $calc = app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'commission_rule_id' => $rule->id,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $this->assertEquals(100.0, (float) $calc->commission_amount);

        $payment = app(PayCommission::class)->handle($calc, [
            'method' => 'cash',
            'idempotency_key' => 'wf-pay-1',
        ], $this->httpRequest());

        $this->assertSame('pending_approval', $payment->status);
        $this->assertNotNull($payment->approval_request_id);
        $this->assertNull($payment->journal_entry_id);
        $this->assertSame('pending_approval', $calc->fresh()->status);

        // Cannot finalize until approved
        try {
            app(PayCommission::class)->handle($calc->fresh(), [
                'method' => 'cash',
                'idempotency_key' => 'wf-pay-1',
            ], $this->httpRequest());
            $this->fail('Expected approval incomplete RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('approval is not complete', $e->getMessage());
        }

        $approval = ApprovalRequest::query()->findOrFail($payment->approval_request_id);
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'ok');

        $final = app(PayCommission::class)->handle($calc->fresh(), [
            'method' => 'cash',
            'idempotency_key' => 'wf-pay-1',
        ], $this->httpRequest());

        $this->assertSame('paid', $final->status);
        $this->assertNotNull($final->journal_entry_id);
        $this->assertSame('paid', $calc->fresh()->status);
        $this->assertEquals(
            100.0,
            (float) JournalEntry::query()->findOrFail($final->journal_entry_id)->lines()->where('dc', 'debit')->sum('amount'),
        );
    }

    public function test_commission_routes_enforce_permissions_and_no_fake_rows(): void
    {
        $this->assertSame(0, CommissionRule::query()->count());
        $this->assertSame(0, CommissionCalculation::query()->count());
        $this->assertSame(0, CommissionPayment::query()->count());

        $user = $this->makeUser();
        $portalOnly = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($portalOnly->id);

        $this->actingAs($user)->get('/app/sales/team/commissions')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/team/commission-rules')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/team/commissions/calculate', [
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
        ])->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.team.view',
            'sales.team.commissions',
            'sales.team.commission_pay',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/team/commissions')->assertOk();
        $this->actingAs($user)->get('/app/sales/team/commission-rules')->assertOk();

        $this->actingAs($user)->post('/app/sales/team/commission-rules', [
            'name' => 'Team 3%',
            'rule_type' => 'percent_of_revenue',
            'rate' => 3,
            'period_type' => 'monthly',
        ])->assertRedirect();
        $this->assertSame(1, CommissionRule::query()->count());

        // Structure ships zero commissions
        $this->assertSame(0, CommissionCalculation::query()->count());
        $this->assertSame(0, CommissionPayment::query()->count());
    }

    public function test_global_rule_applies_when_employee_specific_missing(): void
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());
        $this->issueInvoice(100, 4); // 400

        $rule = app(CommissionCalculator::class)->createRule([
            'employee_id' => null,
            'name' => 'All sales 2%',
            'rule_type' => 'percent_of_revenue',
            'rate' => 2,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $this->assertNull($rule->employee_id);

        $calc = app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        $this->assertSame($rule->id, $calc->commission_rule_id);
        $this->assertEquals(400.0, (float) $calc->base_amount);
        $this->assertEquals(8.0, (float) $calc->commission_amount);
    }

    public function test_calculate_without_active_rule_fails_loudly(): void
    {
        try {
            app(CommissionCalculator::class)->calculate([
                'employee_id' => $this->employee->id,
                'period_type' => 'monthly',
            ], $this->httpRequest());
            $this->fail('Expected missing rule RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No active commission rule', $e->getMessage());
        }
    }
}
