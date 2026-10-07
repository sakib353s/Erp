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
use App\Domain\Returns\Refund;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Invoice;
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
 * 02-38 Sales › POS › POS Return: a counter return against a POS invoice
 * runs the whole returns pipeline (request → receive → credit note →
 * refund). Cash refunds leave the open session's drawer (cash_out feeds
 * the close math); non-cash refunds never touch it. The route and the
 * menu leaf sit behind pos.returns.create.
 */
class PosReturnTest extends TestCase
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
            'code' => 'POSRET-1',
            'sku' => 'POSRET-SKU-1',
            'name' => 'POS Return Product',
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
            'idempotency_suffix' => 'posret-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-return-test', 'POST', [], [], [], [
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

    protected function returnPayload(Invoice $invoice, float $qty, array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id,
            'payment_method' => 'cash',
            'lines' => [
                ['invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'qty' => $qty],
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

    public function test_pos_return_full_path_refunds_stock_credit_and_drawer(): void
    {
        [$session, $txn] = $this->posSale();
        $invoice = $txn->invoice;
        $this->assertEquals(45.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);

        $this->actingAs($this->admin)
            ->post(route('pos.return'), $this->returnPayload($invoice, 2))
            ->assertRedirect(route('pos.returns.index'))
            ->assertSessionHas('status');

        $return = SalesReturn::query()->sole();
        $this->assertSame('refunded', $return->status);
        $this->assertSame('pos', $return->source);
        $this->assertSame($invoice->id, (int) $return->invoice_id);
        $this->assertNotNull($return->credit_note_id);

        // Stock back (45 + 2) via a real SALES_RETURN movement.
        $this->assertEquals(47.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        $this->assertTrue(
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_SALES_RETURN)
                ->where('source_type', 'sales_return')
                ->where('source_id', $return->id)
                ->exists()
        );

        // Refund posted with a balanced journal; invoice history intact.
        $refund = Refund::query()->where('sales_return_id', $return->id)->sole();
        $this->assertSame('posted', $refund->status);
        $this->assertSame('cash', $refund->method);
        $this->assertEquals(300.0, (float) $refund->amount); // 2 × 150
        $entry = JournalEntry::query()->findOrFail($refund->journal_entry_id);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        $invoice->refresh();
        $this->assertEquals(750.0, (float) $invoice->grand_total);

        // Cash refund leaves the drawer; close math balances to zero.
        $session->refresh();
        $this->assertEquals(750.0, (float) $session->cash_sales);
        $this->assertEquals(300.0, (float) $session->cash_out);
        $closed = app(ClosePosSession::class)->handle($session, 550.0, $this->httpRequest());
        $this->assertEquals(550.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);

        $audit = AuditEvent::query()
            ->where('action', 'pos.return_processed')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('sales_return', $audit->entity_type);
        $this->assertSame($return->id, (int) $audit->entity_id);
        $this->assertSame($return->return_no, $audit->after['return_no']);
        $this->assertSame($refund->refund_no, $audit->after['refund_no']);
        $this->assertSame($invoice->invoice_no, $audit->after['invoice_no']);
        $this->assertEquals(300.0, (float) $audit->after['amount']);
        $this->assertSame('cash', $audit->after['method']);
    }

    public function test_non_cash_pos_return_never_touches_the_drawer(): void
    {
        [$session, $txn] = $this->posSale();

        $this->actingAs($this->admin)
            ->post(route('pos.return'), $this->returnPayload($txn->invoice, 1, [
                'payment_method' => 'bank',
            ]))
            ->assertRedirect(route('pos.returns.index'));

        $refund = Refund::query()->sole();
        $this->assertSame('bank', $refund->method);
        $this->assertEquals(150.0, (float) $refund->amount);
        $this->assertNotNull($refund->journal_entry_id);

        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_out);
        $this->assertEquals(46.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);

        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        }
    }

    public function test_pos_return_rejects_non_pos_invoice(): void
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
            ->post(route('pos.return'), $this->returnPayload($invoice, 1))
            ->assertSessionHasErrors(['return']);

        $this->assertStringContainsString(
            'POS return requires a POS invoice',
            session('errors')->first('return'),
        );
        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_pos_return_rejects_qty_beyond_invoiced(): void
    {
        [$session, $txn] = $this->posSale();

        $this->actingAs($this->admin)
            ->post(route('pos.return'), $this->returnPayload($txn->invoice, 99))
            ->assertSessionHasErrors(['return']);

        $this->assertStringContainsString('exceeds remaining', session('errors')->first('return'));
        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertEquals(45.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        $this->assertEquals(0.0, (float) $session->fresh()->cash_out);
    }

    public function test_pos_return_requires_an_open_session(): void
    {
        [$session, $txn] = $this->posSale();
        app(ClosePosSession::class)->handle($session, 850.0, $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('pos.return'), $this->returnPayload($txn->invoice, 1))
            ->assertSessionHasErrors(['return']);

        $this->assertStringContainsString(
            'No open POS session',
            session('errors')->first('return'),
        );
        $this->assertSame(0, SalesReturn::query()->count());
    }

    public function test_pos_return_is_idempotent_by_key(): void
    {
        [$session, $txn] = $this->posSale();
        $payload = $this->returnPayload($txn->invoice, 2, [
            'idempotency_key' => 'pos-ret-key-1',
        ]);

        $this->actingAs($this->admin)->post(route('pos.return'), $payload)
            ->assertRedirect(route('pos.returns.index'));
        $this->actingAs($this->admin)->post(route('pos.return'), $payload)
            ->assertRedirect(route('pos.returns.index'));

        $this->assertSame(1, SalesReturn::query()->count());
        $this->assertSame(1, Refund::query()->count());
        $this->assertEquals(47.0, (float) StockBalance::query()
            ->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        $this->assertEquals(300.0, (float) $session->fresh()->cash_out);
    }

    public function test_pos_return_routes_require_permission(): void
    {
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->get(route('pos.returns.index'))
            ->assertForbidden();
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->post(route('pos.return'), [])
            ->assertForbidden();

        $granted = $this->userWith(['portal.erp.access', 'pos.returns.create']);
        $this->actingAs($granted)
            ->get(route('pos.returns.index'))
            ->assertOk()
            ->assertSee('POS Returns')
            ->assertSee('Look a POS invoice up by its number');

        // The screen honestly reports a miss.
        $this->actingAs($granted)
            ->get(route('pos.returns.index', ['invoice' => 'INV-NOPE']))
            ->assertOk()
            ->assertSee('No POS invoice matches');

        // And the granted user can actually process a return.
        [$session, $txn] = $this->posSale();
        $this->actingAs($granted)
            ->post(route('pos.return'), $this->returnPayload($txn->invoice, 1))
            ->assertRedirect(route('pos.returns.index'));
        $this->assertSame('refunded', SalesReturn::query()->sole()->status);
    }

    public function test_pos_return_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'POS Return')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos/returns', $leaf->route);
        $this->assertSame('pos.returns.create', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('POS Return');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('POS Return');
    }
}
