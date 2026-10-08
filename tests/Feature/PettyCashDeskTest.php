<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\PettyCashFund;
use App\Domain\CashBank\PettyCashRequest;
use App\Domain\CashBank\PettyCashTransaction;
use App\Domain\CashBank\Services\PettyCashService;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-21 — the float in the drawer.
 *
 * Petty cash is where an ERP's rules are tested hardest: the money is small, the
 * vouchers are paper, and the person holding the tin is usually the person who
 * spends it. What this suite refuses to let slip:
 *
 *  · declaring a float creates a real ledger account and posts nothing — a tin
 *    is not a transaction;
 *  · a voucher is a payment out of that account, so the tin can never pay money
 *    it does not hold, and the ledger and the register cannot disagree;
 *  · above the company's limit the money is *asked* for: a waiting request has no
 *    number, no payment and nothing in the ledger, and the person who asked
 *    cannot be the person who answers;
 *  · replenishment is a transfer into the float, never a second record of the
 *    spending — recording it again would count every rickshaw twice;
 *  · a float holding cash, or a float with a request still waiting, cannot be
 *    closed: those are the two states in which nobody can say where the money
 *    went;
 *  · another company's float is not found, not runnable and not postable to.
 */
class PettyCashDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    // --------------------------------------------------------------- helpers

    protected function account(string $code): Account
    {
        return Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();
    }

    protected function category(array $overrides = []): ExpenseCategory
    {
        return ExpenseCategory::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'PC-TRAVEL',
            'name' => 'Local travel',
            'account_id' => $this->account('5220')->id,
            'is_active' => true,
            'sort_order' => 0,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function fundPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'PC-HEAD',
            'name' => 'Head office tin',
            'custodian_id' => $this->admin->id,
            'imprest_amount' => '5000.00',
            'opened_on' => now()->toDateString(),
            'is_active' => 1,
        ], $overrides);
    }

    protected function fund(array $overrides = [], ?User $actor = null): PettyCashFund
    {
        $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.petty-cash.store'), $this->fundPayload($overrides))
            ->assertSessionHasNoErrors();

        return PettyCashFund::query()->orderByDesc('id')->firstOrFail();
    }

    /** The company's own limit — the only thing that decides pay-now from ask-first. */
    protected function limit(float $amount): void
    {
        app(SettingService::class)->set(
            PettyCashService::SETTING_GROUP,
            PettyCashService::SETTING_KEY,
            $amount,
            null,
            $this->admin,
        );
    }

    protected function voucher(PettyCashFund $fund, ExpenseCategory $category, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cash-bank.petty-cash.expenses.store'), array_merge([
            'fund_id' => $fund->id,
            'expense_category_id' => $category->id,
            'payee' => 'Rickshaw',
            'amount' => '250.00',
            'spent_on' => now()->toDateString(),
            'narration' => 'Two rides to the courier office',
        ], $overrides));
    }

    protected function topUp(PettyCashFund $fund, array $overrides = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.petty-cash.replenishments.store'), array_merge([
                'fund_id' => $fund->id,
                'source_account_id' => $this->account('1110')->id,
                'amount' => '2000.00',
                'replenished_on' => now()->toDateString(),
            ], $overrides));
    }

    /** How many payments the module has posted — the figure that must stay put. */
    protected function posted(): int
    {
        return JournalEntry::query()->where('source_type', 'cash_payment')->count();
    }

    protected function balanceOf(PettyCashFund $fund): string
    {
        return app(\App\Domain\CashBank\Services\MoneyAccountService::class)->balanceOf($fund->account);
    }

    // ----------------------------------------------------- the desk is real

    public function test_the_four_leaves_are_mapped_and_the_keys_are_four_separate_jobs(): void
    {
        $leaves = [
            '/app/cash-bank/petty-cash' => 'pettycash.funds',
            '/app/cash-bank/petty-cash/requests' => 'pettycash.spend',
            '/app/cash-bank/petty-cash/expenses' => 'pettycash.spend',
            '/app/cash-bank/petty-cash/replenishments' => 'pettycash.replenish',
        ];

        foreach ($leaves as $route => $key) {
            $leaf = MenuItem::query()->where('route', $route)->first();

            $this->assertNotNull($leaf, "the catalogue leaf {$route} was not mapped");
            $this->assertSame($key, $leaf->permission?->key, "the leaf {$route} is behind the wrong key");
        }

        // A custodian may pay vouchers and put money back, but declaring the float
        // (which creates a ledger account) and approving what they asked for are
        // somebody else's acts.
        $storekeeper = \App\Domain\Foundation\Role::query()
            ->where('company_id', $this->admin->company_id)
            ->where('slug', 'storekeeper')
            ->firstOrFail();

        $keys = $storekeeper->permissions()->pluck('key')->all();

        $this->assertContains('pettycash.spend', $keys);
        $this->assertContains('pettycash.replenish', $keys);
        $this->assertNotContains('pettycash.funds', $keys);
        $this->assertNotContains('pettycash.approve', $keys);
    }

    public function test_a_custodian_cannot_open_the_desk_that_declares_floats(): void
    {
        $custodian = $this->makeUser();
        $custodian->roles()->attach($this->roleWith(['pettycash.spend', 'pettycash.replenish']));

        $this->actingAs($custodian)->get(route('cash-bank.petty-cash'))->assertForbidden();
        $this->actingAs($custodian)->get(route('cash-bank.petty-cash.expenses'))->assertOk();
        $this->actingAs($custodian)->get(route('cash-bank.petty-cash.replenishments'))->assertOk();
        $this->actingAs($custodian)->get(route('cash-bank.petty-cash.requests'))->assertOk();
    }

    // ------------------------------------------------------------ the float

    public function test_declaring_a_float_opens_a_real_ledger_account_and_posts_nothing(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cash-bank.petty-cash'))
            ->assertOk()
            ->assertSee('No float has been declared yet');

        $fund = $this->fund();

        $this->assertSame('PC-HEAD', $fund->code);
        $this->assertSame('5000.0000', $fund->imprest_amount);
        $this->assertTrue($fund->isActive());

        $account = $fund->account;
        $this->assertNotNull($account);
        $this->assertTrue((bool) $account->is_cash);
        $this->assertSame($this->account('1100')->id, $account->parent_id);
        $this->assertStringContainsString('Head office tin', $account->name);

        // A tin is not a transaction: declaring it touches nothing.
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame('0.0000', $this->balanceOf($fund));
        $this->assertSame('5000.00', app(PettyCashService::class)->shortfallOf($fund));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.fund_declared')->count());

        // Two floats cannot share a code in one company.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.store'), $this->fundPayload())
            ->assertSessionHasErrors('code');

        $this->assertSame(1, PettyCashFund::query()->count());
    }

    public function test_a_float_needs_a_custodian_and_a_level_before_it_exists(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.store'), $this->fundPayload(['custodian_id' => null]))
            ->assertSessionHasErrors('custodian_id');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.store'), $this->fundPayload(['imprest_amount' => 0]))
            ->assertSessionHasErrors('imprest_amount');

        // A custodian has to be a user of this company; a stray id is not one.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.store'), $this->fundPayload(['custodian_id' => 999999]))
            ->assertSessionHasErrors('custodian_id');

        $this->assertSame(0, PettyCashFund::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    // ------------------------------------------------------------ a voucher

    public function test_a_voucher_is_a_payment_out_of_the_float_and_the_ledger_agrees(): void
    {
        $fund = $this->fund();
        $category = $this->category();

        $this->voucher($fund, $category)
            ->assertRedirect(route('cash-bank.petty-cash.expenses', ['fund' => $fund->id]));

        $voucher = PettyCashTransaction::query()->sole();

        $this->assertTrue($voucher->isDisbursement());
        $this->assertSame('250.0000', $voucher->amount);
        $this->assertSame('Rickshaw', $voucher->payee);
        $this->assertSame($fund->id, $voucher->fund_id);
        $this->assertSame($category->id, $voucher->expense_category_id);
        $this->assertNotNull($voucher->payment_id);
        $this->assertStringStartsWith('EX-', (string) $voucher->documentNo());

        // Dr the category, Cr the float — the float's own account, not a cash box.
        $entry = $voucher->payment->journalEntry;
        $debit = $entry->lines->firstWhere('dc', 'debit');
        $credit = $entry->lines->firstWhere('dc', 'credit');

        $this->assertSame(1, $this->posted());
        $this->assertSame($category->account_id, $debit->account_id);
        $this->assertSame($fund->account_id, $credit->account_id);
        $this->assertEqualsWithDelta(250.0, (float) $debit->amount, 0.0001);
        $this->assertEqualsWithDelta(250.0, (float) $credit->amount, 0.0001);

        $this->assertSame('4750.0000', $this->balanceOf($fund));
        $this->assertSame('250.00', app(PettyCashService::class)->shortfallOf($fund));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.voucher_recorded')->count());

        // And the register says so out loud.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.petty-cash.expenses'))
            ->assertOk()
            ->assertSee('Rickshaw')
            ->assertSee((string) $voucher->documentNo());
    }

    public function test_the_float_cannot_pay_money_it_does_not_hold(): void
    {
        $fund = $this->fund(['imprest_amount' => '500.00']);
        $category = $this->category();

        $this->voucher($fund, $category, ['amount' => '800.00'])
            ->assertSessionHasErrors('petty_cash');

        $this->assertSame(0, PettyCashTransaction::query()->count());
        $this->assertSame(0, $this->posted());

        // A voucher has to name who was paid and what it was for.
        $this->voucher($fund, $category, ['payee' => '  '])->assertSessionHasErrors('payee');
        $this->voucher($fund, $category, ['amount' => '0'])->assertSessionHasErrors('amount');

        $this->assertSame(0, PettyCashTransaction::query()->count());
    }

    // ------------------------------------------------------------ the limit

    public function test_above_the_limit_the_money_is_asked_for_and_the_asker_cannot_answer(): void
    {
        $custodian = $this->makeUser();
        $custodian->roles()->attach($this->roleWith(['pettycash.spend']));
        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['pettycash.approve', 'pettycash.spend']));

        $fund = $this->fund(['custodian_id' => $custodian->id]);
        $category = $this->category();
        $this->limit(1000);

        // The company's own limit is what turns a voucher into a request.
        $this->actingAs($custodian)
            ->post(route('cash-bank.petty-cash.requests.store'), [
                'fund_id' => $fund->id,
                'expense_category_id' => $category->id,
                'payee' => 'Paper shop',
                'amount' => '2000.00',
                'needed_on' => now()->toDateString(),
                'narration' => 'Reams of paper for the counter',
            ])
            ->assertRedirect(route('cash-bank.petty-cash.requests', ['fund' => $fund->id]));

        $request = PettyCashRequest::query()->sole();
        $this->assertTrue($request->isPending());
        $this->assertNull($request->payment_id);

        // A waiting request is not money: nothing posted, nothing paid, and the
        // float has not moved a paisa.
        $this->assertSame(0, $this->posted());
        $this->assertSame(0, PettyCashTransaction::query()->count());
        $this->assertSame('0.0000', $this->balanceOf($fund));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.requested')->count());

        // The person who asked cannot be the person who answers.
        $this->actingAs($custodian)
            ->post(route('cash-bank.petty-cash.requests.decide', ['pettyRequest' => $request->id]), ['action' => 'approve'])
            ->assertSessionHasErrors('petty_cash');
        $this->assertTrue($request->refresh()->isPending());

        // Somebody else can — and that is the moment the money moves.
        $this->actingAs($checker)
            ->post(route('cash-bank.petty-cash.requests.decide', ['pettyRequest' => $request->id]), [
                'action' => 'approve',
                'note' => 'Needed for the counter',
            ])
            ->assertRedirect(route('cash-bank.petty-cash.requests'));

        $request->refresh();
        $this->assertTrue($request->isApproved());
        $this->assertNotNull($request->payment_id);
        $this->assertSame($checker->id, $request->decided_by);
        $this->assertSame(1, $this->posted());
        $this->assertSame('2000.0000', app(PettyCashService::class)->balanceOf($fund));
        $this->assertSame('3000.00', app(PettyCashService::class)->shortfallOf($fund));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.request_approved')->count());

        // A decided request is history, not a button.
        $this->actingAs($checker)
            ->post(route('cash-bank.petty-cash.requests.decide', ['pettyRequest' => $request->id]), ['action' => 'approve'])
            ->assertSessionHasErrors('petty_cash');
        $this->assertSame(1, $this->posted());
    }

    public function test_below_the_limit_the_same_form_pays_at_once(): void
    {
        $fund = $this->fund();
        $category = $this->category();
        $this->limit(1000);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.requests.store'), [
                'fund_id' => $fund->id,
                'expense_category_id' => $category->id,
                'payee' => 'Tea stall',
                'amount' => '300.00',
                'needed_on' => now()->toDateString(),
            ])
            ->assertRedirect(route('cash-bank.petty-cash.expenses', ['fund' => $fund->id]));

        $this->assertSame(0, PettyCashRequest::query()->count());
        $this->assertSame(1, PettyCashTransaction::query()->count());
        $this->assertSame(1, $this->posted());
        $this->assertSame('4700.0000', $this->balanceOf($fund));
    }

    public function test_asking_without_saying_what_for_is_refused_before_the_ledger(): void
    {
        $fund = $this->fund();
        $category = $this->category();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.requests.store'), [
                'fund_id' => $fund->id,
                'expense_category_id' => $category->id,
                'payee' => '',
                'amount' => '0',
                'needed_on' => '',
            ])
            ->assertSessionHasErrors(['payee', 'amount', 'needed_on']);

        // A category that has been switched off is not a place to file money.
        $category->forceFill(['is_active' => false])->save();

        $this->voucher($fund, $category)->assertSessionHasErrors('petty_cash');

        $this->assertSame(0, PettyCashRequest::query()->count());
        $this->assertSame(0, PettyCashTransaction::query()->count());
        $this->assertSame(0, $this->posted());
    }

    // -------------------------------------------------------- replenishment

    public function test_replenishment_puts_the_float_back_and_is_never_a_second_expense(): void
    {
        $fund = $this->fund();
        $category = $this->category();

        $this->voucher($fund, $category, ['amount' => '1500.00']);
        $this->assertSame('3500.0000', $this->balanceOf($fund));
        $this->assertSame(1, $this->posted());

        $this->topUp($fund, ['amount' => '1500.00'])
            ->assertRedirect(route('cash-bank.petty-cash.replenishments', ['fund' => $fund->id]));

        $topUp = PettyCashTransaction::query()
            ->where('kind', PettyCashTransaction::KIND_REPLENISHMENT)
            ->sole();

        $this->assertStringStartsWith('CT-', (string) $topUp->documentNo());
        $this->assertSame('1500.0000', $topUp->amount);
        $this->assertSame('5000.0000', $this->balanceOf($fund));
        $this->assertSame('0.00', app(PettyCashService::class)->shortfallOf($fund));

        // One payment for the spending, one transfer for the top-up — and the
        // expense side is exactly the rickshaw fare, not the rickshaw plus the float.
        $this->assertSame(1, $this->posted());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'cash_transfer')->count());
        $this->assertEqualsWithDelta(
            1500.0,
            (float) JournalEntry::query()
                ->where('source_type', 'cash_payment')
                ->firstOrFail()
                ->lines()
                ->where('dc', 'debit')
                ->sum('amount'),
            0.0001,
        );
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.replenished')->count());
    }

    public function test_a_top_up_cannot_come_from_the_float_itself_or_from_an_expense_account(): void
    {
        $fund = $this->fund();
        $service = app(PettyCashService::class);

        $this->topUp($fund, ['source_account_id' => $fund->account_id])
            ->assertSessionHasErrors('petty_cash');

        $this->topUp($fund, ['source_account_id' => $this->account('5220')->id])
            ->assertSessionHasErrors('petty_cash');

        // A transfer still has to be more than nothing.
        $this->topUp($fund, ['amount' => '0'])->assertSessionHasErrors('amount');

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame('0.0000', $service->balanceOf($fund));
    }

    // ------------------------------------------------------------- closing

    public function test_a_float_holding_money_or_a_waiting_request_cannot_be_closed(): void
    {
        $fund = $this->fund(['imprest_amount' => '1000.00']);
        $category = $this->category();

        // Money in the tin: closing would lose the question "where did it go?".
        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.close', ['fund' => $fund->id]))
            ->assertSessionHasErrors('petty_cash');
        $this->assertTrue($fund->refresh()->isActive());

        // A request still waiting is the same objection.
        $this->limit(100);
        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.requests.store'), [
                'fund_id' => $fund->id,
                'expense_category_id' => $category->id,
                'payee' => 'Courier',
                'amount' => '500.00',
                'needed_on' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.close', ['fund' => $fund->id]))
            ->assertSessionHasErrors('petty_cash');

        // Decide the request — somebody other than the person who asked — and
        // spend the tin flat; then it closes.
        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['pettycash.approve']));

        $request = PettyCashRequest::query()->sole();
        $this->actingAs($checker)
            ->post(route('cash-bank.petty-cash.requests.decide', ['pettyRequest' => $request->id]), [
                'action' => 'reject',
                'note' => 'Not this month',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(PettyCashRequest::STATUS_REJECTED, $request->refresh()->status);
        $this->assertSame(0, $this->posted());

        $this->voucher($fund, $category, ['amount' => '1000.00'])->assertSessionHasNoErrors();
        $this->assertSame('0.0000', $this->balanceOf($fund));

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.close', ['fund' => $fund->id]))
            ->assertRedirect(route('cash-bank.petty-cash'));

        $fund->refresh();
        $this->assertFalse($fund->isActive());
        $this->assertNotNull($fund->closed_on);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.fund_closed')->count());

        // Nothing can be paid out of a closed tin.
        $this->voucher($fund, $category, ['amount' => '10.00'])
            ->assertSessionHasErrors('petty_cash');
        $this->assertSame(1, PettyCashTransaction::query()->where('kind', PettyCashTransaction::KIND_DISBURSEMENT)->count());
    }

    public function test_the_float_can_be_redescribed_without_moving_its_history(): void
    {
        $fund = $this->fund();
        $accountId = $fund->account_id;
        $successor = $this->makeUser(['name' => 'New Custodian']);

        $this->actingAs($this->admin)
            ->put(route('cash-bank.petty-cash.update', ['fund' => $fund->id]), $this->fundPayload([
                'name' => 'Head office tin (renamed)',
                'custodian_id' => $successor->id,
                'imprest_amount' => '7000.00',
                'description' => 'Now the successor holds it',
            ]))
            ->assertRedirect(route('cash-bank.petty-cash'));

        $fund->refresh();
        $this->assertSame('Head office tin (renamed)', $fund->name);
        $this->assertSame($successor->id, $fund->custodian_id);
        $this->assertSame('7000.0000', $fund->imprest_amount);

        // The code and the ledger account are the same ones: every voucher that
        // was ever posted against this tin still points at the same place.
        $this->assertSame('PC-HEAD', $fund->code);
        $this->assertSame($accountId, $fund->account_id);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'petty_cash.fund_updated')->count());
    }

    // ------------------------------------------------------- other companies

    public function test_another_companys_float_is_not_found_and_not_payable(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = $this->category();

        $foreign = PettyCashFund::query()->create([
            'company_id' => $otherCompany,
            'code' => 'PC-SHADOW',
            'name' => 'Their tin',
            'custodian_id' => $this->admin->id,
            'account_id' => $this->account('1110')->id,
            'imprest_amount' => '100.00',
            'currency' => 'BDT',
            'is_active' => true,
            'opened_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.petty-cash.close', ['fund' => $foreign->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->put(route('cash-bank.petty-cash.update', ['fund' => $foreign->id]), $this->fundPayload())
            ->assertNotFound();

        // Nor can their float be posted to from this company's forms.
        $this->voucher($foreign, $category)->assertSessionHasErrors('fund_id');

        $this->assertSame(0, PettyCashTransaction::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }
}
