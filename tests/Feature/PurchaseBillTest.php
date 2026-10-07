<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Services\GoodsReceiptService;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Purchase\Services\SupplierService;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\PurchaseCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 03.6 Purchase bills — the point where a delivery becomes money owed.
 *
 * What this pins:
 *  · bill money is recomputed from the lines; the due date follows the
 *    supplier's terms;
 *  · only a POSTED goods receipt can be billed, and the postings are what
 *    create the payable — a draft bill changes nothing in the ledger;
 *  · approving posts a real, balanced journal entry through posting_rules
 *    (Dr inventory / Dr input tax / Cr accounts payable), and the account
 *    codes come from data, not from the service;
 *  · goods bills capitalise inventory while direct/service bills land on
 *    purchases & services — the same event cannot mean two different things;
 *  · the maker can never approve their own bill, and approval is its own
 *    permission;
 *  · a three-way mismatch is recorded on the bill, not swallowed;
 *  · an unposted bill can be cancelled with a reason; a posted one cannot.
 */
class PurchaseBillTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(PurchaseCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = $this->makeProduct('BILL-1', 'Billed Probe');
        $this->supplier = app(SupplierService::class)->create([
            'name' => 'Nile Textiles',
            'phone' => '01700000000',
            'payment_terms_days' => 30,
        ], $this->admin->id);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__purchase-bills', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $code, string $name): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    /** An approved order with 10 units at 250, plus a posted receipt of 10. */
    protected function postedReceipt(float $qty = 10, float $cost = 250): \App\Domain\Purchase\Models\GoodsReceipt
    {
        $orders = app(PurchaseOrderService::class);

        $order = $orders->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-01',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Billed probe',
                'qty_ordered' => 10,
                'unit_price' => 250,
            ]],
        ], $this->admin->id);

        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $receipts = app(GoodsReceiptService::class);

        $receipt = $receipts->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-03',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => $qty,
                'unit_cost' => $cost,
            ]],
        ], $this->admin->id);

        $receipts->post($receipt, $this->admin->id);

        return $receipt->refresh();
    }

    protected function accountId(string $code): int
    {
        return (int) Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->value('id');
    }

    public function test_bill_totals_come_from_the_lines_and_the_due_date_from_terms(): void
    {
        $bill = app(PurchaseBillService::class)->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-05',
            'supplier_bill_no' => 'NT-8841',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Billed probe',
                'qty' => 10,
                'unit_cost' => 250,
                'discount' => 100,
                'tax_rate' => 5,
            ]],
        ], $this->admin->id);

        // 10 × 250 = 2500, − 100 = 2400, + 5% = 120 → 2520
        $this->assertSame('2500.0000', (string) $bill->subtotal);
        $this->assertSame('100.0000', (string) $bill->discount_total);
        $this->assertSame('120.0000', (string) $bill->tax_total);
        $this->assertSame('2520.0000', (string) $bill->total);
        $this->assertSame('2520.0000', (string) $bill->due_amount);
        $this->assertSame('BILL-00001', $bill->code);
        $this->assertSame('draft', $bill->status);
        $this->assertSame('draft', $bill->posting_state);
        $this->assertSame('2026-11-04', $bill->due_date->toDateString(), '30-day terms from the bill date');
    }

    public function test_only_a_posted_receipt_can_be_billed(): void
    {
        $orders = app(PurchaseOrderService::class);

        $order = $orders->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-01',
            'lines' => [['product_id' => $this->product->id, 'qty_ordered' => 5, 'unit_price' => 100]],
        ], $this->admin->id);

        $orders->submit($order, $this->admin->id);
        $orders->approve($order->refresh(), $this->makeUser()->id);

        $draft = app(GoodsReceiptService::class)->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => '2026-10-04',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => 5,
                'unit_cost' => 100,
            ]],
        ], $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a posted goods receipt can be billed');

        app(PurchaseBillService::class)->createFromReceipt($draft, [], $this->admin->id);
    }

    public function test_billing_a_receipt_copies_its_lines_and_links_the_document(): void
    {
        $receipt = $this->postedReceipt();

        $bill = app(PurchaseBillService::class)->createFromReceipt(
            $receipt,
            ['supplier_bill_no' => 'NT-9001', 'bill_date' => '2026-10-06'],
            $this->admin->id,
        );

        $this->assertSame($receipt->id, $bill->goods_receipt_id);
        $this->assertSame($receipt->purchase_order_id, $bill->purchase_order_id);
        $this->assertSame('NT-9001', $bill->supplier_bill_no);
        $this->assertCount(1, $bill->lines);
        $this->assertSame('10.0000', (string) $bill->lines->first()->qty);
        $this->assertSame('250.0000', (string) $bill->lines->first()->unit_cost);
        $this->assertSame('2500.0000', (string) $bill->total);
    }

    public function test_approval_posts_a_balanced_payable_journal_entry(): void
    {
        $receipt = $this->postedReceipt();
        $service = app(PurchaseBillService::class);

        $bill = $service->createFromReceipt($receipt, ['bill_date' => '2026-10-06'], $this->admin->id);
        $service->submit($bill, $this->admin->id);

        $approver = $this->makeUser();
        $posted = $service->approve($bill->refresh(), $approver->id);

        $this->assertSame('approved', $posted->status);
        $this->assertSame('posted', $posted->posting_state);
        $this->assertSame($approver->id, $posted->approved_by);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertSame('matched', $posted->match_state);

        $entry = JournalEntry::query()->with('lines')->findOrFail($posted->journal_entry_id);

        $this->assertSame('2500.0000', (string) $entry->total_debit);
        $this->assertSame('2500.0000', (string) $entry->total_credit);
        $this->assertSame('posted', $entry->posting_state);

        $byAccount = $entry->lines->mapWithKeys(fn ($line) => [
            (int) $line->account_id => [$line->dc, (string) $line->amount],
        ]);

        $this->assertSame(['debit', '2500.0000'], $byAccount[$this->accountId('1140')], 'inventory is debited');
        $this->assertSame(['credit', '2500.0000'], $byAccount[$this->accountId('2110')], 'accounts payable is credited');
        $this->assertArrayNotHasKey($this->accountId('2120'), $byAccount->all(), 'a zero tax line is never posted');

        // The payable is now part of what the company owes.
        $this->assertSame(2500.0, (float) PurchaseBill::query()->open()->sum('due_amount'));
    }

    public function test_a_taxed_bill_posts_input_tax_in_the_same_entry(): void
    {
        $service = app(PurchaseBillService::class);

        $bill = $service->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-07',
            'lines' => [[
                'description' => 'VAT-bearing purchase',
                'qty' => 4,
                'unit_cost' => 500,
                'tax_rate' => 15,
            ]],
        ], $this->admin->id);

        $entry = JournalEntry::query()->with('lines')->findOrFail(
            $service->approve($bill, $this->makeUser()->id)->journal_entry_id
        );

        $byAccount = $entry->lines->mapWithKeys(fn ($line) => [
            (int) $line->account_id => [strtoupper($line->dc), (string) $line->amount],
        ]);

        // 4 × 500 = 2000 net, 15% = 300 tax, total 2300 — a service bill with no
        // receipt capitalises nothing, so the net lands on purchases & services.
        $this->assertSame(['DEBIT', '2000.0000'], $byAccount[$this->accountId('5225')]);
        $this->assertSame(['DEBIT', '300.0000'], $byAccount[$this->accountId('2120')]);
        $this->assertSame(['CREDIT', '2300.0000'], $byAccount[$this->accountId('2110')]);
        $this->assertSame('2300.0000', (string) $entry->total_debit);
    }

    public function test_the_maker_cannot_approve_their_own_bill(): void
    {
        $bill = app(PurchaseBillService::class)->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-08',
            'lines' => [['description' => 'Solo entry', 'qty' => 1, 'unit_cost' => 100]],
        ], $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('you cannot approve it');

        app(PurchaseBillService::class)->approve($bill, $this->admin->id);
    }

    public function test_billing_more_than_was_received_is_recorded_as_a_mismatch_not_swallowed(): void
    {
        $receipt = $this->postedReceipt(qty: 6, cost: 250);
        $service = app(PurchaseBillService::class);

        $bill = $service->createFromReceipt($receipt, ['bill_date' => '2026-10-09'], $this->admin->id);

        // The supplier's paper claims two units more than actually arrived.
        $bill->lines->first()->forceFill(['qty' => 8])->save();
        $service->recalculate($bill);

        $posted = $service->approve($bill->refresh(), $this->makeUser()->id);

        $this->assertSame('qty_mismatch', $posted->match_state);
        $this->assertStringContainsString('billed 8 vs received 6', (string) $posted->match_summary);
        $this->assertSame('approved', $posted->status, 'the real liability is still recognised');
        $this->assertNotNull($posted->journal_entry_id);
    }

    public function test_cancelling_an_unposted_bill_needs_a_reason_and_posted_bills_are_immutable(): void
    {
        $service = app(PurchaseBillService::class);

        $bill = $service->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-10',
            'lines' => [['description' => 'Wrong paperwork', 'qty' => 1, 'unit_cost' => 50]],
        ], $this->admin->id);

        try {
            $service->cancel($bill, '   ', $this->admin->id);
            $this->fail('A reason-less cancellation was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requires a reason', $e->getMessage());
        }

        $service->cancel($bill->refresh(), 'Wrong supplier paper', $this->admin->id);
        $this->assertSame('cancelled', $bill->refresh()->status);

        $posted = $service->approve(
            $service->create([
                'supplier_id' => $this->supplier->id,
                'bill_date' => '2026-10-11',
                'lines' => [['description' => 'Correct bill', 'qty' => 1, 'unit_cost' => 75]],
            ], $this->admin->id),
            $this->makeUser()->id,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('raise a credit note instead');

        $service->cancel($posted, 'Changed my mind', $this->admin->id);
    }

    public function test_the_approve_route_is_gated_on_the_approve_permission(): void
    {
        $service = app(PurchaseBillService::class);
        $bill = $service->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-12',
            'lines' => [['description' => 'Gated bill', 'qty' => 1, 'unit_cost' => 200]],
        ], $this->admin->id);

        $accountant = $this->makeUser();
        $accountant->roles()->attach($this->roleWith([
            'portal.erp.access', 'purchase.bills.view', 'purchase.bills.create',
        ])->id);

        $this->actingAs($accountant)
            ->post('/app/purchase/bills/'.$bill->id.'/approve')
            ->assertForbidden();

        $this->actingAs($accountant)->get('/app/purchase/bills')->assertOk();
        $this->actingAs($accountant)->get('/app/purchase/bills/'.$bill->id)->assertOk();
    }

    public function test_payables_reach_the_supplier_profile_and_the_bill_summary(): void
    {
        $receipt = $this->postedReceipt();
        $service = app(PurchaseBillService::class);
        $bill = $service->createFromReceipt($receipt, ['bill_date' => now()->subDays(40)->toDateString()], $this->admin->id);
        $service->approve($bill, $this->makeUser()->id);

        $query = app(\App\Domain\Purchase\Queries\PurchaseQuery::class);

        $payables = $query->supplierPayables($this->supplier->id);
        $this->assertSame(2500.0, $payables['due']);
        $this->assertSame(2500.0, $payables['overdue'], 'a 40-day-old bill with 30-day terms is overdue');
        $this->assertSame(1, $payables['buckets']['d31_60']['count']);

        $summary = $query->billSummary();
        $this->assertSame(2500.0, $summary['payable']);
        $this->assertSame(1, $summary['open_bills']);
        $this->assertSame(0, $summary['awaiting']);

        $this->actingAs($this->admin)->get('/app/suppliers/'.$this->supplier->id)->assertOk();
    }
}
