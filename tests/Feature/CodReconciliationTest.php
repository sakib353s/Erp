<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Actions\CreateRiderProfile;
use App\Domain\Delivery\Actions\RecordRiderCodCollection;
use App\Domain\Delivery\CodReconciliation;
use App\Domain\Delivery\RiderAssignment;
use App\Domain\Delivery\RiderCodCollection;
use App\Domain\Delivery\RiderProfile;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\People\Employee;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Sales\SalesOrder;
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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-97b COD reconciliation at POST /app/sales/delivery/cod/reconcile
 * behind sales.delivery.cod.reconcile:
 *
 *  - the cash-vs-remittance match posts one cod_remittance journal:
 *    Dr cash (remitted) + Dr cash over/short when short, Cr AR (cash
 *    total) + Cr other income when over — balanced, never invented;
 *  - every collection settles its own order's issued invoice (money
 *    receipt + allocation + paid/due), refused with truthful reasons
 *    when there is no issued invoice or it is already fully paid;
 *  - mixed riders, foreign collections, already-reconciled rows and
 *    negative remittances are refused;
 *  - a workflow definition gates GL until approved (re-submit
 *    finalizes), a rejection blocks GL while incomplete;
 *  - no stock/STK movement ever comes out of a reconciliation.
 */
class CodReconciliationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Customer $customer;

    protected Courier $courier;

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

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'CODR-1',
            'sku' => 'CODR-SKU-1',
            'name' => 'COD Reconcile Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 200, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'codr-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CODRC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'COD Reconcile Customer',
            'phone' => '01733333334',
            'address_line1' => 'House 8, Road 8, Uttara',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $this->courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CODRX',
            'name' => 'COD Reconcile Courier',
            'configuration_status' => 'pending',
            'integration_enabled' => false,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__cod-reconcile', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 5): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    protected function makeShipment(SalesOrder $order, int $qty = 5): Shipment
    {
        $this->actingAs($this->admin)->post(route('sales.shipments.store'), [
            'order_id' => $order->id,
            'courier_id' => $this->courier->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => $qty]],
        ])->assertSessionHasNoErrors();

        return Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
    }

    protected function dispatch(Shipment $shipment): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.shipments.dispatch', $shipment))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    protected function makeRider(): RiderProfile
    {
        $employee = Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'code' => 'RECON-'.Employee::query()->count(),
            'first_name' => 'Recon',
            'full_name' => 'Recon Rider '.Employee::query()->count(),
            'employment_status' => 'active',
            'status' => 'active',
        ]);

        return app(CreateRiderProfile::class)
            ->handle($employee, [], $this->httpRequest());
    }

    protected function assignAccepted(Shipment $shipment, RiderProfile $profile): RiderAssignment
    {
        return RiderAssignment::query()->create([
            'company_id' => $this->admin->company_id,
            'shipment_id' => $shipment->id,
            'rider_name' => $profile->employee->full_name,
            'rider_employee_id' => $profile->employee_id,
            'status' => RiderAssignment::STATUS_ACCEPTED,
            'assigned_by' => $this->admin->id,
        ]);
    }

    /**
     * Full COD story: confirmed order → issued invoice → dispatched
     * shipment → accepted rider assignment → recorded collection.
     *
     * @return array{0: Invoice, 1: RiderAssignment, 2: RiderCodCollection, 3: RiderProfile}
     */
    protected function codChain(int $qty = 5, bool $withInvoice = true): array
    {
        $order = $this->makeConfirmedOrder($qty);
        $invoice = null;

        if ($withInvoice) {
            $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
            app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());
            $invoice = $invoice->fresh();
        }

        $shipment = $this->makeShipment($order, $qty);
        $this->dispatch($shipment);

        $profile = $this->makeRider();
        $assignment = $this->assignAccepted($shipment, $profile);

        $amount = $invoice !== null
            ? round((float) $invoice->grand_total, 2)
            : 150.0 * $qty;

        $collection = app(RecordRiderCodCollection::class)
            ->handle($assignment, ['amount' => $amount], $this->httpRequest());

        return [$invoice, $assignment, $collection, $profile];
    }

    protected function postReconcile(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('sales.delivery.cod.reconcile'),
            $overrides + ['remitted_amount' => 750],
        );
    }

    protected function lineSum(JournalEntry $entry, string $code, string $dc): float
    {
        $account = Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();

        return round((float) JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $account->id)
            ->where('dc', $dc)
            ->sum('amount'), 2);
    }

    protected function seedReconcileWorkflow(): array
    {
        $role = $this->roleWith([]);
        $approver = $this->makeUser();
        $approver->roles()->attach($role->id);

        WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'cod_reconciliation',
            'action' => 'reconcile',
            'name' => 'COD reconciliation approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => WorkflowDefinition::query()
                ->where('entity_type', 'cod_reconciliation')
                ->where('action', 'reconcile')
                ->firstOrFail()
                ->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        return [$approver, $role];
    }

    public function test_exact_rematch_posts_cod_remittance_and_settles_invoice(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()->where('sales_order_id', $collection->assignment->shipment->sales_order_id)->firstOrFail();
        $expected = round((float) $invoice->grand_total, 2);

        $movementsBefore = StockMovement::query()->count();

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => $expected,
            'reference' => 'SLIP-001',
        ])
            ->assertRedirect(route('sales.delivery.cod.index'))
            ->assertSessionHas('status', 'COD reconciled — cash matched to the remittance.');

        $row = CodReconciliation::query()->firstOrFail();
        $this->assertSame(CodReconciliation::STATUS_RECONCILED, $row->status);
        $this->assertEqualsWithDelta($expected, (float) $row->cash_total, 0.001);
        $this->assertEqualsWithDelta($expected, (float) $row->remitted_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $row->variance, 0.001);
        $this->assertSame('SLIP-001', $row->reference);
        $this->assertNotNull($row->journal_entry_id);
        $this->assertNotNull($row->reconciled_at);

        // cod_remittance journal: Dr cash / Cr AR, balanced, no other event.
        $entry = JournalEntry::query()->findOrFail($row->journal_entry_id);
        $this->assertSame('cod_reconciliation', $entry->source_type);
        $this->assertSame('cod_remittance', $entry->source_event);
        $this->assertEqualsWithDelta($expected, (float) $entry->total_debit, 0.001);
        $this->assertEqualsWithDelta($expected, (float) $entry->total_credit, 0.001);
        $this->assertSame($expected, $this->lineSum($entry, '1110', 'debit'));
        $this->assertSame($expected, $this->lineSum($entry, '1130', 'credit'));
        $this->assertSame(0.0, $this->lineSum($entry, '5250', 'debit'));
        $this->assertSame(0.0, $this->lineSum($entry, '4200', 'credit'));
        $this->assertSame(2, JournalLine::query()->where('journal_entry_id', $entry->id)->count());

        // Collection marked; invoice settled through payment + allocation.
        $collection = $collection->fresh();
        $this->assertSame((int) $row->id, (int) $collection->cod_reconciliation_id);

        $invoice = $invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->due_amount, 0.001);
        $this->assertEqualsWithDelta($expected, (float) $invoice->paid_amount, 0.001);

        $payment = Payment::query()->where('company_id', $this->admin->company_id)->firstOrFail();
        $this->assertSame('cash', $payment->method);
        $this->assertSame('posted', $payment->status);
        $this->assertNotEmpty($payment->receipt_no);
        $this->assertSame((int) $entry->id, (int) $payment->journal_entry_id);

        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame((int) $invoice->id, (int) $allocation->allocatable_id);
        $this->assertEqualsWithDelta($expected, (float) $allocation->amount, 0.001);

        // Audit carries the match; reconciliation itself never moves stock.
        $audit = AuditEvent::query()
            ->where('action', 'sales.cod_reconciled')
            ->where('entity_id', $row->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $audit->after['variance'], 0.001);
        $this->assertSame((int) $entry->id, (int) $audit->after['journal_entry_id']);
        $this->assertSame(1, (int) $audit->after['collections']);

        $this->assertSame($movementsBefore, StockMovement::query()->count());
    }

    public function test_short_remittance_posts_cash_over_short(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()->where('sales_order_id', $collection->assignment->shipment->sales_order_id)->firstOrFail();
        $expected = round((float) $invoice->grand_total, 2); // 750
        $remitted = $expected - 50;

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => $remitted,
        ])->assertSessionHas('status', 'COD reconciled — cash matched to the remittance.');

        $row = CodReconciliation::query()->firstOrFail();
        $this->assertEqualsWithDelta(-50.0, (float) $row->variance, 0.001);

        $entry = JournalEntry::query()->findOrFail($row->journal_entry_id);
        $this->assertEqualsWithDelta($expected, (float) $entry->total_debit, 0.001);
        $this->assertEqualsWithDelta($expected, (float) $entry->total_credit, 0.001);
        $this->assertSame($remitted, $this->lineSum($entry, '1110', 'debit'));
        $this->assertSame(50.0, $this->lineSum($entry, '5250', 'debit'));
        $this->assertSame($expected, $this->lineSum($entry, '1130', 'credit'));
        $this->assertSame(0.0, $this->lineSum($entry, '4200', 'credit'));

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_over_remittance_posts_other_income(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()->where('sales_order_id', $collection->assignment->shipment->sales_order_id)->firstOrFail();
        $expected = round((float) $invoice->grand_total, 2); // 750
        $remitted = $expected + 10;

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => $remitted,
        ])->assertSessionHas('status', 'COD reconciled — cash matched to the remittance.');

        $row = CodReconciliation::query()->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $row->variance, 0.001);

        $entry = JournalEntry::query()->findOrFail($row->journal_entry_id);
        $this->assertSame($remitted, $this->lineSum($entry, '1110', 'debit'));
        $this->assertSame(0.0, $this->lineSum($entry, '5250', 'debit'));
        $this->assertSame($expected, $this->lineSum($entry, '1130', 'credit'));
        $this->assertSame(10.0, $this->lineSum($entry, '4200', 'credit'));
        $this->assertEqualsWithDelta($remitted, (float) $entry->total_debit, 0.001);
        $this->assertEqualsWithDelta($remitted, (float) $entry->total_credit, 0.001);
    }

    public function test_reconcile_refused_without_an_issued_invoice(): void
    {
        [, , $collection] = $this->codChain(withInvoice: false);

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHasErrors('cod');

        $this->assertStringContainsString(
            'has no invoice',
            (string) session('errors')->get('cod')[0],
        );
        $this->assertSame(0, CodReconciliation::query()->count());
        $this->assertSame(0, JournalEntry::query()
            ->where('source_type', 'cod_reconciliation')
            ->count());
        $this->assertNull($collection->fresh()->cod_reconciliation_id);
    }

    public function test_reconcile_refused_when_invoice_already_paid(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()
            ->where('sales_order_id', $collection->assignment->shipment->sales_order_id)
            ->firstOrFail();

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => (float) $invoice->grand_total,
            'method' => 'cash',
        ], $this->httpRequest());

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => (float) $invoice->grand_total,
        ])->assertSessionHasErrors('cod');

        $this->assertStringContainsString(
            'already fully paid',
            (string) session('errors')->get('cod')[0],
        );
        $this->assertSame(0, CodReconciliation::query()->count());
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
    }

    public function test_already_reconciled_collections_are_refused(): void
    {
        [, , $collection] = $this->codChain();

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHas('status');

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHasErrors('cod');

        $this->assertStringContainsString(
            'already reconciled',
            (string) session('errors')->get('cod')[0],
        );
        $this->assertSame(1, CodReconciliation::query()->count());
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
    }

    public function test_mixed_rider_collections_are_refused(): void
    {
        [, , $collectionA] = $this->codChain();
        [, , $collectionB] = $this->codChain();

        $this->assertNotSame(
            (int) $collectionA->rider_employee_id,
            (int) $collectionB->rider_employee_id,
        );

        $this->postReconcile([
            'collection_ids' => [$collectionA->id, $collectionB->id],
            'remitted_amount' => 1500,
        ])->assertSessionHasErrors('cod');

        $this->assertStringContainsString(
            'different riders',
            (string) session('errors')->get('cod')[0],
        );
        $this->assertSame(0, CodReconciliation::query()->count());
        $this->assertNull($collectionA->fresh()->cod_reconciliation_id);
        $this->assertNull($collectionB->fresh()->cod_reconciliation_id);
    }

    public function test_foreign_collection_ids_fail_validation(): void
    {
        [, , $collection] = $this->codChain();

        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow COD Reconcile Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowEmployee = Employee::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDWR',
            'first_name' => 'Shadow',
            'full_name' => 'Shadow Recon Rider',
            'employment_status' => 'active',
            'status' => 'active',
        ]);
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'order_no' => 'SHDW-REC',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCourierId = DB::table('couriers')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHDWC',
            'name' => 'Shadow Courier',
            'configuration_status' => 'not_configured',
            'integration_enabled' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowShipmentId = DB::table('shipments')->insertGetId([
            'company_id' => $shadowId,
            'sales_order_id' => $shadowOrderId,
            'courier_id' => $shadowCourierId,
            'status' => 'pending_dispatch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowAssignmentId = DB::table('rider_assignments')->insertGetId([
            'company_id' => $shadowId,
            'shipment_id' => $shadowShipmentId,
            'rider_name' => 'Shadow Recon Rider',
            'rider_employee_id' => $shadowEmployee->id,
            'status' => 'accepted',
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowCollectionId = DB::table('rider_cod_collections')->insertGetId([
            'company_id' => $shadowId,
            'rider_assignment_id' => $shadowAssignmentId,
            'rider_employee_id' => $shadowEmployee->id,
            'amount' => 500,
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postReconcile([
            'collection_ids' => [$collection->id, $shadowCollectionId],
            'remitted_amount' => 1250,
        ])->assertSessionHasErrors('collection_ids.1');

        $this->assertSame(0, CodReconciliation::query()->count());
        $this->assertNull($collection->fresh()->cod_reconciliation_id);
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
    }

    public function test_workflow_gates_gl_until_approval_then_resubmit_finalizes(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()
            ->where('sales_order_id', $collection->assignment->shipment->sales_order_id)
            ->firstOrFail();
        [$approver] = $this->seedReconcileWorkflow();

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHas('status', 'COD reconciliation submitted for approval.');

        $row = CodReconciliation::query()->firstOrFail();
        $this->assertSame(CodReconciliation::STATUS_PENDING, $row->status);
        $this->assertNotNull($row->approval_request_id);
        $this->assertNull($row->journal_entry_id);
        $this->assertSame((int) $row->id, (int) $collection->fresh()->cod_reconciliation_id);
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.cod_reconciliation_submitted')->count(),
        );

        // Approve, then re-submit the same collections: now it finalizes.
        app(WorkflowEngine::class)
            ->approve($row->approval_request_id, $approver, 'cash counted');

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHas('status', 'COD reconciled — cash matched to the remittance.');

        $row = $row->fresh();
        $this->assertSame(CodReconciliation::STATUS_RECONCILED, $row->status);
        $this->assertNotNull($row->journal_entry_id);
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_rejected_workflow_blocks_finalize(): void
    {
        [, , $collection] = $this->codChain();
        [$approver] = $this->seedReconcileWorkflow();

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHas('status', 'COD reconciliation submitted for approval.');

        $row = CodReconciliation::query()->firstOrFail();
        app(WorkflowEngine::class)
            ->reject($row->approval_request_id, $approver, 'count is wrong');

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 750,
        ])->assertSessionHasErrors('cod');

        $this->assertStringContainsString(
            'approval is not complete',
            (string) session('errors')->get('cod')[0],
        );

        $row = $row->fresh();
        $this->assertSame(CodReconciliation::STATUS_PENDING, $row->status);
        $this->assertNull($row->journal_entry_id);
        $this->assertSame(
            ApprovalRequest::query()->findOrFail($row->approval_request_id)->status,
            'rejected',
        );
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'cod_reconciliation')->count(),
        );
    }

    public function test_reconcile_route_gated_by_cod_reconcile_permission(): void
    {
        $denied = $this->makeUser();
        $deniedRole = $this->roleWith(['portal.erp.access', 'sales.delivery.cod']);
        $denied->roles()->attach($deniedRole->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.delivery.cod.reconcile'), [
                'collection_ids' => [1],
                'remitted_amount' => 100,
            ])
            ->assertForbidden();

        $granted = $this->makeUser();
        $grantedRole = $this->roleWith([
            'portal.erp.access',
            'sales.delivery.cod',
            'sales.delivery.cod.reconcile',
        ]);
        $granted->roles()->attach($grantedRole->id);
        app(PermissionCatalog::class)->invalidate($granted);

        // Gate passed — validation (no collections selected) is the next stop.
        $this->actingAs($granted)
            ->post(route('sales.delivery.cod.reconcile'), [
                'remitted_amount' => 100,
            ])
            ->assertSessionHasErrors('collection_ids');
    }

    public function test_negative_remittance_rejected_and_zero_remittance_posts_full_shortage(): void
    {
        [, , $collection] = $this->codChain();
        $invoice = Invoice::query()
            ->where('sales_order_id', $collection->assignment->shipment->sales_order_id)
            ->firstOrFail();
        $expected = round((float) $invoice->grand_total, 2);

        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => -5,
        ])->assertSessionHasErrors('remitted_amount');

        $this->assertSame(0, CodReconciliation::query()->count());

        // Remitted 0: the office handed over nothing — full shortage vs AR.
        $this->postReconcile([
            'collection_ids' => [$collection->id],
            'remitted_amount' => 0,
        ])->assertSessionHas('status', 'COD reconciled — cash matched to the remittance.');

        $row = CodReconciliation::query()->firstOrFail();
        $this->assertEqualsWithDelta(-$expected, (float) $row->variance, 0.001);

        $entry = JournalEntry::query()->findOrFail($row->journal_entry_id);
        $this->assertSame(0.0, $this->lineSum($entry, '1110', 'debit'));
        $this->assertSame($expected, $this->lineSum($entry, '5250', 'debit'));
        $this->assertSame($expected, $this->lineSum($entry, '1130', 'credit'));
        $this->assertSame('paid', $invoice->fresh()->status);
    }
}
