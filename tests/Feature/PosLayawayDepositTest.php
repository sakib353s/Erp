<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\LayawaySchedule;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\StockReservation;
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
 * 02-41 Sales › POS › Layaway / Advance Deposit: the counter takes a
 * deposit against a pending sales order — stock reserved (source_type
 * sales_order), one balanced journal Dr cash/bank / Cr Customer Advances
 * (2140) through the layaway_deposit rules, a real pos_transactions row
 * + session counter so X/close agree, and a dated installment schedule
 * for the balance. The deposit invoice (invoice_type layaway) is a
 * liability, never revenue — sales reports exclude it. A matching
 * sales_order/layaway workflow definition holds the order with no stock
 * and no money until approval, then the same order resumes. Route,
 * terminal button and menu leaf all sit behind pos.layaway.
 */
class PosLayawayDepositTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

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
            'code' => 'POSLAY-1',
            'sku' => 'POSLAY-SKU-1',
            'name' => 'Layaway Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'poslay-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-layaway-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function layawayPayload(array $overrides = []): array
    {
        return array_merge([
            'notes' => 'Counter layaway',
            'deposit_amount' => 300,
            'installment_count' => 3,
            'interval_days' => 30,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides);
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser();
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    protected function openSession(float $float = 1000.0): PosSession
    {
        return app(OpenPosSession::class)->handle(
            ['opening_float' => $float, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );
    }

    public function test_layaway_takes_deposit_reserves_stock_and_builds_schedule(): void
    {
        $session = $this->openSession(1000.0);

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertRedirect(route('pos.terminal'))
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('300.00', $status);
        $this->assertStringContainsString('450.00', $status);

        // The order: pending, stock reserved, priced server-side (5 × 150).
        $order = SalesOrder::query()->sole();
        $this->assertSame('pending', $order->status);
        $this->assertTrue((bool) $order->stock_reserved);
        $this->assertSame($session->warehouse_id, (int) $order->warehouse_id);
        $this->assertEquals(750.0, (float) $order->grand_total);
        $this->assertStringContainsString($order->order_no, $status);

        // STK: one reservation under the order's own source — held, not issued.
        $reservation = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->sole();
        $this->assertSame('active', $reservation->status);
        $this->assertEquals(5.0, (float) $reservation->qty);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(50.0, (float) $balance->on_hand);
        $this->assertEquals(5.0, (float) $balance->reserved);
        $this->assertSame(1, StockMovement::query()->count()); // opening stock only

        // Deposit invoice: paid, posted, tied to order + drawer, no goods lines.
        $invoice = Invoice::query()->sole();
        $this->assertSame('layaway', $invoice->invoice_type);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('posted', $invoice->posting_state);
        $this->assertEquals(300.0, (float) $invoice->grand_total);
        $this->assertEquals(300.0, (float) $invoice->paid_amount);
        $this->assertEquals(0.0, (float) $invoice->due_amount);
        $this->assertSame($order->id, (int) $invoice->sales_order_id);
        $this->assertSame($session->id, (int) $invoice->pos_session_id);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertSame(0, $invoice->lines()->count());
        $this->assertNotNull($invoice->invoice_no);

        // ACCT: balanced Dr 1110 cash / Cr 2140 customer advances — a
        // liability, never revenue.
        $entry = JournalEntry::query()->where('source_event', 'layaway_deposit')->sole();
        $this->assertSame('pos_session', $entry->source_type);
        $this->assertSame($session->id, (int) $entry->source_id);
        $this->assertEquals(300.0, (float) $entry->total_debit);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        $this->assertSame(
            ['1110' => 'debit', '2140' => 'credit'],
            $entry->lines()->with('account')->get()
                ->mapWithKeys(fn ($line) => [$line->account->code => $line->dc])->all(),
        );

        // Drawer: a real pos_transactions row + the matching counter, so
        // X report and close math agree.
        $transaction = PosTransaction::query()->sole();
        $this->assertSame($session->id, (int) $transaction->pos_session_id);
        $this->assertSame($invoice->id, (int) $transaction->invoice_id);
        $this->assertEquals(300.0, (float) $transaction->total);
        $this->assertSame('cash', $transaction->payment_method);
        $session->refresh();
        $this->assertEquals(300.0, (float) $session->cash_sales);

        // Schedule: balance split over dated installments.
        $schedule = LayawaySchedule::query()->sole();
        $this->assertSame('open', $schedule->status);
        $this->assertSame($order->id, (int) $schedule->sales_order_id);
        $this->assertSame($invoice->id, (int) $schedule->deposit_invoice_id);
        $this->assertEquals(750.0, (float) $schedule->order_total);
        $this->assertEquals(300.0, (float) $schedule->deposit_amount);
        $this->assertEquals(450.0, (float) $schedule->balance_amount);
        $this->assertSame(3, (int) $schedule->installment_count);
        $this->assertSame(30, (int) $schedule->interval_days);
        $this->assertSame(now()->addDays(30)->toDateString(), $schedule->first_due_on->toDateString());

        $installments = $schedule->installments()->orderBy('line_no')->get();
        $this->assertCount(3, $installments);
        $this->assertEquals([150.0, 150.0, 150.0], $installments->map(fn ($i) => (float) $i->amount)->all());
        $this->assertSame(
            [
                now()->addDays(30)->toDateString(),
                now()->addDays(60)->toDateString(),
                now()->addDays(90)->toDateString(),
            ],
            $installments->map(fn ($i) => $i->due_on->toDateString())->all(),
        );
        $this->assertSame(['pending', 'pending', 'pending'], $installments->pluck('status')->all());

        // AUD: no session keys (AuditRedactor), real entry number.
        $audit = AuditEvent::query()
            ->where('action', 'pos.layaway_created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('layaway_schedule', $audit->entity_type);
        $this->assertSame($schedule->id, (int) $audit->entity_id);
        $this->assertSame($order->order_no, $audit->after['order_no']);
        $this->assertSame($invoice->invoice_no, $audit->after['invoice_no']);
        $this->assertEquals(300.0, (float) $audit->after['deposit_amount']);
        $this->assertArrayNotHasKey('session_no', $audit->after);

        // Close: counted cash = opening + deposit → zero variance.
        $closed = app(ClosePosSession::class)->handle($session->fresh(), 1300.0, $this->httpRequest());
        $this->assertEquals(1300.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
    }

    public function test_installment_split_absorbs_rounding_in_the_last_row(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload([
                'deposit_amount' => 100,
                'installment_count' => 3,
            ]))
            ->assertRedirect(route('pos.terminal'));

        $schedule = LayawaySchedule::query()->sole();
        $this->assertEquals(650.0, (float) $schedule->balance_amount);

        $amounts = $schedule->installments()->orderBy('line_no')
            ->get()->map(fn ($i) => (float) $i->amount);
        $this->assertCount(3, $amounts);
        $this->assertEqualsWithDelta(650.0, (float) $amounts->sum(), 0.0001);
        $this->assertTrue($amounts->every(fn ($a) => $a > 0));
        // The last row absorbs whatever the equal split could not.
        $this->assertEqualsWithDelta(
            (float) $schedule->balance_amount - $amounts->take(2)->sum(),
            (float) $amounts->last(),
            0.0001,
        );
        $this->assertSame($session->id, (int) $schedule->pos_session_id);
    }

    public function test_bank_deposit_posts_advance_liability_and_keeps_drawer_cash_unaffected(): void
    {
        $session = $this->openSession(1000.0);

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['payment_method' => 'bank']))
            ->assertRedirect(route('pos.terminal'));

        // ACCT through the bank variant: Dr 1120 / Cr 2140.
        $entry = JournalEntry::query()->where('source_event', 'layaway_deposit')->sole();
        $this->assertEquals(300.0, (float) $entry->total_debit);
        $this->assertSame(
            ['1120' => 'debit', '2140' => 'credit'],
            $entry->lines()->with('account')->get()
                ->mapWithKeys(fn ($line) => [$line->account->code => $line->dc])->all(),
        );

        // Non-cash: the drawer's expected cash never moves.
        $transaction = PosTransaction::query()->sole();
        $this->assertSame('bank', $transaction->payment_method);
        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_sales);
        $this->assertEquals(300.0, (float) $session->non_cash_sales);

        $closed = app(ClosePosSession::class)->handle($session->fresh(), 1000.0, $this->httpRequest());
        $this->assertEquals(1000.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
    }

    public function test_layaway_validates_deposit_installments_interval_method_and_lines(): void
    {
        $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['deposit_amount' => 0]))
            ->assertSessionHasErrors(['deposit_amount']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['deposit_amount' => 'abc']))
            ->assertSessionHasErrors(['deposit_amount']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['installment_count' => 0]))
            ->assertSessionHasErrors(['installment_count']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['installment_count' => 61]))
            ->assertSessionHasErrors(['installment_count']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['interval_days' => 0]))
            ->assertSessionHasErrors(['interval_days']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['payment_method' => 'gold']))
            ->assertSessionHasErrors(['payment_method']);
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['lines' => []]))
            ->assertSessionHasErrors(['lines']);

        // Deposit >= order total is refused by the action (the total is
        // only known after server-side pricing).
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload(['deposit_amount' => 800]))
            ->assertSessionHasErrors(['layaway']);
        $this->assertStringContainsString(
            'must be less than the order total',
            session('errors')->first('layaway'),
        );

        // Every rejection rolled back completely — no orders, invoices,
        // schedules, journals or reservations.
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, LayawaySchedule::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_layaway_requires_an_open_session_and_leaves_nothing_behind(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertSessionHasErrors(['layaway']);

        $this->assertStringContainsString(
            'No open POS session',
            session('errors')->first('layaway'),
        );
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, LayawaySchedule::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_insufficient_stock_rolls_back_the_whole_layaway(): void
    {
        $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload([
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 999, 'unit_price' => 150],
                ],
            ]))
            ->assertSessionHasErrors(['layaway']);

        $this->assertStringContainsString(
            'Insufficient available stock',
            session('errors')->first('layaway'),
        );
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, LayawaySchedule::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, PosTransaction::query()->count());

        $session = PosSession::query()->where('status', 'open')->sole();
        $this->assertEquals(0.0, (float) $session->cash_sales);
    }

    public function test_layaway_route_requires_pos_layaway_permission(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->userWith(['portal.erp.access']))
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertForbidden();
        $this->assertSame(0, SalesOrder::query()->count());

        // pos.layaway alone — the endpoint never needs pos.sell.
        $granted = $this->userWith(['portal.erp.access', 'pos.layaway']);
        $this->actingAs($granted)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertRedirect(route('pos.terminal'));

        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, LayawaySchedule::query()->count());

        $session->refresh();
        $this->assertEquals(300.0, (float) $session->cash_sales);
    }

    public function test_terminal_layaway_form_gated_by_permission(): void
    {
        $this->openSession();

        $withKey = $this->userWith(['portal.erp.access', 'pos.sell', 'pos.layaway']);
        $this->actingAs($withKey)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertSee('Start layaway');

        $withoutKey = $this->userWith(['portal.erp.access', 'pos.sell']);
        $this->actingAs($withoutKey)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertDontSee('Start layaway');
    }

    public function test_layaway_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Layaway / Advance Deposit')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos', $leaf->route);
        $this->assertSame('pos.layaway', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Layaway / Advance Deposit');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Layaway / Advance Deposit');
    }

    public function test_workflow_definition_holds_layaway_then_approved_resume_applies_deposit(): void
    {
        $this->openSession(1000.0);

        $role = $this->roleWith([]);
        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'sales_order',
            'action' => 'layaway',
            'name' => 'Layaway deposit approval',
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

        // Hold: order created, but no stock, no money, no schedule.
        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertRedirect(route('pos.terminal'));
        $this->assertStringContainsString(
            'submitted for approval',
            (string) session('status'),
        );

        $order = SalesOrder::query()->sole();
        $this->assertSame('pending', $order->status);
        $this->assertFalse((bool) $order->stock_reserved);
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, LayawaySchedule::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());

        $approval = ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $order->id)
            ->where('action', 'layaway')
            ->where('status', 'pending')
            ->sole();

        // Approve, then resume the SAME order — the deposit applies now.
        $approver = $this->makeUser(['name' => 'Layaway Approver']);
        $approver->roles()->sync($role->id);
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'deposit ok');
        $this->assertSame('approved', $approval->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload([
                'sales_order_id' => $order->id,
            ]))
            ->assertRedirect(route('pos.terminal'));

        $this->assertSame(1, SalesOrder::query()->count());
        $this->assertTrue((bool) $order->fresh()->stock_reserved);
        $this->assertEquals(5.0, (float) StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)->sole()->qty);

        $invoice = Invoice::query()->sole();
        $this->assertSame($order->id, (int) $invoice->sales_order_id);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1, LayawaySchedule::query()->count());
        $this->assertEquals(300.0, (float) JournalEntry::query()
            ->where('source_event', 'layaway_deposit')->sole()->total_debit);

        $session = PosSession::query()->where('status', 'open')->sole();
        $this->assertEquals(300.0, (float) $session->cash_sales);
    }

    public function test_confirm_after_layaway_never_double_reserves(): void
    {
        $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertRedirect(route('pos.terminal'));

        $order = SalesOrder::query()->sole();
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertTrue((bool) $order->stock_reserved);

        // The layaway reservation is reused, not stacked.
        $reservation = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->sole();
        $this->assertEquals(5.0, (float) $reservation->qty);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(50.0, (float) $balance->on_hand);
        $this->assertEquals(5.0, (float) $balance->reserved);
    }

    public function test_delivery_invoicing_is_blocked_until_layaway_balance_settled(): void
    {
        $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.layaway.store'), $this->layawayPayload())
            ->assertRedirect(route('pos.terminal'));

        $order = SalesOrder::query()->sole();
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $order = $order->fresh();

        // Goods must never be billed on top of the deposit invoice.
        try {
            app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
            $this->fail('CreateInvoiceFromOrder should refuse a layaway order.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('settle the balance', $e->getMessage());
        }

        // Only the deposit invoice exists — nothing was double-billed.
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame('layaway', Invoice::query()->sole()->invoice_type);
    }
}
