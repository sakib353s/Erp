<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\PurchaseReturnService;
use App\Domain\Purchase\Services\SupplierPaymentService;
use App\Domain\Purchase\Services\SupplierService;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\PurchaseCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §06 supplier account — the ledger, the ageing screen and the statement.
 *
 * What this pins:
 *  · the account is built from documents (bills credit, payments and returns
 *    debit) and nothing else — a draft bill or a cancelled return is not money;
 *  · the running balance is a running balance: every row carries the balance
 *    after it, and closing equals billed − settled for the period;
 *  · a range's opening balance carries everything from before it;
 *  · ageing buckets follow each bill's own due date, so a bill with no due
 *    date is never called late;
 *  · the statement is printable and exportable from the same rows.
 */
class SupplierLedgerTest extends TestCase
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
            'code' => 'LED-1',
            'sku' => 'LED-1-SKU',
            'name' => 'Ledger Probe',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $this->supplier = app(SupplierService::class)->create([
            'name' => 'Ledger Textiles',
            'phone' => '01733333333',
            'payment_terms_days' => 15,
        ], $this->admin->id);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__supplier-ledger', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function bill(float $net, string $billDate, ?string $dueDate = null): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Billed goods',
                'qty' => 1,
                'unit_cost' => $net,
            ]],
        ], $this->admin->id);

        return $bills->approve($bill, $this->makeUser()->id);
    }

    protected function query(): PurchaseQuery
    {
        return app(PurchaseQuery::class);
    }

    public function test_the_ledger_reads_bills_payments_and_returns_in_order(): void
    {
        $bill = $this->bill(1000, '2026-09-01');

        app(SupplierPaymentService::class)->handle([
            'bill_id' => $bill->id,
            'amount' => 400,
            'method' => 'cash',
            'paid_at' => '2026-09-10',
        ], $this->admin->id);

        $returns = app(PurchaseReturnService::class);
        $return = $returns->create([
            'supplier_id' => $this->supplier->id,
            'purchase_bill_id' => $bill->id,
            'return_date' => '2026-09-20',
            'reason' => 'Part of the delivery was rejected on inspection.',
            'reason_code' => 'quality',
            'lines' => [['description' => 'Rejected goods', 'qty' => 1, 'unit_cost' => 100]],
        ], $this->admin->id);
        $returns->approve($return, $this->makeUser()->id);

        $ledger = $this->query()->supplierLedger($this->supplier, ['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(0.0, $ledger['opening']);
        $this->assertCount(3, $ledger['lines']);

        $this->assertSame('BILL-00001', $ledger['lines'][0]['reference']);
        $this->assertSame(1000.0, $ledger['lines'][0]['credit']);
        $this->assertSame(1000.0, $ledger['lines'][0]['balance'], 'a bill increases what we owe');

        $this->assertSame(400.0, $ledger['lines'][1]['debit']);
        $this->assertSame(600.0, $ledger['lines'][1]['balance'], 'a payment reduces it');

        $this->assertSame(100.0, $ledger['lines'][2]['debit']);
        $this->assertSame(500.0, $ledger['lines'][2]['balance'], 'a return takes the rest back');

        $this->assertSame(500.0, $ledger['closing']);
        $this->assertSame(500.0, $ledger['totals']['debit']);
        $this->assertSame(1000.0, $ledger['totals']['credit']);
    }

    public function test_the_opening_balance_carries_everything_from_before_the_range(): void
    {
        $bill = $this->bill(800, '2026-08-05');

        app(SupplierPaymentService::class)->handle([
            'bill_id' => $bill->id,
            'amount' => 300,
            'method' => 'bank',
            'paid_at' => '2026-08-20',
        ], $this->admin->id);

        $this->bill(200, '2026-09-05');

        $ledger = $this->query()->supplierLedger($this->supplier, ['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(500.0, $ledger['opening'], '800 billed − 300 paid before the period');
        $this->assertCount(1, $ledger['lines']);
        $this->assertSame(700.0, $ledger['closing']);
    }

    public function test_a_draft_bill_and_a_cancelled_return_never_reach_the_account(): void
    {
        $bills = app(PurchaseBillService::class);

        $bills->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-09-02',
            'lines' => [['description' => 'Not approved yet', 'qty' => 1, 'unit_cost' => 5000]],
        ], $this->admin->id);

        $posted = $this->bill(300, '2026-09-03');

        $returns = app(PurchaseReturnService::class);
        $return = $returns->create([
            'supplier_id' => $this->supplier->id,
            'return_date' => '2026-09-04',
            'reason' => 'Wrong item supplied, going back.',
            'reason_code' => 'wrong_item',
            'lines' => [['description' => 'Wrong item', 'qty' => 1, 'unit_cost' => 300]],
        ], $this->admin->id);
        $returns->cancel($return, 'Supplier accepted the goods after all.', $this->admin->id);

        $ledger = $this->query()->supplierLedger($this->supplier, ['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertCount(1, $ledger['lines'], 'only the posted bill is on the account');
        $this->assertSame($posted->code, $ledger['lines'][0]['reference']);
        $this->assertSame(300.0, $ledger['closing']);
    }

    public function test_the_ageing_screen_buckets_every_open_bill_by_its_own_due_date(): void
    {
        // Four open bills: not due, 5 days late, 40 days late, 120 days late.
        $this->bill(100, now()->subDay()->toDateString(), now()->addDays(10)->toDateString());
        $this->bill(200, now()->subDays(10)->toDateString(), now()->subDays(5)->toDateString());
        $this->bill(300, now()->subDays(50)->toDateString(), now()->subDays(40)->toDateString());
        $this->bill(400, now()->subDays(130)->toDateString(), now()->subDays(120)->toDateString());

        // A supplier on no terms has no due date at all: such a bill is never
        // called late, however old it is.
        $noTerms = app(SupplierService::class)->create([
            'name' => 'Ledger Cash Supplier',
            'phone' => '01744444444',
            'payment_terms_days' => 0,
        ], $this->admin->id);

        $undated = app(PurchaseBillService::class)->create([
            'supplier_id' => $noTerms->id,
            'bill_date' => now()->subDays(200)->toDateString(),
            'lines' => [['description' => 'Undated purchase', 'qty' => 1, 'unit_cost' => 50]],
        ], $this->admin->id);

        $this->assertNull($undated->due_date, 'no terms means no due date');
        app(PurchaseBillService::class)->approve($undated, $this->makeUser()->id);

        $ageing = $this->query()->payablesAgeing();

        $this->assertSame(150.0, $ageing['totals']['current'], 'not-yet-due plus the undated bill');
        $this->assertSame(200.0, $ageing['totals']['d1_30']);
        $this->assertSame(300.0, $ageing['totals']['d31_60']);
        $this->assertSame(400.0, $ageing['totals']['d90_plus']);
        $this->assertSame(1050.0, $ageing['grand']);

        $row = collect($ageing['rows'])->firstWhere(fn ($row) => $row['supplier']->id === $this->supplier->id);
        $this->assertSame(4, $row['bills']);
        $this->assertSame(1000.0, $row['total']);
        $this->assertNotNull($row['oldest_due'], 'the oldest due date is the one the chase call starts from');
    }

    public function test_the_ledger_index_lists_accounts_with_their_balances(): void
    {
        $bill = $this->bill(600, '2026-09-06');

        app(SupplierPaymentService::class)->handle([
            'bill_id' => $bill->id,
            'amount' => 100,
            'method' => 'cash',
            'paid_at' => '2026-09-07',
        ], $this->admin->id);

        $rows = $this->query()->supplierBalances();
        $row = $rows->firstWhere(fn ($row) => $row['supplier']->id === $this->supplier->id);

        $this->assertNotNull($row);
        $this->assertSame(600.0, $row['billed']);
        $this->assertSame(100.0, $row['paid']);
        $this->assertSame(0.0, $row['credited']);
        $this->assertSame(500.0, $row['balance']);
        $this->assertSame(500.0, $row['outstanding'], 'the account balance and the open-bill total agree');
    }

    public function test_the_statement_is_printable_and_exportable(): void
    {
        $this->bill(750, '2026-09-08', '2026-09-20');

        $this->actingAs($this->admin)
            ->get('/app/suppliers/'.$this->supplier->id.'/statement?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertSee('Supplier statement of account')
            ->assertSee($this->supplier->name)
            ->assertSee('Opening balance');

        $csv = $this->actingAs($this->admin)
            ->get('/app/suppliers/'.$this->supplier->id.'/statement?from=2026-09-01&to=2026-09-30&format=csv');

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Billed (Cr)', $csv->streamedContent());
        $this->assertStringContainsString('BILL-00001', $csv->streamedContent());
    }

    public function test_the_account_screens_are_permission_gated(): void
    {
        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith(['portal.erp.access', 'suppliers.view'])->id);

        $this->actingAs($viewer)->get('/app/suppliers/ledger')->assertOk();
        $this->actingAs($viewer)->get('/app/suppliers/'.$this->supplier->id.'/ledger')->assertOk();
        // Ageing is a purchase report, so seeing suppliers is not enough.
        $this->actingAs($viewer)->get('/app/purchase/payables')->assertForbidden();

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access', 'purchase.bills.view'])->id);

        $this->actingAs($outsider)->get('/app/suppliers/ledger')->assertForbidden();
        $this->actingAs($outsider)->get('/app/purchase/payables')->assertOk();
    }
}
