<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\BankCharge;
use App\Domain\CashBank\BankChargeRule;
use App\Domain\CashBank\Services\BankChargeService;
use App\Domain\CashBank\Services\MoneyMovementService;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-10 — the money a bank takes without asking.
 *
 * A bank charge is the one posting in this module nobody here performed, and
 * these are the things that make it honest:
 *
 *  · writing a rule charges nothing — a tariff is not a transaction, and the
 *    first charge falls on the date the rule names;
 *  · a charge is two lines and no party (Dr the expense the bank charged, Cr the
 *    account it came out of), posted through the same journal service as
 *    everything else, in its own numbered series;
 *  · a commission is computed from the money that actually left the account since
 *    the last charge, and the arithmetic travels with the row so the figure can be
 *    checked against the bank's own — a percentage of nothing refuses rather than
 *    posting a zero;
 *  · the same rule cannot charge twice for the same day, so a scheduled run that
 *    overlapped a manual one adopts what it finds instead of double-counting;
 *  · a charge that was wrong is answered with a reversal, never an edit, and one
 *    already reversed cannot be reversed again;
 *  · recording what the bank took and writing the rules that take it by
 *    themselves are two separate powers.
 */
class BankChargeDeskTest extends TestCase
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

    /** The bank account charges come out of. */
    protected function bank(): Account
    {
        return $this->account('1120');
    }

    /** The standard chart's own Bank Charges leaf — the desk's default. */
    protected function bankChargesAccount(): Account
    {
        return $this->account('5280');
    }

    /** @return array<string, mixed> */
    protected function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->bank()->id,
            'expense_account_id' => $this->bankChargesAccount()->id,
            'name' => 'Account maintenance',
            'basis' => BankChargeRule::BASIS_FIXED,
            'amount' => '500.00',
            'frequency' => BankChargeRule::QUARTERLY,
            'day_of_month' => 5,
            'starts_on' => now()->toDateString(),
            'narration' => 'Quarterly maintenance on the current account',
            'is_active' => 1,
        ], $overrides);
    }

    protected function rule(array $overrides = [], ?User $actor = null): BankChargeRule
    {
        $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.bank-charge-rules.store'), $this->rulePayload($overrides))
            ->assertSessionHasNoErrors();

        return BankChargeRule::query()->orderByDesc('id')->firstOrFail();
    }

    /** A rule that is already due today, whatever its rhythm. */
    protected function dueRule(array $overrides = []): BankChargeRule
    {
        $rule = $this->rule($overrides);

        $rule->forceFill(['next_due_on' => now()->toDateString()])->save();

        return $rule->refresh();
    }

    protected function service(): BankChargeService
    {
        return app(BankChargeService::class);
    }

    protected function movements(): MoneyMovementService
    {
        return app(MoneyMovementService::class);
    }

    /** Money leaving the bank account — a credit line, which is what commission is charged on. */
    protected function withdraw(float $amount, ?string $date = null): void
    {
        $this->movements()->pay([
            'money_account_id' => $this->bank()->id,
            'counter_account_id' => $this->account('5220')->id,
            'amount' => $amount,
            'paid_on' => $date ?? now()->toDateString(),
            'payee' => 'Landlord',
        ], $this->admin->id);
    }

    /** How many entries the charge desk has posted — the figure that must stay put. */
    protected function posted(): int
    {
        return JournalEntry::query()->where('source_type', 'bank_charge')->count();
    }

    // ------------------------------------------------------------- the desk

    public function test_the_leaf_is_real_and_configuring_the_tariff_is_its_own_power(): void
    {
        $leaf = MenuItem::query()->where('route', '/app/cash-bank/bank-charges')->first();

        $this->assertNotNull($leaf, 'the catalogue leaf for bank charge auto-posting was not mapped');
        $this->assertSame('bank.charges', $leaf->permission?->key);

        // Recording what the bank took is one job; deciding what will be taken
        // automatically from now on is another.
        $clerk = $this->makeUser();
        $clerk->roles()->attach($this->roleWith(['portal.erp.access', 'bank.charges']));

        $this->actingAs($clerk)->get(route('cash-bank.bank-charges'))->assertOk();

        $this->actingAs($clerk)
            ->post(route('cash-bank.bank-charge-rules.store'), $this->rulePayload())
            ->assertForbidden();

        $this->assertSame(0, BankChargeRule::query()->count());
    }

    public function test_writing_a_rule_charges_nothing_and_says_what_it_will_do(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cash-bank.bank-charges'))
            ->assertOk()
            ->assertSee('No bank charge has been recorded yet');

        $rule = $this->rule();

        $this->assertSame('500.0000', $rule->amount);
        $this->assertTrue($rule->isActive());
        $this->assertSame('every quarter on the 5th', $rule->rhythm());
        $this->assertSame('500.00', $rule->termsLabel());
        $this->assertNull($rule->last_charged_on);
        $this->assertSame(0, $rule->charged_count);

        // A tariff is not a transaction.
        $this->assertSame(0, $this->posted());
        $this->assertSame(0, BankCharge::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.bank_charge_rule_saved')->count());

        // A fixed charge without an amount, and a commission without a rate, are
        // both refused — they are different computations.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charge-rules.store'), $this->rulePayload(['amount' => null, 'name' => 'No amount']))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charge-rules.store'), $this->rulePayload([
                'basis' => BankChargeRule::BASIS_PERCENT,
                'rate_percent' => null,
                'name' => 'No rate',
            ]))
            ->assertSessionHasErrors('rate_percent');

        $this->assertSame(1, BankChargeRule::query()->count());
        $this->assertSame(0, $this->posted());
    }

    // ---------------------------------------------------------- one charge

    public function test_recording_a_charge_posts_two_lines_and_no_party(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), [
                'account_id' => $this->bank()->id,
                'expense_account_id' => $this->bankChargesAccount()->id,
                'amount' => '750.00',
                'charged_on' => now()->toDateString(),
                'narration' => 'SMS alert fee',
                'reference' => 'STMT-4471',
            ])
            ->assertRedirect(route('cash-bank.bank-charges'));

        $charge = BankCharge::query()->sole();

        $this->assertStringStartsWith('BC-', $charge->charge_no);
        $this->assertSame('750.0000', $charge->amount);
        $this->assertSame(BankCharge::STATUS_POSTED, $charge->status);
        $this->assertNull($charge->rule_id);
        $this->assertSame('recorded by hand', $charge->originLabel());
        $this->assertNotNull($charge->journal_entry_id);

        $entry = $charge->journalEntry;
        $debit = $entry->lines->firstWhere('dc', 'debit');
        $credit = $entry->lines->firstWhere('dc', 'credit');

        $this->assertSame(1, $this->posted());
        $this->assertCount(2, $entry->lines);
        $this->assertSame($this->bankChargesAccount()->id, $debit->account_id);
        $this->assertSame($this->bank()->id, $credit->account_id);
        $this->assertEqualsWithDelta(750.0, (float) $debit->amount, 0.0001);
        $this->assertEqualsWithDelta(750.0, (float) $credit->amount, 0.0001);
        $this->assertNull($debit->party_id, 'a bank charge has no party: nobody was paid');
        $this->assertSame('bank_charge', $entry->source_type);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.bank_charge_recorded')->count());

        // The register shows what the bank took and what it was booked to.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.bank-charges'))
            ->assertOk()
            ->assertSee($charge->charge_no)
            ->assertSee('SMS alert fee');
    }

    public function test_a_charge_cannot_come_from_a_ledger_leaf_or_be_booked_to_a_heading(): void
    {
        $payload = [
            'expense_account_id' => $this->bankChargesAccount()->id,
            'amount' => '100.00',
            'charged_on' => now()->toDateString(),
        ];

        // Not money: a rent expense is not a drawer the bank can take from.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), $payload + ['account_id' => $this->account('5220')->id])
            ->assertSessionHasErrors('bank_charge');

        // Not an expense: a heading of accounts, and a bank account, are both
        // refused as a place to book a cost.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), $payload + [
                'account_id' => $this->bank()->id,
                'expense_account_id' => $this->account('5200')->id,
            ])
            ->assertSessionHasErrors('bank_charge');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), $payload + [
                'account_id' => $this->bank()->id,
                'expense_account_id' => $this->bank()->id,
            ])
            ->assertSessionHasErrors('bank_charge');

        $this->assertSame(0, $this->posted());
        $this->assertSame(0, BankCharge::query()->count());
    }

    // ------------------------------------------------------------- the run

    public function test_a_due_rule_charges_once_and_moves_its_own_date(): void
    {
        $rule = $this->dueRule();

        $result = $this->service()->generateDue();

        $this->assertSame(1, $result['generated']);
        $this->assertSame(0, $result['refused']);
        $this->assertSame(1, $this->posted());

        $charge = BankCharge::query()->sole();

        $this->assertSame($rule->id, $charge->rule_id);
        $this->assertSame('generated by Account maintenance', $charge->originLabel());
        $this->assertSame($rule->next_due_on->toDateString(), $charge->charged_on->toDateString());

        // The date moved by the rhythm, and it moved once.
        $rule->refresh();
        $this->assertSame(1, $rule->charged_count);
        $this->assertNotNull($rule->last_charged_on);
        $this->assertTrue($rule->next_due_on->gt(now()), 'a quarterly rule is not due again today');
        $this->assertSame(5, (int) $rule->next_due_on->day, 'the rule keeps the day of the month it was written with');
        $this->assertSame(
            now()->copy()->addMonthsNoOverflow(3)->format('Y-m'),
            $rule->next_due_on->format('Y-m'),
            'a quarterly rule moves three months, not one',
        );

        // A second run is a no-op, not a second quarter of charges.
        $again = $this->service()->generateDue();
        $this->assertSame(0, $again['generated']);
        $this->assertSame(1, $this->posted());
        $this->assertSame(1, BankCharge::query()->count());

        // Even if the date is put back by hand, the (rule, date) index is the
        // referee and the run adopts what is already there.
        $rule->forceFill(['next_due_on' => $charge->charged_on->toDateString()])->save();
        $adopted = $this->service()->generateOne($rule->refresh(), $this->admin);

        $this->assertSame($charge->id, $adopted->id);
        $this->assertSame(1, $this->posted());
        $this->assertSame(1, BankCharge::query()->count());
    }

    public function test_a_commission_is_computed_on_what_left_the_account_since_the_last_charge(): void
    {
        // 0.5% of withdrawals, minimum 100. Two withdrawals of 20,000 each leave
        // the account before the charge falls due.
        $rule = $this->dueRule([
            'name' => 'Commission on withdrawals',
            'basis' => BankChargeRule::BASIS_PERCENT,
            'amount' => null,
            'rate_percent' => '0.5',
            'min_amount' => '100.00',
            'starts_on' => now()->copy()->subMonth()->toDateString(),
        ]);

        $this->withdraw(20000);
        $this->withdraw(20000);

        $quote = $this->service()->quote($rule->refresh(), now());

        $this->assertSame('40000.0000', $quote['turnover']);
        $this->assertEqualsWithDelta(200.0, (float) $quote['amount'], 0.0001);

        $this->service()->generateDue();

        $charge = BankCharge::query()->sole();

        $this->assertEqualsWithDelta(200.0, (float) $charge->amount, 0.0001);
        $this->assertSame('40000.0000', $charge->turnover);
        $this->assertSame('0.5% of 40,000.00 of withdrawals', $charge->basisLabel());
        $this->assertSame(1, $this->posted());
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'cash_payment')->count(), 'the two withdrawals came through the payment desk');

        // The next commission is measured from this charge, not from the start.
        $rule->refresh();
        $this->assertSame($charge->charged_on->toDateString(), $rule->last_charged_on->toDateString());
        $this->assertSame('0.0000', $this->service()->quote($rule, now())['turnover']);

        // Nothing left the account this period, so there is no commission to
        // take — the minimum is a floor under a real commission, not a licence
        // to charge when nothing happened.
        $rule->forceFill(['next_due_on' => now()->toDateString()])->save();

        $this->service()->generateDue();

        $this->assertSame(1, BankCharge::query()->count());
        $this->assertSame(1, $this->posted());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.bank_charge_refused')->count());
    }

    public function test_a_charge_for_a_commission_on_an_idle_account_is_refused_at_the_desk_too(): void
    {
        $rule = $this->rule([
            'basis' => BankChargeRule::BASIS_PERCENT,
            'amount' => null,
            'rate_percent' => '0.15',
            'min_amount' => '100.00',
        ]);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), [
                'rule_id' => $rule->id,
                'charged_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('bank_charge');

        $this->assertSame(0, $this->posted());
        $this->assertSame(0, BankCharge::query()->count());
    }

    // -------------------------------------------------------- reversals, ends

    public function test_a_wrong_charge_is_answered_with_a_reversal_rather_than_erased(): void
    {
        $rule = $this->dueRule();
        $this->service()->generateDue();

        $charge = BankCharge::query()->sole();
        $original = $charge->journal_entry_id;

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.reverse', $charge), ['reason' => 'The bank reversed it'])
            ->assertRedirect(route('cash-bank.bank-charges'));

        $charge->refresh();

        $this->assertTrue($charge->isReversed());
        $this->assertSame($original, $charge->journal_entry_id, 'the original entry stays exactly where it was');
        $this->assertNotNull($charge->reversal_entry_id);
        $this->assertSame('The bank reversed it', $charge->reversal_reason);
        $this->assertSame(2, $this->posted(), 'the charge and the entry that answers it');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.bank_charge_reversed')->count());

        // A reversal needs a reason, and one reversal is enough.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.reverse', $charge), ['reason' => '  '])
            ->assertSessionHasErrors('bank_charge');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.reverse', $charge), ['reason' => 'Again'])
            ->assertSessionHasErrors('bank_charge');

        $this->assertSame(2, $this->posted());
    }

    public function test_a_rule_whose_arrangement_ended_stops_itself(): void
    {
        $rule = $this->dueRule(['ends_on' => now()->toDateString()]);

        $this->service()->generateDue();

        $this->assertSame(1, $this->posted());

        $rule->refresh();

        // The next date would fall outside the arrangement, so the rule stops
        // rather than charging into a period nobody agreed to.
        $this->assertFalse($rule->isActive());
        $this->assertFalse($rule->isDue());
        $this->assertSame(1, BankCharge::query()->count());
    }

    public function test_a_paused_rule_charges_nothing_and_resuming_starts_from_the_next_occurrence(): void
    {
        $rule = $this->dueRule();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charge-rules.toggle', $rule))
            ->assertRedirect(route('cash-bank.bank-charges'));

        $rule->refresh();
        $this->assertFalse($rule->isActive());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash.bank_charge_rule_paused')->count());

        $this->service()->generateDue();

        $this->assertSame(0, $this->posted());
        $this->assertSame(0, BankCharge::query()->count());

        // Resumed months later, the desk starts from the next occurrence rather
        // than firing for every period it missed: what the bank really took in
        // the meantime is recorded by hand.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charge-rules.toggle', $rule))
            ->assertRedirect(route('cash-bank.bank-charges'));

        $rule->refresh();
        $this->assertTrue($rule->isActive());
        $this->assertTrue($rule->next_due_on->gte(now()->copy()->startOfDay()));
        $this->assertSame(0, $this->posted());
    }

    // --------------------------------------------------------- other company

    public function test_another_companys_rule_and_charge_are_not_reachable(): void
    {
        $rule = $this->dueRule();

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = BankCharge::query()->create([
            'company_id' => $otherCompany,
            'rule_id' => null,
            'account_id' => $this->bank()->id,
            'expense_account_id' => $this->bankChargesAccount()->id,
            'charge_no' => 'BC-2026-00099',
            'charged_on' => now()->toDateString(),
            'amount' => '999.00',
            'basis' => BankChargeRule::BASIS_FIXED,
            'status' => BankCharge::STATUS_POSTED,
        ]);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.reverse', $foreign), ['reason' => 'Not ours'])
            ->assertNotFound();

        $foreignRule = BankChargeRule::query()->create([
            'company_id' => $otherCompany,
            'account_id' => $this->bank()->id,
            'expense_account_id' => $this->bankChargesAccount()->id,
            'name' => 'Their rule',
            'basis' => BankChargeRule::BASIS_FIXED,
            'amount' => '10.00',
            'frequency' => BankChargeRule::MONTHLY,
            'day_of_month' => 1,
            'starts_on' => now()->toDateString(),
            'next_due_on' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charge-rules.toggle', $foreignRule))
            ->assertNotFound();

        // Nor can their rule be used to post a charge into this company's books.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.bank-charges.store'), ['rule_id' => $foreignRule->id, 'charged_on' => now()->toDateString()])
            ->assertSessionHasErrors('rule_id');

        // Their rule is never picked up by this company's run.
        $this->service()->generateDue();

        $this->assertSame(0, BankCharge::query()->where('charge_no', '!=', 'BC-2026-00099')->count());
        $this->assertSame(0, $this->posted());
    }

    // ---------------------------------------------------------------- console

    public function test_the_daily_command_charges_headless_and_reports_before_it_acts(): void
    {
        $this->dueRule();

        // A dry run says what would happen and posts nothing.
        $this->artisan('erp:cash:bank-charges', ['--company' => $this->admin->company_id, '--dry-run' => true])
            ->expectsOutputToContain('nothing was posted')
            ->assertExitCode(0);

        $this->assertSame(0, $this->posted());
        $this->assertSame(0, BankCharge::query()->count());

        $this->artisan('erp:cash:bank-charges', ['--company' => $this->admin->company_id])
            ->expectsOutputToContain('1 bank charge(s) posted')
            ->assertExitCode(0);

        $this->assertSame(1, $this->posted());
        $this->assertSame(1, BankCharge::query()->count());

        // And the rule is not due again: the command did not leave it there.
        $rule = BankChargeRule::query()->sole();
        $this->assertTrue($rule->next_due_on->gt(now()));
        $this->assertSame(1, $rule->charged_count);
    }
}
