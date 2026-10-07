<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseReturn;
use App\Domain\Purchase\Services\GoodsReceiptService;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Purchase\Services\PurchaseReturnService;
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
 * 03.9 Purchase returns — the correction path for a posted receipt or bill.
 *
 * What this pins:
 *  · a return cannot exceed what arrived (received − already returned), so the
 *    same goods cannot be claimed from the supplier twice;
 *  · approving takes stock out through the ledger and posts the debit note
 *    (Dr accounts payable, Cr inventory or purchases, Cr tax payable);
 *  · a return tied to a bill credits that bill in the same transaction, and
 *    the bill's due stays equal to the AP it relieved;
 *  · maker ≠ checker, unposted returns are the only cancellable ones, and the
 *    screens are permission gated.
 */
class PurchaseReturnTest extends TestCase
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

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'RET-1',
            'sku' => 'RET-1-SKU',
            'name' => 'Return Probe',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $this->supplier = app(SupplierService::class)->create([
            'name' => 'Return Textiles',
            'phone' => '01722222222',
            'payment_terms_days' => 15,
        ], $this->admin->id);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__purchase-returns', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function accountId(string $code): int
    {
        return (int) Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->value('id');
    }

    /** An approved order, a posted receipt of `qty` and the receipt itself. */
    protected function postedReceipt(float $qty, float $cost = 100): array
    {
        $orders = app(PurchaseOrderService::class);

        $order = $orders->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => '2026-10-01',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Return Probe',
                'qty_ordered' => $qty,
                'unit_price' => $cost,
            ]],
        ], $this->admin->id);

        $order = $orders->approve($order, $this->makeUser()->id);

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $order->id,
            'received_date' => '2026-10-02',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->product->id,
                'qty_received' => $qty,
                'unit_cost' => $cost,
            ]],
        ], $this->admin->id);

        $posted = $receipts->post($receipt, $this->admin->id);

        return [$posted['receipt']->load('lines'), $order];
    }

    /** A posted bill for the given amount, with no receipt behind it. */
    protected function postedBill(float $net): \App\Domain\Purchase\Models\PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-01',
            'lines' => [['description' => 'Goods billed', 'qty' => 1, 'unit_cost' => $net]],
        ], $this->admin->id);

        return $bills->approve($bill, $this->makeUser()->id);
    }

    protected function returnPayload($receipt, array $overrides = []): array
    {
        $line = $receipt->refresh()->load('lines')->lines->first();

        return array_merge([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $this->warehouse->id,
            'return_date' => '2026-10-05',
            'reason' => 'Two bags arrived torn and damp.',
            'reason_code' => 'damaged',
            'lines' => [[
                'goods_receipt_line_id' => $line->id,
                'product_id' => $this->product->id,
                'description' => 'Return Probe',
                'qty' => 2,
                'unit_cost' => 100,
            ]],
        ], $overrides);
    }

    public function test_a_return_posts_stock_out_and_the_debit_note(): void
    {
        [$receipt] = $this->postedReceipt(10);
        $service = app(PurchaseReturnService::class);

        $return = $service->create($this->returnPayload($receipt), $this->admin->id);

        $this->assertSame('draft', $return->status);
        $this->assertSame('200.0000', (string) $return->total);

        $approver = $this->makeUser();
        $service->approve($return, $approver->id);

        $return->refresh();
        $this->assertSame('approved', $return->status);
        $this->assertSame('posted', $return->posting_state);

        // Stock left the shelf through the immutable ledger.
        $movement = StockMovement::query()
            ->where('source_type', 'purchase_return')
            ->where('source_id', $return->id)
            ->firstOrFail();

        $this->assertSame(StockMovement::TYPE_PURCHASE_RETURN_OUT, $movement->movement_type);
        $this->assertSame('-2.0000', (string) $movement->qty_signed);

        $onHand = (float) StockBalance::query()
            ->where('company_id', $this->admin->company_id)
            ->where('product_id', $this->product->id)
            ->value('on_hand');

        $this->assertSame(8.0, $onHand, 'ten arrived, two went back');

        // The debit note: Dr accounts payable, Cr inventory.
        $entry = JournalEntry::query()->with('lines')->findOrFail($return->journal_entry_id);
        $byAccount = $entry->lines->mapWithKeys(fn ($line) => [
            (int) $line->account_id => [strtoupper($line->dc), (string) $line->amount],
        ]);

        $this->assertSame(['DEBIT', '200.0000'], $byAccount[$this->accountId('2110')]);
        $this->assertSame(['CREDIT', '200.0000'], $byAccount[$this->accountId('1140')]);
        $this->assertSame('200.0000', (string) $entry->total_debit);
    }

    public function test_you_cannot_return_more_than_arrived_minus_what_already_went_back(): void
    {
        [$receipt] = $this->postedReceipt(5);
        $service = app(PurchaseReturnService::class);

        $first = $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 4])],
        ]), $this->admin->id);

        $service->approve($first, $this->makeUser()->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only 1.0000 of the received quantity is still returnable');

        $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 2])],
        ]), $this->admin->id);
    }

    public function test_a_return_against_a_bill_credits_that_bill_and_keeps_the_ledger_agreeing(): void
    {
        [$receipt] = $this->postedReceipt(10);

        // Bill the whole delivery, then send two back.
        $bills = app(PurchaseBillService::class);
        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_id' => $receipt->id,
            'bill_date' => '2026-10-03',
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines->first()->id,
                'product_id' => $this->product->id,
                'description' => 'Return Probe',
                'qty' => 10,
                'unit_cost' => 100,
            ]],
        ], $this->admin->id);

        $bill = $bills->approve($bill, $this->makeUser()->id);
        $this->assertSame('1000.0000', (string) $bill->due_amount);

        $service = app(PurchaseReturnService::class);
        $return = $service->create($this->returnPayload($receipt, [
            'purchase_bill_id' => $bill->id,
        ]), $this->admin->id);

        $service->approve($return, $this->makeUser()->id);

        $bill->refresh();
        $this->assertSame('200.0000', (string) $bill->credited_amount);
        $this->assertSame('800.0000', (string) $bill->due_amount, 'total − paid − credited');
        $this->assertSame('partially_paid', $bill->status);

        // AP relieved by the return equals the drop in the bill's balance.
        $entry = JournalEntry::query()->with('lines')->findOrFail($return->refresh()->journal_entry_id);
        $apDebit = (float) $entry->lines
            ->where('account_id', $this->accountId('2110'))
            ->where('dc', 'debit')
            ->sum('amount');

        $this->assertSame(200.0, $apDebit);
    }

    public function test_a_return_may_not_claim_more_than_the_bill_still_owes(): void
    {
        [$receipt] = $this->postedReceipt(10);

        $bills = app(PurchaseBillService::class);
        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_id' => $receipt->id,
            'bill_date' => '2026-10-03',
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines->first()->id,
                'product_id' => $this->product->id,
                'description' => 'Return Probe',
                'qty' => 3,
                'unit_cost' => 100,
            ]],
        ], $this->admin->id);

        $bill = $bills->approve($bill, $this->makeUser()->id);

        // A 4-unit return against a 3-unit bill is refused rather than turned
        // into an unapplied debit note this slice does not track.
        $service = app(PurchaseReturnService::class);
        $return = $service->create($this->returnPayload($receipt, [
            'purchase_bill_id' => $bill->id,
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 4])],
        ]), $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only has 300.00 outstanding');

        $service->approve($return, $this->makeUser()->id);
    }

    public function test_a_service_bill_return_credits_purchases_not_inventory(): void
    {
        $bill = $this->postedBill(500);

        $service = app(PurchaseReturnService::class);
        $return = $service->create([
            'supplier_id' => $this->supplier->id,
            'purchase_bill_id' => $bill->id,
            'return_date' => '2026-10-06',
            'reason' => 'Cleaning work was not completed as invoiced.',
            'reason_code' => 'other',
            'lines' => [['description' => 'Cleaning service', 'qty' => 1, 'unit_cost' => 200]],
        ], $this->admin->id);

        $service->approve($return, $this->makeUser()->id);

        $entry = JournalEntry::query()->with('lines')->findOrFail($return->refresh()->journal_entry_id);
        $byAccount = $entry->lines->mapWithKeys(fn ($line) => [
            (int) $line->account_id => [strtoupper($line->dc), (string) $line->amount],
        ]);

        $this->assertSame(['DEBIT', '200.0000'], $byAccount[$this->accountId('2110')]);
        $this->assertSame(['CREDIT', '200.0000'], $byAccount[$this->accountId('5225')]);
        $this->assertArrayNotHasKey($this->accountId('1140'), $byAccount, 'a service return must not touch inventory');
        $this->assertSame('300.0000', (string) $bill->refresh()->due_amount);
    }

    public function test_the_maker_cannot_approve_their_own_return(): void
    {
        [$receipt] = $this->postedReceipt(5);
        $service = app(PurchaseReturnService::class);

        $return = $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 1])],
        ]), $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not approve it');

        $service->approve($return, $this->admin->id);
    }

    public function test_cancelling_is_only_for_unposted_returns_and_needs_a_reason(): void
    {
        [$receipt] = $this->postedReceipt(5);
        $service = app(PurchaseReturnService::class);

        $return = $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 1])],
        ]), $this->admin->id);

        try {
            $service->cancel($return, '   ', $this->admin->id);
            $this->fail('An empty reason should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requires a reason', $e->getMessage());
        }

        $cancelled = $service->cancel($return, 'Quality inspection cleared the goods.', $this->admin->id);
        $this->assertSame('cancelled', $cancelled->status);

        // Nothing was posted for a cancelled return.
        $this->assertNull($cancelled->journal_entry_id);
        $this->assertSame(0, StockMovement::query()->where('source_type', 'purchase_return')->count());

        // A posted return, by contrast, cannot be cancelled at all.
        $posted = $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 1])],
        ]), $this->admin->id);
        $service->approve($posted, $this->makeUser()->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be cancelled');

        $service->cancel($posted->refresh(), 'Changed our mind.', $this->admin->id);
    }

    public function test_a_return_needs_a_real_reason_and_at_least_one_line(): void
    {
        [$receipt] = $this->postedReceipt(5);
        $service = app(PurchaseReturnService::class);

        try {
            $service->create($this->returnPayload($receipt, ['reason' => '  ']), $this->admin->id);
            $this->fail('A return without a reason should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least one line');

        $service->create($this->returnPayload($receipt, ['lines' => []]), $this->admin->id);
    }

    public function test_the_return_screens_are_permission_gated(): void
    {
        [$receipt] = $this->postedReceipt(5);
        $service = app(PurchaseReturnService::class);

        $return = $service->create($this->returnPayload($receipt, [
            'lines' => [array_merge($this->returnPayload($receipt)['lines'][0], ['qty' => 1])],
        ]), $this->admin->id);

        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith(['portal.erp.access', 'purchase.returns.view'])->id);

        $this->actingAs($viewer)->get('/app/purchase/returns')->assertOk();
        $this->actingAs($viewer)->get('/app/purchase/returns/'.$return->id)->assertOk();
        $this->actingAs($viewer)->get('/app/purchase/returns/create')->assertForbidden();
        // Seeing a return does not grant the right to approve one.
        $this->actingAs($viewer)->post('/app/purchase/returns/'.$return->id.'/approve')->assertForbidden();

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access'])->id);
        $this->actingAs($outsider)->get('/app/purchase/returns')->assertForbidden();

        $this->assertSame('draft', $return->refresh()->status, 'a viewer could not push it through');
    }
}
