<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\SupplierPaymentService;
use App\Domain\Purchase\Services\SupplierService;
use App\Domain\Sales\Payment;
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
 * 03.7 Supplier payments — money leaving against a real payable.
 *
 * What this pins:
 *  · a payment posts Dr Accounts Payable / Cr Cash or Bank through
 *    posting_rules, and lands in the shared `payments` table as direction out;
 *  · it allocates to the bill and moves the bill's balance, status included;
 *  · a bill must be posted before anything can be paid against it, and a
 *    payment can never exceed the balance;
 *  · the same idempotency key never pays a supplier twice;
 *  · the history screen is permission gated.
 */
class SupplierPaymentTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Supplier $supplier;

    protected PurchaseBill $bill;

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
            'code' => 'PAY-1',
            'sku' => 'PAY-1-SKU',
            'name' => 'Payment Probe',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $this->supplier = app(SupplierService::class)->create([
            'name' => 'Payable Textiles',
            'phone' => '01711111111',
            'payment_terms_days' => 15,
        ], $this->admin->id);

        $this->bill = $this->approvedBill(1000);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__supplier-payments', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** A posted bill for the given net value, with no goods behind it. */
    protected function approvedBill(float $net): PurchaseBill
    {
        $bills = app(PurchaseBillService::class);

        $bill = $bills->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-01',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Services billed',
                'qty' => 1,
                'unit_cost' => $net,
            ]],
        ], $this->admin->id);

        return $bills->approve($bill, $this->makeUser()->id);
    }

    protected function accountId(string $code): int
    {
        return (int) Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->value('id');
    }

    public function test_a_full_payment_settles_the_bill_and_posts_ap_against_cash(): void
    {
        $payment = app(SupplierPaymentService::class)->handle([
            'bill_id' => $this->bill->id,
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => '2026-10-05',
            'reference' => 'CASH-11',
        ], $this->admin->id);

        $this->assertSame('out', $payment->direction);
        $this->assertSame($this->supplier->id, $payment->supplier_id);
        $this->assertSame('1000.0000', (string) $payment->amount);
        $this->assertSame('posted', $payment->status);

        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $byAccount = $entry->lines->mapWithKeys(fn ($line) => [
            (int) $line->account_id => [strtoupper($line->dc), (string) $line->amount],
        ]);

        $this->assertSame(['DEBIT', '1000.0000'], $byAccount[$this->accountId('2110')], 'accounts payable is relieved');
        $this->assertSame(['CREDIT', '1000.0000'], $byAccount[$this->accountId('1110')], 'cash leaves the drawer');
        $this->assertSame('1000.0000', (string) $entry->total_debit);

        $allocation = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->where('allocatable_type', PurchaseBill::class)
            ->firstOrFail();

        $this->assertSame($this->bill->id, (int) $allocation->allocatable_id);
        $this->assertSame('1000.0000', (string) $allocation->amount);

        $bill = $this->bill->refresh();
        $this->assertSame('paid', $bill->status);
        $this->assertSame('1000.0000', (string) $bill->paid_amount);
        $this->assertSame('0.0000', (string) $bill->due_amount);
        $this->assertFalse($bill->isPayable());
    }

    public function test_a_part_payment_leaves_the_bill_open_and_ages_the_remainder(): void
    {
        $service = app(SupplierPaymentService::class);

        $service->handle([
            'bill_id' => $this->bill->id,
            'amount' => 400,
            'method' => 'bank',
            'paid_at' => '2026-10-04',
        ], $this->admin->id);

        $bill = $this->bill->refresh();
        $this->assertSame('partially_paid', $bill->status);
        $this->assertSame('600.0000', (string) $bill->due_amount);
        $this->assertTrue($bill->isPayable());

        // Bank payments credit the bank account, not cash.
        $payment = Payment::query()->where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();
        $entry = JournalEntry::query()->with('lines')->findOrFail($payment->journal_entry_id);
        $accounts = $entry->lines->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($this->accountId('1120'), $accounts);
        $this->assertNotContains($this->accountId('1110'), $accounts);

        // The remainder keeps ageing against the bill's own due date.
        $summary = app(\App\Domain\Purchase\Queries\PurchaseQuery::class)->billSummary();
        $this->assertSame(600.0, $summary['payable']);
        $this->assertSame(1, $summary['open_bills']);
    }

    public function test_a_payment_cannot_exceed_the_bill_balance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the balance');

        app(SupplierPaymentService::class)->handle([
            'bill_id' => $this->bill->id,
            'amount' => 1000.01,
            'method' => 'cash',
            'paid_at' => '2026-10-05',
        ], $this->admin->id);
    }

    public function test_an_unposted_bill_is_not_payable(): void
    {
        $draft = app(PurchaseBillService::class)->create([
            'supplier_id' => $this->supplier->id,
            'bill_date' => '2026-10-02',
            'lines' => [['description' => 'Not yet approved', 'qty' => 1, 'unit_cost' => 500]],
        ], $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not posted');

        app(SupplierPaymentService::class)->handle([
            'bill_id' => $draft->id,
            'amount' => 100,
            'method' => 'cash',
            'paid_at' => '2026-10-05',
        ], $this->admin->id);
    }

    public function test_the_same_idempotency_key_never_pays_twice(): void
    {
        $service = app(SupplierPaymentService::class);

        $first = $service->handle([
            'bill_id' => $this->bill->id,
            'amount' => 250,
            'method' => 'cash',
            'paid_at' => '2026-10-06',
            'idempotency_key' => 'run-2026-10-06-001',
        ], $this->admin->id);

        $second = $service->handle([
            'bill_id' => $this->bill->id,
            'amount' => 250,
            'method' => 'cash',
            'paid_at' => '2026-10-06',
            'idempotency_key' => 'run-2026-10-06-001',
        ], $this->admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->where('supplier_id', $this->supplier->id)->count());
        $this->assertSame('750.0000', (string) $this->bill->refresh()->due_amount, 'the retry did not move the bill twice');
    }

    public function test_settling_a_bill_twice_is_refused(): void
    {
        $service = app(SupplierPaymentService::class);

        $service->handle([
            'bill_id' => $this->bill->id,
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => '2026-10-07',
        ], $this->admin->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already settled');

        $service->handle([
            'bill_id' => $this->bill->id,
            'amount' => 1,
            'method' => 'cash',
            'paid_at' => '2026-10-08',
        ], $this->admin->id);
    }

    public function test_the_payment_screens_are_gated_and_render_for_permitted_users(): void
    {
        $clerk = $this->makeUser();
        $clerk->roles()->attach($this->roleWith([
            'portal.erp.access', 'purchase.payments.view',
        ])->id);

        $this->actingAs($clerk)->get('/app/purchase/payments')->assertOk();
        $this->actingAs($clerk)->get('/app/purchase/payments/create')->assertForbidden();

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access'])->id);
        $this->actingAs($outsider)->get('/app/purchase/payments')->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/app/purchase/payments/create?bill='.$this->bill->id)
            ->assertOk()
            ->assertSee($this->bill->code);
    }
}
