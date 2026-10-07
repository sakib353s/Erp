<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\Actions\IssueCreditNote;
use App\Domain\Returns\Actions\ProcessRefund;
use App\Domain\Returns\Actions\ReceiveReturnedGoods;
use App\Domain\Returns\Refund;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\ConvertQuotationToOrder;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\HoldPosOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Actions\ResumePosOrder;
use App\Domain\Sales\Actions\ReviseQuotation;
use App\Domain\Sales\CreditNote;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PosHold;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\Services\PosReportService;
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
 * Phase G remainders: X/Z reports, hold/resume, quotation revise/convert,
 * delivery challan, returns/credit notes/refunds.
 */
class SalesRemainderTest extends TestCase
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
            'code' => 'REM-1',
            'sku' => 'REM-SKU-1',
            'name' => 'Remainder Product',
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
            'idempotency_suffix' => 'rem-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__remainder-test', 'POST', [], [], [], [
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

    protected function issuedInvoice(): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice, $this->httpRequest());

        return $invoice->fresh();
    }

    public function test_pos_x_report_totals_come_from_session_transactions(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 100, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 400,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'bank',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 200],
            ],
        ], $this->httpRequest());

        $report = app(PosReportService::class)->x($session->fresh());

        $this->assertSame('X', $report['kind']);
        $this->assertSame(2, $report['count']);
        $this->assertEquals(500.0, (float) $report['gross_total']);
        $this->assertEquals(300.0, (float) $report['cash_sales']);
        $this->assertEquals(200.0, (float) $report['non_cash_sales']);
        $this->assertEquals(400.0, (float) $report['expected_cash']); // 100 + 300
        $this->assertEquals(500.0, (float) $report['receipt_total']);
    }

    public function test_pos_z_report_after_close_reflects_variance(): void
    {
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 50, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 300,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ClosePosSession::class)->handle($session, 300.0, $this->httpRequest());

        $report = app(PosReportService::class)->z($session->fresh());
        $this->assertSame('Z', $report['kind']);
        $this->assertSame('closed', $report['session']['status']);
        $this->assertEquals(350.0, (float) $report['expected_cash']); // 50 + 300
        $this->assertEquals(300.0, (float) $report['closing_counted']);
        $this->assertEquals(-50.0, (float) $report['variance']);
    }

    public function test_pos_hold_and_resume_releases_then_re_reserves_stock(): void
    {
        $hold = app(HoldPosOrder::class)->handle([
            'pos_session_id' => null,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        $this->assertSame('held', $hold->status);
        $this->assertCount(1, $hold->lines);
        $this->assertStringStartsWith('HOLD-', $hold->hold_no);

        // While held: no reservation is kept
        $this->assertSame(0, StockReservation::query()->where('source_type', 'pos_hold')->where('status', 'active')->count());
        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(0, (float) $balance->reserved);

        $resumed = app(ResumePosOrder::class)->handle($hold, $this->httpRequest());
        $this->assertSame('resumed', $resumed->status);
        $this->assertNotNull($resumed->resumed_at);

        $this->assertSame(1, StockReservation::query()
            ->where('source_type', 'pos_hold')
            ->where('source_id', $hold->id)
            ->where('status', 'active')
            ->count());

        $balance = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals(3, (float) $balance->reserved);
    }

    public function test_pos_resume_surfaces_insufficient_stock(): void
    {
        $hold = app(HoldPosOrder::class)->handle([
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_price' => 100],
            ],
        ], $this->httpRequest());

        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        // Consume nearly all stock so resume conflicts
        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 10000,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 48, 'unit_price' => 100],
            ],
        ], $this->httpRequest());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient available stock/');

        app(ResumePosOrder::class)->handle($hold, $this->httpRequest());
    }

    public function test_quotation_revise_creates_new_revision_immutable_original(): void
    {
        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());
        $originalTotal = (float) $quote->grand_total;

        $revision = app(ReviseQuotation::class)->handle($quote, [
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 175],
            ],
        ], $this->httpRequest());

        $this->assertSame(2, (int) $revision->revision);
        $this->assertSame($quote->id, (int) $revision->revision_of);
        $this->assertNotSame($quote->quote_no, $revision->quote_no);
        $this->assertSame('draft', $revision->status);

        $quote->refresh();
        $this->assertSame('draft', $quote->status);
        $this->assertEquals($originalTotal, (float) $quote->grand_total);
        $this->assertNotEquals($originalTotal, (float) $revision->grand_total);
        $this->assertSame(2, Quotation::query()->where('company_id', $this->admin->company_id)->count());
    }

    public function test_convert_quotation_to_order_maps_lines_with_provenance(): void
    {
        $quote = app(CreateQuotation::class)->handle($this->orderPayload(), $this->httpRequest());

        $order = app(ConvertQuotationToOrder::class)->handle($quote, [
            'warehouse_id' => $this->warehouse->id,
        ], $this->httpRequest());

        $this->assertSame('pending', $order->status);
        $this->assertFalse((bool) $order->stock_reserved);
        $this->assertSame($quote->id, (int) $order->source_quotation_id);
        $this->assertSame((float) $quote->grand_total, (float) $order->grand_total);
        $this->assertCount($quote->lines->count(), $order->lines);
        $this->assertEquals((float) $quote->lines->first()->qty, (float) $order->lines->first()->qty);

        $quote->refresh();
        $this->assertSame('converted', $quote->status);

        // Stock reserved only on confirm — not on convert
        $this->assertSame(0, StockReservation::query()->where('source_type', 'sales_order')->count());

        $this->expectException(\RuntimeException::class);
        app(ConvertQuotationToOrder::class)->handle($quote, [], $this->httpRequest());
    }

    public function test_delivery_challan_is_doc_only_and_moves_order_ready_to_ship(): void
    {
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $stockBefore = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $journalBefore = JournalEntry::query()->count();

        $challan = app(CreateDeliveryChallan::class)->handle($order->fresh(), [
            'courier_name' => 'SA Paribahan',
            'tracking_no' => 'TRK-1',
            'notes' => 'Leave at gate',
        ], $this->httpRequest());

        $this->assertSame('draft', $challan->status);
        $this->assertSame('DELIVERY CHALLAN', $challan->printed_title);
        $this->assertStringStartsWith('DC', $challan->challan_no);
        $this->assertCount(1, $challan->lines);
        $this->assertSame('SA Paribahan', $challan->courier_name);

        $order->refresh();
        $this->assertSame('ready_to_ship', $order->status);

        // No stock issue, no GL at challan create
        $stockAfter = StockBalance::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->assertEquals((float) $stockBefore->on_hand, (float) $stockAfter->on_hand);
        $this->assertEquals((float) $stockBefore->reserved, (float) $stockAfter->reserved);
        $this->assertSame($journalBefore, JournalEntry::query()->count());
    }

    public function test_return_request_receive_credit_and_refund_full_path(): void
    {
        $invoice = $this->issuedInvoice();
        $this->assertSame('issued', $invoice->status);
        $this->assertEquals(45.0, (float) StockBalance::query()->where('product_id', $this->product->id)->firstOrFail()->on_hand);
        // 50 - 5 issued

        $invoiceLine = $invoice->lines()->firstOrFail();

        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [
                ['invoice_line_id' => $invoiceLine->id, 'qty' => 2],
            ],
            'notes' => 'Damaged on arrival',
        ], $this->httpRequest());

        $this->assertSame('requested', $salesReturn->status);
        $this->assertStringStartsWith('RET-', $salesReturn->return_no);
        $this->assertEquals(300.0, (float) $salesReturn->grand_total); // 2 × 150
        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovement::TYPE_SALES_RETURN)->count());

        // Qty guard: cannot return more than invoiced
        try {
            app(CreateReturnRequest::class)->handle([
                'invoice_id' => $invoice->id,
                'lines' => [
                    ['invoice_line_id' => $invoiceLine->id, 'qty' => 10],
                ],
            ], $this->httpRequest());
            $this->fail('Expected qty guard RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceeds remaining', $e->getMessage());
        }

        $received = app(ReceiveReturnedGoods::class)->handle($salesReturn, [], $this->httpRequest());
        $this->assertSame('received', $received->status);

        $this->assertTrue(
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_SALES_RETURN)
                ->where('source_type', 'sales_return')
                ->where('source_id', $salesReturn->id)
                ->exists()
        );
        $this->assertEquals(47.0, (float) StockBalance::query()->where('product_id', $this->product->id)->firstOrFail()->on_hand);

        $creditNote = app(IssueCreditNote::class)->handle($salesReturn, [], $this->httpRequest());
        $this->assertSame('issued', $creditNote->status);
        $this->assertSame('posted', $creditNote->posting_state);
        $this->assertNotNull($creditNote->journal_entry_id);
        $this->assertSame('CREDIT NOTE', $creditNote->printed_title);
        $this->assertNotEmpty($creditNote->credit_note_no);

        $salesReturn->refresh();
        $this->assertSame('credited', $salesReturn->status);
        $this->assertSame($creditNote->id, (int) $salesReturn->credit_note_id);

        // Invoice history not rewritten
        $invoice->refresh();
        $this->assertEquals(750.0, (float) $invoice->grand_total);

        $refund = app(ProcessRefund::class)->handle($salesReturn, [
            'method' => 'cash',
            'idempotency_key' => 'refund-key-'.uniqid(),
        ], $this->httpRequest());

        $this->assertSame('posted', $refund->status);
        $this->assertEquals(300.0, (float) $refund->amount);
        $this->assertNotNull($refund->journal_entry_id);
        $this->assertSame('refunded', $salesReturn->fresh()->status);

        // All journal entries balanced
        foreach (JournalEntry::query()->get() as $entry) {
            $this->assertEquals(
                (float) $entry->total_debit,
                (float) $entry->total_credit,
                "Entry {$entry->entry_no} unbalanced",
            );
        }

        // Refund idempotency: same key returns same refund
        $again = app(ProcessRefund::class)->handle($salesReturn->fresh(), [
            'method' => 'cash',
            'idempotency_key' => $refund->idempotency_key,
        ], $this->httpRequest());
        $this->assertSame($refund->id, $again->id);
        $this->assertSame(1, Refund::query()->count());
    }

    public function test_refund_requires_credit_path_status(): void
    {
        $invoice = $this->issuedInvoice();
        $invoiceLine = $invoice->lines()->firstOrFail();

        $salesReturn = app(CreateReturnRequest::class)->handle([
            'invoice_id' => $invoice->id,
            'lines' => [['invoice_line_id' => $invoiceLine->id, 'qty' => 1]],
        ], $this->httpRequest());

        $this->expectException(\RuntimeException::class);
        app(ProcessRefund::class)->handle($salesReturn, ['amount' => 10], $this->httpRequest());
    }

    public function test_remainder_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/returns')->assertForbidden();
        $this->actingAs($user)->get('/app/sales/delivery-challans')->assertForbidden();
        $this->actingAs($user)->get('/pos/holds')->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'returns.view',
            'sales.delivery.view',
            'sales.delivery.create',
            'pos.hold',
            'pos.reports.x',
            'sales.quotations.revise',
            'sales.quotations.convert',
            'sales.orders.view',
            'sales.orders.confirm',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/returns')->assertOk();
        $this->actingAs($user)->get('/app/sales/delivery-challans')->assertOk();
        $this->actingAs($user)->get('/pos/holds')->assertOk();

        // No session for X report yet — open one first
        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );
        $this->actingAs($user)
            ->get("/pos/sessions/{$session->id}/x-report")
            ->assertOk();

        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $this->actingAs($user)
            ->post("/app/sales/orders/{$order->id}/challan")
            ->assertRedirect();
        $this->assertSame(1, DeliveryChallan::query()->count());
    }

    public function test_structural_seeders_ship_no_fake_return_documents(): void
    {
        $this->assertSame(0, SalesReturn::query()->count());
        $this->assertSame(0, CreditNote::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, DeliveryChallan::query()->count());
        $this->assertSame(0, PosHold::query()->count());
    }
}
