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
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Actions\CancelOrder;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\StockReservation;
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
 * Phase G gate: sales cycle core.
 *  - quotation → order (no GL/stock on save) → confirm (reserve only) →
 *    invoice (draft, D10 title INVOICE) → issue (GL + stock SALES_OUT + COGS) →
 *    payment (Dr Cash / Cr AR, allocate, paid/partial);
 *  - cancel releases reservation; server-authoritative totals;
 *  - POS session open/close variance; permission gates with portal.erp.access.
 */
class SalesCoreTest extends TestCase
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
            'code' => 'SALE-1',
            'sku' => 'SALE-SKU-1',
            'name' => 'Sale Product',
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
            'idempotency_suffix' => 'sales-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__sales-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides);
    }

    public function test_quotation_is_doc_only_no_stock_no_gl(): void
    {
        $balanceBefore = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail()
            ->only(['on_hand', 'reserved']);

        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());

        $this->assertSame('draft', $quote->status);
        $this->assertStringStartsWith('QT', $quote->quote_no);
        $this->assertEquals(750.0, (float) $quote->grand_total);

        $balanceAfter = StockBalance::query()
            ->where('product_id', $this->product->id)
            ->firstOrFail()
            ->only(['on_hand', 'reserved']);

        $this->assertSame($balanceBefore, $balanceAfter);
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(1, Quotation::query()->count());
    }

    public function test_order_save_has_no_stock_or_gl_effect(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());

        $this->assertSame('pending', $order->status);
        $this->assertFalse($order->stock_reserved);
        $this->assertStringStartsWith('SO', $order->order_no);
        $this->assertEquals(750.0, (float) $order->grand_total);
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_order_totals_are_server_authoritative(): void
    {
        // Client sends bogus unit_price — pricing still resolves; totals from calculator
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload([
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 3, 'unit_price' => 200],
                ],
            ]),
            $this->httpRequest(),
        );

        $this->assertEquals(600.0, (float) $order->grand_total);
        $this->assertEquals(600.0, (float) $order->lines->first()->line_total);
    }

    public function test_confirm_reserves_stock_without_issue(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertTrue($order->stock_reserved);

        $reservation = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->firstOrFail();
        $this->assertSame('active', $reservation->status);
        $this->assertEquals(5.0, (float) $reservation->qty);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(50, (float) $balance->on_hand); // not issued
        $this->assertEquals(5, (float) $balance->reserved);

        // No stock movement for reservation
        $this->assertSame(1, StockMovement::query()->count()); // only opening
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_cancel_releases_reservation(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        app(CancelOrder::class)->handle($order->fresh(), 'customer changed mind', $this->httpRequest());

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertFalse($order->stock_reserved);

        $reservation = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->firstOrFail();
        $this->assertSame('released', $reservation->status);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(0, (float) $balance->reserved);
        $this->assertEquals(50, (float) $balance->on_hand);
    }

    public function test_insufficient_stock_blocks_confirm(): void
    {
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload([
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 999, 'unit_price' => 10],
                ],
            ]),
            $this->httpRequest(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient/');

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
    }

    public function test_invoice_draft_has_d10_title_and_no_gl(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)
            ->handle($order->fresh(), [], $this->httpRequest());

        $this->assertSame('draft', $invoice->status);
        $this->assertSame('draft', $invoice->posting_state);
        $this->assertSame('INVOICE', $invoice->printed_title);
        $this->assertStringStartsWith('INV', $invoice->invoice_no);
        $this->assertEquals(750.0, (float) $invoice->grand_total);
        $this->assertEquals(750.0, (float) $invoice->due_amount);
        $this->assertSame(0, JournalEntry::query()->count());
        // Stock still reserved, not issued
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(50, (float) $balance->on_hand);
        $this->assertEquals(5, (float) $balance->reserved);
    }

    public function test_issue_posts_gl_and_issues_stock_with_cogs(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());

        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('posted', $invoice->posting_state);
        $this->assertNotNull($invoice->journal_entry_id);

        // Sales GL: Dr AR 750, Cr Sales 750
        $salesEntry = JournalEntry::query()->whereKey($invoice->journal_entry_id)->firstOrFail();
        $this->assertEquals(750.0, (float) $salesEntry->total_debit);
        $this->assertEquals(750.0, (float) $salesEntry->total_credit);

        $ar = Account::query()->where('code', '1130')->firstOrFail();
        $sales = Account::query()->where('code', '4100')->firstOrFail();
        $this->assertTrue($salesEntry->lines()->where('account_id', $ar->id)->where('dc', 'debit')->exists());
        $this->assertTrue($salesEntry->lines()->where('account_id', $sales->id)->where('dc', 'credit')->exists());

        // Stock issued
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(45, (float) $balance->on_hand); // 50 - 5
        $this->assertEquals(0, (float) $balance->reserved);

        $this->assertTrue(
            StockMovement::query()
                ->where('product_id', $this->product->id)
                ->where('movement_type', StockMovement::TYPE_SALES_OUT)
                ->where('source_type', 'invoice')
                ->where('source_id', $invoice->id)
                ->exists()
        );

        // Reservation consumed
        $reservation = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->firstOrFail();
        $this->assertSame('consumed', $reservation->status);

        // Order completed
        $this->assertSame('completed', $order->fresh()->status);

        // COGS entry exists (Dr COGS / Cr Inventory) from unit cost 80 × 5 = 400
        $cogsEntries = JournalEntry::query()->where('source_event', 'sales_cost')->get();
        $this->assertNotEmpty($cogsEntries);
        $this->assertEquals(400.0, (float) $cogsEntries->first()->total_debit);
    }

    public function test_payment_allocates_and_marks_invoice_paid(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $beforeEntries = JournalEntry::query()->count();

        $payment = app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 750,
            'method' => 'cash',
            'idempotency_key' => 'pay-'.uniqid(),
        ], $this->httpRequest());

        $this->assertSame('posted', $payment->status);
        $this->assertStringStartsWith('MR', $payment->receipt_no);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(750.0, (float) $invoice->paid_amount);
        $this->assertEquals(0.0, (float) $invoice->due_amount);

        // Receipt GL: Dr Cash / Cr AR
        $this->assertSame($beforeEntries + 1, JournalEntry::query()->count());
        $receipt = JournalEntry::query()->whereKey($payment->journal_entry_id)->firstOrFail();
        $cash = Account::query()->where('code', '1110')->firstOrFail();
        $this->assertTrue($receipt->lines()->where('account_id', $cash->id)->where('dc', 'debit')->exists());

        // Allocation row
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'allocatable_id' => $invoice->id,
        ]);
    }

    public function test_partial_payment_marks_partial(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'method' => 'cash',
        ], $this->httpRequest());

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertEquals(450.0, (float) $invoice->due_amount);
    }

    public function test_overpayment_is_rejected(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds due/');

        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 9999,
            'method' => 'cash',
        ], $this->httpRequest());
    }

    public function test_pos_session_open_close_with_variance(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 500],
            $this->httpRequest(),
        );

        $this->assertSame('open', $session->status);
        $this->assertEquals(500.0, (float) $session->opening_float);

        // Second open rejected
        try {
            app(OpenPosSession::class)->handle(['opening_float' => 100], $this->httpRequest());
            $this->fail('Expected concurrent open rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already open', $e->getMessage());
        }

        $closed = app(ClosePosSession::class)->handle($session, 480.0, $this->httpRequest());

        $this->assertSame('closed', $closed->status);
        $this->assertEquals(500.0, (float) $closed->expected_cash);
        $this->assertEquals(480.0, (float) $closed->closing_counted);
        $this->assertEquals(-20.0, (float) $closed->variance);
    }

    public function test_pos_sale_commit_issues_stock_and_updates_session(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 100, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $txn = app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 1000,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        $this->assertSame('completed', $txn->status);
        $this->assertSame('synced', $txn->sync_state);
        $this->assertEquals(300.0, (float) $txn->total);
        $this->assertEquals(1000.0, (float) $txn->tendered);
        $this->assertEquals(700.0, (float) $txn->change_due);

        $invoice = Invoice::query()->findOrFail($txn->invoice_id);
        $this->assertSame('pos', $invoice->invoice_type);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('posted', $invoice->posting_state);
        $this->assertSame('INVOICE', $invoice->printed_title);
        $this->assertSame($session->id, (int) $invoice->pos_session_id);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertEquals(300.0, (float) $invoice->grand_total);
        $this->assertEquals(300.0, (float) $invoice->paid_amount);
        $this->assertEquals(0.0, (float) $invoice->due_amount);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(48, (float) $balance->on_hand); // 50 - 2
        $this->assertEquals(0, (float) $balance->reserved);

        $this->assertTrue(
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_SALES_OUT)
                ->where('source_type', 'invoice')
                ->where('source_id', $invoice->id)
                ->exists()
        );

        $session->refresh();
        $this->assertEquals(100.0, (float) $session->opening_float);
        $this->assertEquals(300.0, (float) $session->cash_sales);
        $this->assertEquals(0.0, (float) $session->non_cash_sales);

        // Sales + receipt + COGS entries all balanced
        $this->assertGreaterThanOrEqual(3, JournalEntry::query()->count());
        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals(
                (float) $entry->total_debit,
                (float) $entry->total_credit,
                "Entry {$entry->entry_no} unbalanced",
            );
        }
    }

    public function test_pos_sale_commit_is_idempotent_by_client_uuid(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $payload = [
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'client_uuid' => 'uuid-idem-'.uniqid(),
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 150],
            ],
        ];

        $first = app(CommitPosSale::class)->handle($payload, $this->httpRequest());
        $second = app(CommitPosSale::class)->handle($payload, $this->httpRequest());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosTransaction::query()->count());
        $this->assertSame(1, Invoice::query()->where('invoice_type', 'pos')->count());

        $session->refresh();
        $this->assertEquals(150.0, (float) $session->cash_sales);
    }

    public function test_pos_sale_commit_requires_open_session_with_warehouse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No open POS session/');

        app(CommitPosSale::class)->handle([
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    public function test_pos_sale_commit_rejects_insufficient_stock(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $this->expectException(\RuntimeException::class);

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 999, 'unit_price' => 10],
            ],
        ], $this->httpRequest());
    }

    public function test_pos_sale_commit_rejects_short_tender(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/less than total/');

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 10,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    public function test_pos_sale_commit_non_cash_updates_non_cash_counter(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $txn = app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'bank',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 200],
            ],
        ], $this->httpRequest());

        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_sales);
        $this->assertEquals(200.0, (float) $session->non_cash_sales);
        $this->assertEquals(200.0, (float) $txn->tendered);
        $this->assertEquals(0.0, (float) $txn->change_due);
    }

    public function test_pos_sale_http_commit_requires_pos_sell(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->post('/pos/sales', [
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/pos/products?q=Sale')
            ->assertForbidden();

        $sellRole = $this->roleWith(['portal.erp.access', 'pos.sell']);
        $user->roles()->sync([$sellRole->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $this->actingAs($user)
            ->get('/pos/products?q=Sale')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SALE-SKU-1');

        $this->actingAs($user)
            ->post('/pos/sales', [
                'pos_session_id' => $session->id,
                'payment_method' => 'cash',
                'tendered' => 150,
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 150],
                ],
            ])
            ->assertRedirect(route('pos.terminal'))
            ->assertSessionHas('status');

        $this->assertSame(1, PosTransaction::query()->count());
    }

    public function test_full_cycle_quotation_to_paid_invoice(): void
    {
        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());
        $this->assertSame('draft', $quote->status);

        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['source_quotation_id' => $quote->id]),
            $this->httpRequest(),
        );
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
        app(RecordInvoicePayment::class)->handle([
            'invoice_id' => $invoice->id,
            'amount' => 750,
            'method' => 'cash',
        ], $this->httpRequest());

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('INVOICE', $invoice->printed_title);

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(45, (float) $balance->on_hand);
        $this->assertEquals(0, (float) $balance->reserved);

        // Trial-balance-ish: all entries balanced
        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals(
                (float) $entry->total_debit,
                (float) $entry->total_credit,
                "Entry {$entry->entry_no} unbalanced",
            );
        }
    }

    public function test_sales_routes_require_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']); // no sales perms
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->get('/app/sales/orders')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/app/sales/invoices')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/pos')
            ->assertForbidden();

        $salesRole = $this->roleWith([
            'portal.erp.access',
            'sales.orders.view',
            'sales.invoices.view',
            'pos.sell',
        ]);
        $user->roles()->sync([$salesRole->id]);
        // Mirror RoleController/UserController: role assignment invalidates
        // the cached permission map (raw pivot sync does not fire the event).
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)
            ->get('/app/sales/orders')
            ->assertOk();

        $this->actingAs($user)
            ->get('/app/sales/invoices')
            ->assertOk();

        $this->actingAs($user)
            ->get('/pos')
            ->assertOk();
    }

    public function test_structural_seeders_ship_no_fake_sales_documents(): void
    {
        $this->assertSame(0, Quotation::query()->count());
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PosSession::query()->count());
        $this->assertSame(0, PosTransaction::query()->count());
        // Only opening stock movement from setUp
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }
}
