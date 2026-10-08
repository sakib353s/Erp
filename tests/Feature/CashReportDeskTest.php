<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\Services\MoneyMovementService;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\CashBank\Services\ExpenseService;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Settings\Services\SettingService;
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
 * §08-20 and §08-22 — the cash and expense report family.
 *
 * These are the screens where a number can be wrong without anybody noticing, so
 * what is pinned here is where every figure comes from:
 *
 *  · the expense report counts **posted** expenses only — a bill waiting for a
 *    signature has no journal entry, and a report that counted it would not tie
 *    to the ledger — and it proves that tie by comparing its own total against the
 *    debit the categories' accounts actually carry, naming the difference rather
 *    than absorbing it;
 *  · the cash book's opening is brought forward from before the window and its
 *    closing is the account's own ledger balance, not the last row added up;
 *  · the bank book covers banks and wallets and leaves cash to the cash book;
 *  · the cash flow is built from the counterpart of each posting, so a receipt is
 *    named by the account it came from; a transfer between the company's own
 *    accounts appears apart and is left out of the flow totals, because moving
 *    money between two pockets is not income;
 *  · the session variance page reports the till's own arithmetic and says so —
 *    the one page in the module that is not the ledger's;
 *  · reading what the company spent and reading where its money is are two keys,
 *    and both are read-only.
 */
class CashReportDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

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
    }

    // --------------------------------------------------------------- helpers

    protected function account(string $code): Account
    {
        return Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();
    }

    protected function movements(): MoneyMovementService
    {
        return app(MoneyMovementService::class);
    }

    protected function category(string $accountCode = '5220', array $overrides = []): ExpenseCategory
    {
        return ExpenseCategory::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'RENT',
            'name' => 'Office rent',
            'account_id' => $this->account($accountCode)->id,
            'is_active' => true,
            'sort_order' => 0,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    protected function expense(ExpenseCategory $category, string $amount, array $overrides = []): Expense
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), array_merge([
                'category_id' => $category->id,
                'expense_date' => now()->toDateString(),
                'payee' => 'Dhaka WASA',
                'amount' => $amount,
                'settled_with' => Expense::SETTLED_MONEY,
                'money_account_id' => $this->account('1110')->id,
                'narration' => 'Water bill',
            ], $overrides))
            ->assertSessionHasNoErrors();

        return Expense::query()->orderByDesc('id')->firstOrFail();
    }

    /** Put the approval limit where the expense desk reads it. */
    protected function limit(float $amount): void
    {
        app(SettingService::class)->set(ExpenseService::SETTING_GROUP, ExpenseService::SETTING_KEY, $amount, null, $this->admin);
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser();
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__cash-report-test', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** A real counter session, with a cash sale and a float taken out and put back. */
    protected function till(): array
    {
        $product = app(CreateProduct::class)->handle([
            'code' => 'RPT-1',
            'sku' => 'RPT-SKU-1',
            'name' => 'Reported Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 50, 'unit_cost' => 80]],
            'idempotency_suffix' => 'rpt-open-'.uniqid(),
        ], $this->httpRequest());

        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 1000.0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 1000,
            'lines' => [['product_id' => $product->id, 'qty' => 5, 'unit_price' => 150]],
        ], $this->httpRequest());

        return [$session->fresh(), $product];
    }

    // ------------------------------------------------------------ §08-20

    public function test_the_expense_report_counts_only_what_the_ledger_has_and_proves_the_tie(): void
    {
        $category = $this->category();

        $this->limit(5000);

        $posted = $this->expense($category, '6400.00');

        // At or above the approval limit the expense waits — and a report that
        // counted it would not tie to the ledger.
        $waiting = $this->expense($category, '9000.00');

        $this->assertTrue($waiting->isPending(), 'the second expense was expected to need approval');
        $this->assertNull($waiting->journal_entry_id);

        $this->actingAs($this->admin)
            ->get(route('expenses.reports.index', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee($posted->expense_no)
            ->assertSee('Office rent')
            ->assertSee('6,400.00')
            ->assertSee('Agrees')
            ->assertSee('still')
            ->assertSee('9,000.00');

        // The note says what was left out, and the number is in it.
        $this->assertTrue((bool) $waiting->approval_gate);

        $report = app(\App\Domain\Reporting\ExpenseReport::class)
            ->forCompany($this->admin->company_id, [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]);

        $this->assertSame(1, $report['totals']['rows']);
        $this->assertEqualsWithDelta(6400.0, $report['totals']['amount'], 0.0001);
        $this->assertEqualsWithDelta(6400.0, $report['totals']['money'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $report['totals']['payable'], 0.0001);
        $this->assertSame(1, $report['waiting']['rows']);
        $this->assertTrue($report['ledger']['agrees'], 'the report total must equal the debit the category account carries');
        $this->assertEqualsWithDelta(6400.0, $report['ledger']['ledger'], 0.0001);
        $this->assertSame('5220 — Rent Expense', $report['ledger']['accounts'][0]['account']);

        // The report's total is the account's debit total — read from the
        // ledger, not from the report's own arithmetic.
        $ledger = app(LedgerService::class)->accountLedger(
            $this->account('5220'),
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        );

        $this->assertEqualsWithDelta(
            6400.0,
            (float) $ledger['debit_total'] - (float) $ledger['rows'][0]['debit'],
            0.0001,
        );
    }

    public function test_the_report_names_a_ledger_difference_instead_of_hiding_it(): void
    {
        $category = $this->category();
        $this->expense($category, '5000.00');

        // Something else lands on the same account: a credit note's expense leg,
        // or a hand-written correction. A report that quietly absorbed this
        // would be telling a comfortable lie.
        app(\App\Domain\Accounting\Services\JournalPostingService::class)->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Rent credited back by the landlord',
            'lines' => [
                ['account_id' => $this->account('5220')->id, 'dc' => 'credit', 'amount' => '1200.00'],
                ['account_id' => $this->account('1120')->id, 'dc' => 'debit', 'amount' => '1200.00'],
            ],
        ], $this->admin);

        $report = app(\App\Domain\Reporting\ExpenseReport::class)
            ->forCompany($this->admin->company_id, [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]);

        $this->assertFalse($report['ledger']['agrees']);
        $this->assertEqualsWithDelta(5000.0, $report['ledger']['reported'], 0.0001);
        $this->assertEqualsWithDelta(3800.0, $report['ledger']['ledger'], 0.0001);
        $this->assertEqualsWithDelta(1200.0, $report['ledger']['difference'], 0.0001);
        $this->assertEqualsWithDelta(1200.0, $report['ledger']['accounts'][0]['credit'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('expenses.reports.index'))
            ->assertOk()
            ->assertSee('something posted to one of these accounts', false)
            ->assertSee('1,200.00');
    }

    public function test_the_expense_report_filters_and_exports_the_same_rows(): void
    {
        $rent = $this->category('5220', ['code' => 'RENT', 'name' => 'Office rent']);
        $power = $this->category('5230', ['code' => 'POWER', 'name' => 'Utilities']);

        $this->expense($rent, '1000.00');
        $this->expense($power, '2500.00', ['payee' => 'DESCO', 'narration' => 'Electricity for September']);

        $both = app(\App\Domain\Reporting\ExpenseReport::class)->forCompany($this->admin->company_id, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(2, $both['totals']['rows']);
        $this->assertEqualsWithDelta(3500.0, $both['totals']['amount'], 0.0001);
        $this->assertSame('Utilities', $both['by_category'][0]['category'], 'the biggest category leads');
        $this->assertEqualsWithDelta(71.4, $both['by_category'][0]['share'], 0.1);
        $this->assertSame(2, $both['totals']['categories']);

        $onlyPower = app(\App\Domain\Reporting\ExpenseReport::class)->forCompany($this->admin->company_id, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'category_id' => $power->id,
        ]);

        $this->assertSame(1, $onlyPower['totals']['rows']);
        $this->assertEqualsWithDelta(2500.0, $onlyPower['totals']['amount'], 0.0001);

        $searched = app(\App\Domain\Reporting\ExpenseReport::class)->forCompany($this->admin->company_id, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'q' => 'September',
        ]);

        $this->assertSame(1, $searched['totals']['rows']);
        $this->assertSame('DESCO', $searched['rows'][0]['payee']);

        $csv = $this->actingAs($this->admin)
            ->get(route('expenses.reports.index', ['category' => $power->id, 'format' => 'csv']));

        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $body = $csv->streamedContent();

        $this->assertStringContainsString('DESCO', $body);
        $this->assertStringContainsString('2500.00', $body);
        $this->assertStringNotContainsString('Dhaka WASA', $body);
        $this->assertStringContainsString('Totals', $body);
    }

    // ------------------------------------------------------------ §08-22

    public function test_the_cash_book_carries_the_ledgers_own_opening_and_closing(): void
    {
        // A receipt last month opens the window with a balance, then money moves
        // inside the window: the report must show the brought-forward figure and
        // a closing that agrees with the account itself.
        $this->movements()->receive([
            'money_account_id' => $this->account('1110')->id,
            'counter_account_id' => $this->account('4100')->id,
            'amount' => '25000.00',
            'received_on' => now()->subMonth()->startOfMonth()->toDateString(),
            'payer' => 'Walk-in sales',
        ], $this->admin->id);

        $this->movements()->receive([
            'money_account_id' => $this->account('1110')->id,
            'counter_account_id' => $this->account('4100')->id,
            'amount' => '4000.00',
            'received_on' => now()->toDateString(),
            'payer' => 'Walk-in sales',
        ], $this->admin->id);

        $this->movements()->pay([
            'money_account_id' => $this->account('1110')->id,
            'counter_account_id' => $this->account('5220')->id,
            'amount' => '1500.00',
            'paid_on' => now()->toDateString(),
            'payee' => 'Landlord',
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('cash.reports.book', [
                'account' => $this->account('1110')->id,
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Brought forward')
            ->assertSee('25,000.00')
            ->assertSee('4,000.00')
            ->assertSee('1,500.00');

        $book = app(\App\Domain\Reporting\CashReports::class)->book(
            $this->account('1110'),
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        );

        $this->assertEqualsWithDelta(25000.0, (float) $book['opening'], 0.0001);
        $this->assertEqualsWithDelta(4000.0, (float) $book['in'], 0.0001);
        $this->assertEqualsWithDelta(1500.0, (float) $book['out'], 0.0001);
        $this->assertEqualsWithDelta(27500.0, (float) $book['closing'], 0.0001);

        // Closing is the ledger's balance, not the report's own addition.
        $ledger = app(LedgerService::class)->accountLedger($this->account('1110'), null, now()->endOfDay());

        $this->assertEqualsWithDelta((float) $ledger['balance'], (float) $book['closing'], 0.0001);

        // …and the CSV carries the same rows, filters included.
        $csv = $this->actingAs($this->admin)
            ->get(route('cash.reports.book', ['account' => $this->account('1110')->id, 'format' => 'csv']));

        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $body = $csv->streamedContent();

        $this->assertStringContainsString('Particulars', $body);
        $this->assertStringContainsString('Opening balance', $body);
        $this->assertStringContainsString('4000.00', $body);
        $this->assertStringContainsString('Closing', $body);
    }

    public function test_the_bank_book_lists_banks_and_wallets_but_leaves_cash_to_the_cash_book(): void
    {
        $this->movements()->receive([
            'money_account_id' => $this->account('1120')->id,
            'counter_account_id' => $this->account('4100')->id,
            'amount' => '18000.00',
            'received_on' => now()->toDateString(),
            'payer' => 'Customer',
        ], $this->admin->id);

        $this->movements()->receive([
            'money_account_id' => $this->account('1110')->id,
            'counter_account_id' => $this->account('4100')->id,
            'amount' => '7000.00',
            'received_on' => now()->toDateString(),
            'payer' => 'Walk-in',
        ], $this->admin->id);

        $result = app(\App\Domain\Reporting\CashReports::class)->bankBook(
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        );

        $codes = $result['rows']->pluck('account.code')->all();

        $this->assertContains('1120', $codes);
        $this->assertNotContains('1110', $codes, 'the cash drawer belongs in the cash book, not the bank book');
        $this->assertEqualsWithDelta(18000.0, (float) $result['totals']['in'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('cash.reports.bank-book'))
            ->assertOk()
            ->assertSee('Bank Account')
            ->assertSee('18,000.00');
    }

    public function test_the_cash_flow_names_each_movement_by_its_counterpart_and_keeps_transfers_out(): void
    {
        // In: a receipt whose counterpart is a revenue account.
        $this->movements()->receive([
            'money_account_id' => $this->account('1120')->id,
            'counter_account_id' => $this->account('4100')->id,
            'amount' => '60000.00',
            'received_on' => now()->toDateString(),
            'payer' => 'Customer',
        ], $this->admin->id);

        // Out: an expense's own account as the counterpart.
        $this->movements()->pay([
            'money_account_id' => $this->account('1120')->id,
            'counter_account_id' => $this->account('5220')->id,
            'amount' => '15000.00',
            'paid_on' => now()->toDateString(),
            'payee' => 'Landlord',
        ], $this->admin->id);

        // Neither: money moved between two of the company's own accounts.
        $this->movements()->transfer([
            'from_account_id' => $this->account('1120')->id,
            'to_account_id' => $this->account('1110')->id,
            'amount' => '10000.00',
            'transferred_on' => now()->toDateString(),
            'narration' => 'Cash for the tills',
        ], $this->admin->id);

        $result = app(\App\Domain\Reporting\CashReports::class)->cashFlow(
            now()->startOfMonth()->startOfDay(),
            now()->endOfDay(),
        );

        $this->assertEqualsWithDelta(60000.0, (float) $result['totals']['in'], 0.0001);
        $this->assertEqualsWithDelta(15000.0, (float) $result['totals']['out'], 0.0001);
        $this->assertEqualsWithDelta(45000.0, (float) $result['totals']['net'], 0.0001);

        // The transfer is real money movement, reported apart and excluded from
        // income and expenditure.
        $this->assertEqualsWithDelta(10000.0, (float) $result['totals']['transfer_in'], 0.0001);
        $this->assertSame(1, $result['transfers']->count());
        $this->assertStringContainsString('Between our own accounts', $result['transfers']->first()['counter_account']);

        // Every inflow is named by the account on the other side of the posting.
        $this->assertStringContainsString('Revenue', $result['inflows']->first()['counter_account']);
        $this->assertStringContainsString('Rent', $result['outflows']->first()['counter_account']);

        // And the totals are the money accounts' own debit and credit totals:
        // the report cannot say the company moved money it did not move.
        $ledger = app(LedgerService::class)->accountLedger($this->account('1120'), now()->startOfMonth()->startOfDay(), now()->endOfDay());
        $debit = (float) $ledger['debit_total'];
        $credit = (float) $ledger['credit_total'];

        $this->assertEqualsWithDelta($debit, (float) $result['totals']['in'] + (float) $result['totals']['transfer_out'], 0.0001);
        $this->assertEqualsWithDelta($credit, (float) $result['totals']['out'] + (float) $result['totals']['transfer_in'], 0.0001);

        $this->actingAs($this->admin)
            ->get(route('cash.reports.flow'))
            ->assertOk()
            ->assertSee('Money in')
            ->assertSee('Money out')
            ->assertSee('Between our own accounts')
            ->assertSee('60,000.00');
    }

    public function test_the_session_variance_reports_the_tills_own_arithmetic_and_says_so(): void
    {
        [$session] = $this->till();

        // expected = 1,000 float + 750 cash sales (5 × 150) = 1,750; counted 2,000,
        // so the till is 250 over — and that is the till's own arithmetic.
        $closed = app(ClosePosSession::class)->handle($session, 2000.0, $this->httpRequest());

        $this->assertFalse($closed->status === 'open');

        $result = app(\App\Domain\Reporting\CashReports::class)->sessionVariance(
            now()->subDay()->startOfDay(),
            now()->endOfDay(),
        );

        $row = $result['rows']->firstWhere('session_no', $closed->session_no);

        $this->assertNotNull($row, 'the closed session must appear in the variance report');
        $this->assertEqualsWithDelta(1000.0, (float) $row['float'], 0.0001);
        $this->assertEqualsWithDelta(750.0, (float) $row['cash_sales'], 0.0001);
        $this->assertEqualsWithDelta(1750.0, (float) $row['expected'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, (float) $row['counted'], 0.0001);
        $this->assertEqualsWithDelta(250.0, (float) $row['variance'], 0.0001);
        $this->assertTrue($row['over']);
        $this->assertFalse($row['short']);

        $this->assertSame(1, $result['totals']['closed']);
        $this->assertSame(1, $result['totals']['with_variance']);
        $this->assertEqualsWithDelta(250.0, (float) $result['totals']['net_variance'], 0.0001);
        $this->assertEqualsWithDelta(250.0, (float) $result['totals']['counted_over'], 0.0001);

        // An open session has no counted figure at all — the report says so
        // rather than printing a zero that would read as a perfect count.
        app(OpenPosSession::class)->handle(['opening_float' => 500.0, 'warehouse_id' => $this->warehouse->id], $this->httpRequest());

        $withOpen = app(\App\Domain\Reporting\CashReports::class)->sessionVariance(
            now()->subDay()->startOfDay(),
            now()->endOfDay(),
        );

        $openRow = $withOpen['rows']->firstWhere('status', 'open');

        $this->assertNotNull($openRow);
        $this->assertNull($openRow['counted']);
        $this->assertNull($openRow['variance']);
        $this->assertSame(1, $withOpen['totals']['open']);

        $this->actingAs($this->admin)
            ->get(route('cash.reports.sessions'))
            ->assertOk()
            ->assertSee('does not read the ledger', false)
            ->assertSee($closed->session_no)
            ->assertSee('1,750.00')
            ->assertSee('2,000.00')
            ->assertSee('not counted yet');
    }

    // ------------------------------------------------- permissions and leaves

    public function test_the_two_report_keys_are_separate_and_read_only(): void
    {
        $expensesLeaf = MenuItem::query()->where('route', '/app/reports/cash/expenses')->first();
        $cashLeaf = MenuItem::query()->where('route', '/app/reports/cash/bank-book')->first();

        $this->assertNotNull($expensesLeaf, 'the expense report leaf was not mapped');
        $this->assertNotNull($cashLeaf, 'the cash reports leaf was not mapped');
        $this->assertSame('expenses.reports', $expensesLeaf->permission?->key);
        $this->assertSame('cash.reports', $cashLeaf->permission?->key);

        $treasurer = $this->userWith(['cash.reports']);

        $this->actingAs($treasurer)->get(route('cash.reports.book'))->assertOk();
        $this->actingAs($treasurer)->get(route('cash.reports.bank-book'))->assertOk();
        $this->actingAs($treasurer)->get(route('cash.reports.flow'))->assertOk();
        $this->actingAs($treasurer)->get(route('cash.reports.sessions'))->assertOk();

        // Reading where the money is does not let anybody read the expense
        // report, and vice versa — they are different audiences.
        $this->actingAs($treasurer)->get(route('expenses.reports.index'))->assertForbidden();

        $manager = $this->userWith(['expenses.reports']);

        $this->actingAs($manager)->get(route('expenses.reports.index'))->assertOk();
        $this->actingAs($manager)->get(route('cash.reports.flow'))->assertForbidden();
        $this->actingAs($manager)->get(route('cash.reports.book'))->assertForbidden();

        // Nothing on either screen writes: every route in the family is a GET.
        foreach (['expenses.reports.index', 'cash.reports.book', 'cash.reports.bank-book', 'cash.reports.flow', 'cash.reports.sessions'] as $name) {
            $this->actingAs($this->admin)->post(route($name))->assertStatus(405);
        }
    }
}
