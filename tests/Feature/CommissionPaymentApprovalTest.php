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
 * Commission payment approval (02-81): WF gates GL until approved;
 * ACCT settle via commission_payment rule (Dr payable, Cr cash).
 */
class CommissionPaymentApprovalTest extends TestCase
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
            'code' => 'SP-AP',
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'full_name' => 'Farah Khan',
            'designation' => 'Sales Executive',
            'employment_status' => 'active',
            'status' => 'active',
            'is_salesperson' => false,
        ]);

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'CAP-1',
            'sku' => 'CAP-SKU-1',
            'name' => 'Approval Product',
            'cost_method' => 'fifo',
            'standard_cost' => 20,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 15],
            ],
            'idempotency_suffix' => 'cap-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__commission-ap', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function seedAccruedCalculation(float $rate = 5): CommissionCalculation
    {
        app(FlagEmployeeAsSalesPerson::class)->handle($this->employee, true, $this->httpRequest());

        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'sales_person_id' => $this->employee->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 4, 'unit_price' => 500],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $rule = app(CommissionCalculator::class)->createRule([
            'employee_id' => $this->employee->id,
            'name' => 'AP rule',
            'rule_type' => 'percent_of_revenue',
            'rate' => $rate,
            'period_type' => 'monthly',
        ], $this->httpRequest());

        return app(CommissionCalculator::class)->calculate([
            'employee_id' => $this->employee->id,
            'commission_rule_id' => $rule->id,
            'period_type' => 'monthly',
        ], $this->httpRequest());
    }

    public function test_rejection_keeps_payment_unpaid_and_blocks_double_post(): void
    {
        $calc = $this->seedAccruedCalculation(); // base 2000 → 100

        $role = $this->roleWith([]);
        $approver = $this->makeUser();
        $approver->roles()->attach($role->id);

        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'commission_payment',
            'action' => 'pay',
            'name' => 'Commission pay reject path',
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

        $payment = app(PayCommission::class)->handle($calc, [
            'method' => 'cash',
            'idempotency_key' => 'reject-1',
        ], $this->httpRequest());

        $this->assertSame('pending_approval', $payment->status);

        $approval = ApprovalRequest::query()->findOrFail($payment->approval_request_id);
        app(WorkflowEngine::class)->reject($approval->id, $approver, 'too high this period');

        // Re-pay after reject: approval not approved → refuse GL
        try {
            app(PayCommission::class)->handle($calc->fresh(), [
                'method' => 'cash',
                'idempotency_key' => 'reject-1',
            ], $this->httpRequest());
            $this->fail('Expected approval incomplete RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('approval is not complete', $e->getMessage());
        }

        $payment->refresh();
        $this->assertNull($payment->journal_entry_id);
        $this->assertNotSame('paid', $payment->status);
        $this->assertNotSame('paid', $calc->fresh()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'commission_payment')->count());
    }

    public function test_approved_payment_settles_payable_and_cash_balanced(): void
    {
        $calc = $this->seedAccruedCalculation(5); // 100

        $role = $this->roleWith([]);
        $approver = $this->makeUser();
        $approver->roles()->attach($role->id);

        WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'commission_payment',
            'action' => 'pay',
            'name' => 'Commission pay approve path',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        $definition = WorkflowDefinition::query()
            ->where('entity_type', 'commission_payment')
            ->where('action', 'pay')
            ->firstOrFail();
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        $payment = app(PayCommission::class)->handle($calc, [
            'method' => 'cash',
            'idempotency_key' => 'approve-1',
        ], $this->httpRequest());
        $this->assertSame('pending_approval', $payment->status);
        $this->assertSame(0, CommissionPayment::query()->where('status', 'paid')->count());

        $approval = ApprovalRequest::query()->findOrFail($payment->approval_request_id);
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'approved');

        $paid = app(PayCommission::class)->handle($calc->fresh(), [
            'method' => 'cash',
            'idempotency_key' => 'approve-1',
        ], $this->httpRequest());

        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->journal_entry_id);
        $this->assertSame(1, CommissionPayment::query()->where('status', 'paid')->count());

        $entry = JournalEntry::query()->findOrFail($paid->journal_entry_id);
        $payable = Account::query()->where('company_id', $this->admin->company_id)->where('code', '2130')->firstOrFail();
        $cash = Account::query()->where('company_id', $this->admin->company_id)->where('code', '1110')->firstOrFail();
        $expense = Account::query()->where('company_id', $this->admin->company_id)->where('code', '5240')->firstOrFail();

        // Payment settles payable (not expense again)
        $this->assertEquals(100.0, (float) $entry->lines()->where('account_id', $payable->id)->where('dc', 'debit')->sum('amount'));
        $this->assertEquals(100.0, (float) $entry->lines()->where('account_id', $cash->id)->where('dc', 'credit')->sum('amount'));
        $this->assertEquals(0.0, (float) $entry->lines()->where('account_id', $expense->id)->where('dc', 'debit')->sum('amount'));

        // Accrual entry still present: Dr expense 100
        $accrual = JournalEntry::query()->findOrFail($calc->fresh()->journal_entry_id);
        $this->assertEquals(100.0, (float) $accrual->lines()->where('account_id', $expense->id)->where('dc', 'debit')->sum('amount'));
        $this->assertEquals(
            (float) $entry->lines()->where('dc', 'debit')->sum('amount'),
            (float) $entry->lines()->where('dc', 'credit')->sum('amount'),
        );
    }

    public function test_http_pay_requires_commission_pay_permission(): void
    {
        $calc = $this->seedAccruedCalculation();

        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access', 'sales.team.commissions']);
        $user->roles()->attach($role->id);

        // Has view but not pay
        $this->actingAs($user)
            ->post('/app/sales/team/commissions/'.$calc->id.'/pay', ['method' => 'cash'])
            ->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.team.commissions',
            'sales.team.commission_pay',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)
            ->post('/app/sales/team/commissions/'.$calc->id.'/pay', [
                'method' => 'cash',
                'idempotency_key' => 'http-pay-1',
            ])
            ->assertRedirect();

        $this->assertSame(1, CommissionPayment::query()->where('status', 'paid')->count());
    }
}
