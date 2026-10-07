<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
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
use App\Domain\Returns\Exchange;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Sales\PosTransaction;
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
 * 02-39 Sales › POS › POS Exchange: one document over two legs — returned
 * stock restocks (SALES_RETURN in), new items leave on a fresh POS invoice
 * (IssueInvoice: GL + SALES_OUT + COGS), and the signed price difference
 * settles for real (receipt payment / refund journal / nothing), so the
 * ledger's net AR effect is zero and the drawer close math balances.
 * Returns and exchanges share the remaining-qty guard. The route and the
 * menu leaf sit behind pos.returns.exchange.
 */
class PosExchangeTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Product $productB;

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
            'code' => 'POSXCH-1',
            'sku' => 'POSXCH-SKU-1',
            'name' => 'POS Exchange Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $this->productB = app(CreateProduct::class)->handle([
            'code' => 'POSXCH-2',
            'sku' => 'POSXCH-SKU-2',
            'name' => 'POS Exchange Item B',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 80],
                ['product_id' => $this->productB->id, 'qty' => 30, 'unit_cost' => 120],
            ],
            'idempotency_suffix' => 'posxch-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-exchange-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** Open a session and commit a 5 × 150 cash sale (stock 50 → 45). */
    protected function posSale(): array
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
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        return [$session->fresh(), $txn];
    }

    protected function exchangePayload(Invoice $invoice, float $returnQty, float $exchangeQty, array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id,
            'payment_method' => 'cash',
            'lines' => [
                ['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => $returnQty],
            ],
            'exchange_lines' => [
                ['product_id' => $this->productB->id, 'qty' => $exchangeQty, 'unit_price' => 400],
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

    protected function balanceOnHand(Product $product): float
    {
        return (float) StockBalance::query()
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->on_hand;
    }

    public function test_pos_exchange_full_path_moves_both_legs_and_settles_the_difference(): void
    {
        [$session, $txn] = $this->posSale();
        $invoice = $txn->invoice;
        $this->assertEquals(45.0, $this->balanceOnHand($this->product));

        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($invoice, 2, 1))
            ->assertRedirect(route('pos.exchange.index'))
            ->assertSessionHas('status');

        $exchange = Exchange::query()->sole();
        $this->assertSame('completed', $exchange->status);
        $this->assertSame('cash', $exchange->payment_method);
        $this->assertStringStartsWith('EXC', $exchange->exchange_no);
        $this->assertSame($invoice->id, (int) $exchange->invoice_id);
        $this->assertNotNull($exchange->new_invoice_id);
        $this->assertEquals(300.0, (float) $exchange->return_total);   // 2 × 150 returned
        $this->assertEquals(400.0, (float) $exchange->exchange_total); // 1 × 400 taken
        $this->assertEquals(100.0, (float) $exchange->price_differential);

        $returnLine = $exchange->lines()->where('direction', 'return')->sole();
        $this->assertSame($this->product->id, (int) $returnLine->product_id);
        $this->assertEquals(2.0, (float) $returnLine->qty);
        $this->assertEquals(150.0, (float) $returnLine->unit_price);
        $this->assertNotNull($returnLine->invoice_line_id);

        $issueLine = $exchange->lines()->where('direction', 'issue')->sole();
        $this->assertSame($this->productB->id, (int) $issueLine->product_id);
        $this->assertEquals(1.0, (float) $issueLine->qty);
        $this->assertNull($issueLine->invoice_line_id);

        // Stock both legs: returned goods back in, exchanged goods out.
        $this->assertEquals(47.0, $this->balanceOnHand($this->product));
        $this->assertEquals(29.0, $this->balanceOnHand($this->productB));
        $this->assertTrue(
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_SALES_RETURN)
                ->where('source_type', 'exchange')
                ->where('source_id', $exchange->id)
                ->exists()
        );

        // New POS invoice settles in full (cash difference + goods credit);
        // the original invoice's history is untouched.
        $newInvoice = Invoice::query()->findOrFail($exchange->new_invoice_id);
        $this->assertSame('pos', $newInvoice->invoice_type);
        $this->assertSame('paid', $newInvoice->status);
        $this->assertEquals(400.0, (float) $newInvoice->grand_total);
        $this->assertEquals(400.0, (float) $newInvoice->paid_amount);
        $this->assertEquals(0.0, (float) $newInvoice->due_amount);
        $invoice->refresh();
        $this->assertEquals(750.0, (float) $invoice->grand_total);
        $this->assertSame('paid', $invoice->status);

        // Real money: one receipt of the positive difference.
        $payment = Payment::query()
            ->whereHas('allocations', function ($q) use ($newInvoice) {
                $q->where('allocatable_type', Invoice::class)
                    ->where('allocatable_id', $newInvoice->id);
            })
            ->sole();
        $this->assertEquals(100.0, (float) $payment->amount);
        $this->assertSame('cash', $payment->method);

        // The credit leg posted a balanced entry linked to the exchange.
        $creditEntry = JournalEntry::query()
            ->where('source_type', 'exchange')
            ->where('source_id', $exchange->id)
            ->where('source_event', 'sales_credit_note_issued')
            ->sole();
        $this->assertEquals((float) $creditEntry->total_debit, (float) $creditEntry->total_credit);
        $this->assertStringContainsString($exchange->exchange_no, $creditEntry->description);

        // Every journal entry balances and the AR account nets to zero:
        // sale (Dr 750 / Cr 750) + exchange (Dr 400 / Cr 300 / Cr 100).
        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        }
        $ar = Account::query()->where('code', '1130')->firstOrFail();
        $netAr = (float) JournalLine::query()
            ->where('account_id', $ar->id)
            ->selectRaw("coalesce(sum(case when dc = 'debit' then amount else -amount end), 0) as net")
            ->value('net');
        $this->assertEqualsWithDelta(0.0, $netAr, 0.0001);

        // The positive difference landed in the drawer and close balances.
        $session->refresh();
        $this->assertEquals(850.0, (float) $session->cash_sales);
        $this->assertEquals(0.0, (float) $session->cash_out);
        $closed = app(ClosePosSession::class)->handle($session, 950.0, $this->httpRequest());
        $this->assertEquals(950.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);

        $audit = AuditEvent::query()
            ->where('action', 'pos.exchange_processed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('exchange', $audit->entity_type);
        $this->assertSame($exchange->id, (int) $audit->entity_id);
        $this->assertSame($exchange->exchange_no, $audit->after['exchange_no']);
        $this->assertSame($invoice->invoice_no, $audit->after['invoice_no']);
        $this->assertSame($newInvoice->invoice_no, $audit->after['new_invoice_no']);
        $this->assertEquals(300.0, (float) $audit->after['return_total']);
        $this->assertEquals(400.0, (float) $audit->after['exchange_total']);
        $this->assertEquals(100.0, (float) $audit->after['price_differential']);
        $this->assertSame('cash', $audit->after['method']);
        $this->assertArrayNotHasKey('session_no', $audit->after);
    }

    public function test_non_cash_difference_never_touches_the_drawer(): void
    {
        [$session, $txn] = $this->posSale();

        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 2, 1, [
                'payment_method' => 'bank',
            ]))
            ->assertRedirect(route('pos.exchange.index'));

        $exchange = Exchange::query()->sole();
        $newInvoice = Invoice::query()->findOrFail($exchange->new_invoice_id);
        $payment = Payment::query()
            ->whereHas('allocations', function ($q) use ($newInvoice) {
                $q->where('allocatable_type', Invoice::class)
                    ->where('allocatable_id', $newInvoice->id);
            })
            ->sole();
        $this->assertSame('bank', $payment->method);
        $this->assertEquals(100.0, (float) $payment->amount);

        $session->refresh();
        $this->assertEquals(750.0, (float) $session->cash_sales);
        $this->assertEquals(100.0, (float) $session->non_cash_sales);
        $this->assertEquals(0.0, (float) $session->cash_out);

        // The exchange difference is a real counter transaction too.
        $this->assertSame(2, PosTransaction::query()->where('pos_session_id', $session->id)->count());
        $this->assertEquals(100.0, (float) PosTransaction::query()
            ->where('pos_session_id', $session->id)
            ->where('payment_method', 'bank')
            ->sole()
            ->total);

        $closed = app(ClosePosSession::class)->handle($session, 850.0, $this->httpRequest());
        $this->assertEquals(0.0, (float) $closed->variance);

        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        }
    }

    public function test_negative_difference_refunds_through_the_drawer(): void
    {
        [$session, $txn] = $this->posSale();

        // Return 300 of goods, take 100 back → the counter owes 200.
        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 2, 1, [
                'exchange_lines' => [
                    ['product_id' => $this->productB->id, 'qty' => 1, 'unit_price' => 100],
                ],
            ]))
            ->assertRedirect(route('pos.exchange.index'));

        $exchange = Exchange::query()->sole();
        $this->assertEquals(-200.0, (float) $exchange->price_differential);

        $refundEntry = JournalEntry::query()
            ->where('source_type', 'exchange')
            ->where('source_id', $exchange->id)
            ->where('source_event', 'refund')
            ->sole();
        $this->assertEquals((float) $refundEntry->total_debit, (float) $refundEntry->total_credit);

        // No cash was collected — only the original sale's payment exists.
        $this->assertSame(1, Payment::query()->count());

        // Goods credit still settles the new invoice in full.
        $newInvoice = Invoice::query()->findOrFail($exchange->new_invoice_id);
        $this->assertSame('paid', $newInvoice->status);
        $this->assertEquals(100.0, (float) $newInvoice->paid_amount);
        $this->assertEquals(0.0, (float) $newInvoice->due_amount);

        $session->refresh();
        $this->assertEquals(750.0, (float) $session->cash_sales);
        $this->assertEquals(200.0, (float) $session->cash_out);
        $this->assertSame(1, PosTransaction::query()->where('pos_session_id', $session->id)->count());

        $closed = app(ClosePosSession::class)->handle($session, 650.0, $this->httpRequest());
        $this->assertEquals(650.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);

        $this->assertEquals(47.0, $this->balanceOnHand($this->product));
        $this->assertEquals(29.0, $this->balanceOnHand($this->productB));
    }

    public function test_pos_exchange_rejects_non_pos_invoice(): void
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
        $invoice = $invoice->fresh();
        $this->assertNotSame('pos', $invoice->invoice_type);

        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($invoice, 1, 1))
            ->assertSessionHasErrors(['exchange']);

        $this->assertStringContainsString(
            'POS exchange requires a POS invoice',
            session('errors')->first('exchange'),
        );
        $this->assertSame(0, Exchange::query()->count());
        $this->assertEquals(45.0, $this->balanceOnHand($this->product));
    }

    public function test_pos_exchange_rejects_qty_beyond_invoiced(): void
    {
        [$session, $txn] = $this->posSale();

        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 99, 1))
            ->assertSessionHasErrors(['exchange']);

        $this->assertStringContainsString('exceeds remaining', session('errors')->first('exchange'));
        $this->assertSame(0, Exchange::query()->count());
        $this->assertEquals(45.0, $this->balanceOnHand($this->product));
        $this->assertEquals(0.0, (float) $session->fresh()->cash_out);
    }

    public function test_returns_and_exchanges_share_the_remaining_qty_guard(): void
    {
        [$session, $txn] = $this->posSale();

        // Exchange 3 of the 5 invoiced units first (stock 45 → 48).
        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 3, 1, [
                'exchange_lines' => [
                    ['product_id' => $this->productB->id, 'qty' => 1, 'unit_price' => 100],
                ],
            ]))
            ->assertRedirect(route('pos.exchange.index'));
        $this->assertEquals(48.0, $this->balanceOnHand($this->product));

        // A plain POS return cannot push past what the exchange already took.
        $this->actingAs($this->admin)
            ->post(route('pos.return'), [
                'invoice_id' => $txn->invoice->id,
                'payment_method' => 'cash',
                'lines' => [
                    ['invoice_line_id' => $txn->invoice->lines()->firstOrFail()->id, 'qty' => 3],
                ],
            ])
            ->assertSessionHasErrors(['return']);
        $this->assertStringContainsString('exceeds remaining', session('errors')->first('return'));

        // And a second exchange cannot either.
        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 3, 1))
            ->assertSessionHasErrors(['exchange']);
        $this->assertStringContainsString('exceeds remaining', session('errors')->first('exchange'));

        // The remaining 2 units are still returnable — the guard counts, it does not block.
        $this->actingAs($this->admin)
            ->post(route('pos.return'), [
                'invoice_id' => $txn->invoice->id,
                'payment_method' => 'cash',
                'lines' => [
                    ['invoice_line_id' => $txn->invoice->lines()->firstOrFail()->id, 'qty' => 2],
                ],
            ])
            ->assertRedirect(route('pos.returns.index'));

        $this->assertSame('refunded', SalesReturn::query()->sole()->status);
        $this->assertEquals(50.0, $this->balanceOnHand($this->product));
        $this->assertEquals(650.0, (float) $session->fresh()->cash_out); // 350 exchange refund + 300 return
    }

    public function test_pos_exchange_requires_an_open_session(): void
    {
        [$session, $txn] = $this->posSale();
        app(ClosePosSession::class)->handle($session, 850.0, $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 1, 1))
            ->assertSessionHasErrors(['exchange']);

        $this->assertStringContainsString(
            'No open POS session',
            session('errors')->first('exchange'),
        );
        $this->assertSame(0, Exchange::query()->count());
    }

    public function test_pos_exchange_is_idempotent_by_key(): void
    {
        [$session, $txn] = $this->posSale();
        $payload = $this->exchangePayload($txn->invoice, 2, 1, [
            'idempotency_key' => 'pos-xch-key-1',
        ]);

        $this->actingAs($this->admin)->post(route('pos.exchange'), $payload)
            ->assertRedirect(route('pos.exchange.index'));
        $this->actingAs($this->admin)->post(route('pos.exchange'), $payload)
            ->assertRedirect(route('pos.exchange.index'));

        $this->assertSame(1, Exchange::query()->count());
        $this->assertSame(2, Exchange::query()->sole()->lines()->count());
        $this->assertEquals(47.0, $this->balanceOnHand($this->product));
        $this->assertEquals(850.0, (float) $session->fresh()->cash_sales);
    }

    public function test_pos_exchange_routes_require_permission(): void
    {
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->get(route('pos.exchange.index'))
            ->assertForbidden();
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->post(route('pos.exchange'), [])
            ->assertForbidden();

        $granted = $this->userWith(['portal.erp.access', 'pos.returns.exchange']);
        $this->actingAs($granted)
            ->get(route('pos.exchange.index'))
            ->assertOk()
            ->assertSee('POS Exchange')
            ->assertSee('Look a POS invoice up by its number');

        // The screen honestly reports a miss.
        $this->actingAs($granted)
            ->get(route('pos.exchange.index', ['invoice' => 'INV-NOPE']))
            ->assertOk()
            ->assertSee('No POS invoice matches');

        // Product search rides the screen's own permission — no pos.sell needed.
        [$session, $txn] = $this->posSale();
        $this->actingAs($granted)
            ->get(route('pos.exchange.index', [
                'invoice' => $txn->invoice->invoice_no,
                'q' => 'POSXCH-SKU-2',
            ]))
            ->assertOk()
            ->assertSee('POS Exchange Item B');

        // And the granted user can actually process an exchange.
        $this->actingAs($granted)
            ->post(route('pos.exchange'), $this->exchangePayload($txn->invoice, 1, 1))
            ->assertRedirect(route('pos.exchange.index'));
        $this->assertSame(1, Exchange::query()->count());
    }

    public function test_pos_exchange_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'POS Exchange')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos/exchange', $leaf->route);
        $this->assertSame('pos.returns.exchange', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('POS Exchange');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('POS Exchange');
    }
}
