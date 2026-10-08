<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\CashBank\CashTransfer;
use App\Domain\CashBank\Services\MoneyMovementService;
use App\Domain\Foundation\User;
use App\Domain\Sales\Payment;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-01 … §08-07, §08-11 — the cash and bank desk.
 *
 * What this pins, in the order the complaints would arrive:
 *  · every money account is a real leaf in the chart of accounts, so nothing on
 *    the desk can disagree with the general ledger — including the position;
 *  · a movement is a balanced journal entry or it does not exist: a receipt
 *    debits the account the money landed in, a payment credits the account it
 *    left, and neither is written without the other side;
 *  · money moves between the company's own accounts through the transfer desk
 *    only, and a transfer posts both legs or neither — the same request twice
 *    moves the money once;
 *  · an account still holding a balance cannot be closed, and an account holding
 *    no balance can be, after which it refuses new movement;
 *  · opening a mobile wallet is a different power from opening a bank account.
 */
class CashBankDeskTest extends TestCase
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

    /** The chart's own money accounts, which the standard COA ships with. */
    protected function account(string $code): Account
    {
        return Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();
    }

    public function test_the_desk_shows_the_positions_from_the_books_and_needs_the_permission(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cash-bank.index'))
            ->assertOk()
            ->assertSee('Cash in Hand')
            ->assertSee('Bank Account')
            // Every figure comes from posted journal lines, so a fresh company
            // reads zero rather than a seeded number pretending to be money.
            ->assertSee('0.00');

        $this->actingAs($this->makeUser())->get(route('cash-bank.index'))->assertForbidden();
    }

    public function test_a_bank_account_is_declared_as_a_real_leaf_in_the_chart(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'bank',
                'code' => '1121',
                'name' => 'Islami Bank — current account',
                'bank_name' => 'Islami Bank Bangladesh',
                'account_number' => '2050447188301',
                'currency' => 'BDT',
            ])
            ->assertRedirect(route('cash-bank.accounts'))
            ->assertSessionHasNoErrors();

        $account = $this->account('1121');

        $this->assertTrue((bool) $account->is_bank);
        $this->assertFalse((bool) $account->is_group, 'a money account posts, so it can never be a heading');
        $this->assertSame('bank', $account->instrument);
        $this->assertSame('Islami Bank Bangladesh', $account->bank_name);
        $this->assertSame($this->account('1100')->id, $account->parent_id, 'money sits under Current Assets');

        // The registry and the chart are the same list: the account is postable.
        $this->assertContains($account->id, Account::query()->postable()->pluck('id')->all());

        $this->actingAs($this->admin)
            ->get(route('cash-bank.accounts'))
            ->assertOk()
            ->assertSee('Islami Bank — current account')
            ->assertSee('2050447188301');
    }

    public function test_a_bank_account_without_a_bank_and_a_wallet_without_a_provider_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'bank', 'code' => '1122', 'name' => 'Nameless bank',
            ])
            ->assertSessionHasErrors('bank_name');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'wallet', 'code' => '1131', 'name' => 'MFS float',
            ])
            ->assertSessionHasErrors('wallet_provider');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'wallet', 'code' => '1131', 'name' => 'bKash merchant float',
                'wallet_provider' => 'bkash', 'account_number' => '01711000000',
            ])
            ->assertSessionHasNoErrors();

        $wallet = $this->account('1131');
        $this->assertSame('wallet', $wallet->instrument);
        $this->assertFalse((bool) $wallet->is_bank, 'a wallet is not a bank — money leaves it through a different door');

        // A code the chart already uses is the chart's, not the desk's.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'cash', 'code' => '1131', 'name' => 'Second float',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_a_receipt_debits_the_account_the_money_landed_in_and_credits_what_it_was_for(): void
    {
        $cash = $this->account('1110');
        $otherIncome = $this->account('4200');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.receipts.store'), [
                'direction' => 'in',
                'money_account_id' => $cash->id,
                'counter_account_id' => $otherIncome->id,
                'amount' => 1500.50,
                'moved_on' => now()->toDateString(),
                'party_name' => 'Rahman Traders',
                'narration' => 'Scrap sale',
            ])
            ->assertRedirect(route('cash-bank.receipts'))
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('direction', 'in')->firstOrFail();

        $this->assertSame('1500.5000', (string) $payment->amount);
        $this->assertSame($cash->id, $payment->account_id);
        $this->assertSame('cash', $payment->method);
        $this->assertStringStartsWith('MR-', $payment->receipt_no);

        $lines = JournalLine::query()->where('journal_entry_id', $payment->journal_entry_id)->get();

        $this->assertCount(2, $lines);
        $this->assertSame('1500.5000', (string) $lines->firstWhere('account_id', $cash->id)->amount);
        $this->assertSame('debit', $lines->firstWhere('account_id', $cash->id)->dc);
        $this->assertSame('credit', $lines->firstWhere('account_id', $otherIncome->id)->dc);

        $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $this->assertSame((string) $entry->total_debit, (string) $entry->total_credit);

        // …and the desk's position is that same number, because it is the same books.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.index'))
            ->assertOk()
            ->assertSee('1,500.50');

        // The day-by-day window is keyed by the movement's own date; a receipt
        // entered today has to land on today's row, not on none of them.
        $window = app(MoneyMovementService::class)->dailyNet(3);

        $this->assertSame(1500.5, $window[now()->toDateString()]['in']);
        $this->assertSame(0.0, $window[now()->toDateString()]['out']);
    }

    public function test_a_payment_credits_the_account_the_money_left_and_refuses_a_money_account_as_its_counter(): void
    {
        $cash = $this->account('1110');
        $rent = $this->account('5220');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.payments.store'), [
                'direction' => 'out',
                'money_account_id' => $cash->id,
                'counter_account_id' => $rent->id,
                'amount' => 800,
                'party_name' => 'Landlord',
                'narration' => 'October rent',
            ])
            ->assertRedirect(route('cash-bank.payments'))
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('direction', 'out')->firstOrFail();
        $lines = JournalLine::query()->where('journal_entry_id', $payment->journal_entry_id)->get();

        $this->assertSame('debit', $lines->firstWhere('account_id', $rent->id)->dc);
        $this->assertSame('credit', $lines->firstWhere('account_id', $cash->id)->dc);
        $this->assertStringStartsWith('EX-', $payment->receipt_no);

        // Paying "to" a bank account is a transfer wearing the wrong form.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.payments.store'), [
                'direction' => 'out',
                'money_account_id' => $cash->id,
                'counter_account_id' => $this->account('1120')->id,
                'amount' => 100,
            ])
            ->assertSessionHasErrors('cash_bank');

        // A movement of nothing is not a movement.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.payments.store'), [
                'direction' => 'out',
                'money_account_id' => $cash->id,
                'counter_account_id' => $rent->id,
                'amount' => 0,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_a_transfer_posts_both_legs_and_the_same_request_twice_moves_the_money_once(): void
    {
        $cash = $this->account('1110');
        $bank = $this->account('1120');

        $payload = [
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 4000,
            'transferred_on' => now()->toDateString(),
            'reference' => 'PAYIN-771',
            'narration' => 'End of day banking',
        ];

        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), $payload)
            ->assertRedirect(route('cash-bank.transfer'))
            ->assertSessionHasNoErrors();

        $transfer = CashTransfer::query()->firstOrFail();
        $lines = JournalLine::query()->where('journal_entry_id', $transfer->journal_entry_id)->get();

        $this->assertCount(2, $lines);
        $this->assertSame('debit', $lines->firstWhere('account_id', $bank->id)->dc, 'the money landed in the bank');
        $this->assertSame('credit', $lines->firstWhere('account_id', $cash->id)->dc, 'and left the till');
        $this->assertStringStartsWith('CT-', $transfer->transfer_no);
        $this->assertSame(CashTransfer::STATUS_POSTED, $transfer->status);

        // The desk's answer to a double submit is the same document, not a second move.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), $payload + ['idempotency_key' => 'banking-2026-10-08'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), $payload + ['idempotency_key' => 'banking-2026-10-08'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CashTransfer::query()->count(), 'a retried transfer moves the money once');

        // Both legs are the same amount, and they cancel in the ledger's own sums.
        $this->assertSame(
            (string) $lines->firstWhere('account_id', $bank->id)->amount,
            (string) $lines->firstWhere('account_id', $cash->id)->amount,
        );

        // Moving money to the account it is already in is refused on the form.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), [
                'from_account_id' => $cash->id, 'to_account_id' => $cash->id, 'amount' => 10,
            ])
            ->assertSessionHasErrors('to_account_id');

        // And a transfer cannot be made from an account that is not money at all.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), [
                'from_account_id' => $this->account('1140')->id,
                'to_account_id' => $bank->id,
                'amount' => 10,
            ])
            ->assertSessionHasErrors('cash_bank');
    }

    public function test_an_account_holding_money_cannot_be_closed_and_a_closed_one_refuses_new_movement(): void
    {
        $cash = $this->account('1110');

        // Put money in the till first: an account holding it must not be closable.
        $this->actingAs($this->admin)->post(route('cash-bank.receipts.store'), [
            'direction' => 'in',
            'money_account_id' => $cash->id,
            'counter_account_id' => $this->account('4200')->id,
            'amount' => 300,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.close', ['account' => $cash->id]))
            ->assertSessionHasErrors('cash_bank');

        $this->assertTrue((bool) $cash->refresh()->is_active, 'nothing may take a funded account off the desk');

        // Empty it through the payment desk, and it closes.
        $this->actingAs($this->admin)->post(route('cash-bank.payments.store'), [
            'direction' => 'out',
            'money_account_id' => $cash->id,
            'counter_account_id' => $this->account('5250')->id,
            'amount' => 300,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.close', ['account' => $cash->id]))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $cash->refresh()->is_active);

        // A closed account is closed to every kind of movement.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.receipts.store'), [
                'direction' => 'in',
                'money_account_id' => $cash->id,
                'counter_account_id' => $this->account('4200')->id,
                'amount' => 5,
            ])
            ->assertSessionHasErrors('cash_bank');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.transfer.store'), [
                'from_account_id' => $cash->id,
                'to_account_id' => $this->account('1120')->id,
                'amount' => 5,
            ])
            ->assertSessionHasErrors('cash_bank');
    }

    public function test_the_book_answers_with_the_ledger_and_exports_the_same_rows(): void
    {
        $cash = $this->account('1110');

        $this->actingAs($this->admin)->post(route('cash-bank.receipts.store'), [
            'direction' => 'in',
            'money_account_id' => $cash->id,
            'counter_account_id' => $this->account('4200')->id,
            'amount' => 250,
            'party_name' => 'Courier settlement',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('cash-bank.book', ['account' => $cash->id]))
            ->assertOk()
            ->assertSee('Courier settlement')
            ->assertSee('250.00')
            // The book opens where the ledger opens, brought forward rather than
            // recomputed from the window the operator typed.
            ->assertSee('Brought forward');

        $response = $this->actingAs($this->admin)
            ->get(route('cash-bank.book', ['account' => $cash->id, 'format' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString('Running balance', $response->streamedContent());
        $this->assertStringContainsString('Courier settlement', $response->streamedContent());

        // An account that is not money has no book here.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.book', ['account' => $this->account('1140')->id]))
            ->assertNotFound();
    }

    public function test_opening_a_wallet_is_a_different_power_from_opening_a_bank_account(): void
    {
        $banker = $this->makeUser();
        $banker->roles()->attach($this->roleWith(['bank.accounts', 'cash.view'])->id);

        $this->actingAs($banker)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'bank', 'code' => '1123', 'name' => 'City Bank — current',
                'bank_name' => 'City Bank',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($banker)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'wallet', 'code' => '1132', 'name' => 'Nagad float',
                'wallet_provider' => 'nagad',
            ])
            ->assertForbidden();

        // A user with no cash key at all cannot even read the desk.
        $this->actingAs($this->makeUser())
            ->get(route('cash-bank.receipts'))
            ->assertForbidden();
    }
}
