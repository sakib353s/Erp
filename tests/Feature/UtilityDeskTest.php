<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Business\Services\UtilityService;
use App\Domain\Business\UtilityBill;
use App\Domain\Business\UtilityProvider;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-15 — the utility desk.
 *
 * What is pinned here, in the order the questions actually get asked:
 *
 *  · **filing a bill is not paying it** — filing records the obligation and posts
 *    nothing; the ledger hears about it exactly once, on the day the money goes,
 *    and the bill keeps the entry number that proves it;
 *  · **the payment posts the right two accounts** — the provider's expense account
 *    on the debit side (5230 utilities or 5220 rent, by the registry rather than by
 *    a dropdown) and the account the money left on the credit;
 *  · **a big payment waits** — at or above the company's own approval limit the
 *    money does not move, the bill says so, and somebody other than the person who
 *    filed it has to agree;
 *  · **one bill per provider per month** — a second one is refused by name,
 *    because that is how a company pays twice;
 *  · **nothing is stored twice** — overdue and due-soon are read from the clock,
 *    and a paid bill cannot be voided back out of the ledger;
 *  · **the six menu leaves point at real pages**, and the two permissions are real
 *    doors.
 */
class UtilityDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-02-20 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->headOffice = $this->defaultBranch();
        $this->bindTenantContext($this->admin, $this->headOffice);

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------- helpers */

    protected function desk(): UtilityService
    {
        return app(UtilityService::class);
    }

    protected function provider(string $code = 'DESCO', ?string $family = null): UtilityProvider
    {
        // The registry is materialised lazily, the way the desk does it on its
        // first visit — so a helper that asks for DESCO is also a helper that
        // switches the shelves on.
        $this->desk()->materialiseDefaults();

        $provider = UtilityProvider::query()
            ->where('company_id', Company::current()?->id)
            ->where('code', $code)
            ->firstOrFail();

        if ($family !== null) {
            $this->assertSame($family, $provider->family, "{$code} should sit on the {$family} shelf.");
        }

        return $provider;
    }

    /** The bank account the money leaves from. */
    protected function bank(): Account
    {
        return app(\App\Domain\CashBank\Services\MoneyAccountService::class)->accounts()->firstOrFail();
    }

    /** A bill filed through the real service, the way the desk does it. */
    protected function bill(float $amount = 4800, string $period = '2027-02', string $code = 'DESCO', ?string $due = null): UtilityBill
    {
        $due = $due ?? '2027-02-28';

        return $this->desk()->record([
            'provider_id' => $this->provider($code)->id,
            'period_month' => $period,
            // A bill is posted before it falls due, which is the whole reason the
            // desk exists: the issue date is a week ahead of the due date.
            'issue_date' => Carbon::parse($due)->subDays(7)->toDateString(),
            'due_date' => $due,
            'amount' => $amount,
            'consumption' => 320,
            'narration' => 'Uttara office',
        ], $this->admin);
    }

    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();

        $this->grant($user, $keys);

        return $user;
    }

    protected function threshold(float $value): void
    {
        app(SettingService::class)->set('cash', 'expense_approval_above', $value, null, $this->admin);
    }

    /* -------------------------------------------------------------- 12-15 CRUD */

    public function test_the_registry_arrives_with_the_six_counterparties_a_bangladeshi_company_has(): void
    {
        $providers = $this->desk()->providers();

        $this->assertCount(6, $providers);
        $this->assertSame(
            ['DESCO', 'DPDC', 'INTERNET', 'RENT', 'TITAS', 'WASA'],
            $providers->pluck('code')->sort()->values()->all(),
        );

        // Every shelf the menu names is represented, and each provider carries the
        // account its bills belong in — utilities for the four metered ones, rent
        // for the landlord.
        $this->assertSame(
            ['electricity', 'gas', 'internet', 'rent', 'water'],
            $providers->pluck('family')->unique()->sort()->values()->all(),
        );

        $this->assertSame('5230', $this->provider('DESCO')->account?->code);
        $this->assertSame('5230', $this->provider('WASA', UtilityProvider::FAMILY_WATER)->account?->code);
        $this->assertSame('5230', $this->provider('TITAS', UtilityProvider::FAMILY_GAS)->account?->code);
        $this->assertSame('5230', $this->provider('INTERNET', UtilityProvider::FAMILY_INTERNET)->account?->code);
        $this->assertSame('5220', $this->provider('RENT', UtilityProvider::FAMILY_RENT)->account?->code);
    }

    public function test_the_registry_is_materialised_once_and_edited_never_overwritten(): void
    {
        $this->desk()->providers();

        $desco = $this->provider('DESCO');
        $this->desk()->saveProvider([
            'code' => 'DESCO',
            'name' => 'DESCO — Uttara zone',
            'family' => UtilityProvider::FAMILY_ELECTRICITY,
            'due_day' => 12,
            'consumer_no' => 'UT-99120',
            'is_active' => true,
        ], $desco, $this->admin);

        // A later visit must not materialise a second DESCO or restore the name.
        $this->assertSame(0, $this->desk()->materialiseDefaults());
        $this->assertSame(6, UtilityProvider::query()->where('company_id', Company::current()?->id)->count());
        $this->assertSame('DESCO — Uttara zone', $this->provider('DESCO')->name);
        $this->assertSame(12, $this->provider('DESCO')->due_day);
    }

    public function test_a_provider_cannot_be_pointed_at_a_group_account_or_another_companys(): void
    {
        $this->desk()->providers();

        $group = Account::query()
            ->where('company_id', Company::current()?->id)
            ->where('is_group', true)
            ->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('group');

        $this->desk()->saveProvider([
            'code' => 'NEW-UTIL',
            'name' => 'New utility',
            'family' => UtilityProvider::FAMILY_WATER,
            'expense_account_id' => $group->id,
        ], null, $this->admin);
    }

    /* ------------------------------------------------------- filing and paying */

    public function test_filing_a_bill_records_the_obligation_and_posts_nothing(): void
    {
        $bill = $this->bill(4800);

        $this->assertSame(UtilityBill::STATUS_RECORDED, $bill->status);
        $this->assertNull($bill->journal_entry_id);
        $this->assertNull($bill->paid_on);
        $this->assertSame('UB-000001', $bill->bill_no);
        $this->assertSame('2027-02', $bill->period_month);
        $this->assertSame('320.0000', (string) $bill->consumption);
        $this->assertSame('kWh', $bill->consumption_unit);
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'utility_bill')->count());

        // The desk shows it, and the page says nothing has been paid.
        $page = $this->actingAs($this->admin)->get(route('business.utilities.index'));
        $page->assertOk();
        $page->assertSee('UB-000001');
        $page->assertSee('DESCO');

        $this->actingAs($this->admin)
            ->get(route('business.utilities.show', $bill))
            ->assertOk()
            ->assertSee('Nothing has been posted');
    }

    public function test_paying_a_bill_posts_the_providers_account_against_the_money_account(): void
    {
        $bill = $this->bill(4800);
        $bank = $this->bank();

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), [
                'money_account_id' => $bank->id,
                'paid_on' => '2027-02-22',
            ])
            ->assertSessionHasNoErrors();

        $bill->refresh();

        $this->assertSame(UtilityBill::STATUS_PAID, $bill->status);
        $this->assertSame('2027-02-22', $bill->paid_on?->toDateString());
        $this->assertSame($bank->id, (int) $bill->money_account_id);
        $this->assertNotNull($bill->journal_entry_id);

        // One entry, two legs, the right way round.
        $entry = $bill->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->posting_state);
        $this->assertSame('utility_bill', $entry->source_type);
        $this->assertSame('2027-02-22', $entry->entry_date?->toDateString());

        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);

        $debit = $lines->firstWhere('dc', 'debit');
        $credit = $lines->firstWhere('dc', 'credit');

        $this->assertSame((int) $this->provider('DESCO')->account->id, (int) $debit->account_id);
        $this->assertSame((int) $bank->id, (int) $credit->account_id);
        $this->assertEquals(4800.0, (float) $debit->amount);
        $this->assertEquals(4800.0, (float) $credit->amount);

        // And the invoice-to-ledger tie: what the register says was paid is what
        // the expense account actually carries.
        $this->assertSame(4800.0, (float) $debit->amount);
    }

    public function test_a_second_bill_for_the_same_provider_and_month_is_refused_by_name(): void
    {
        $bill = $this->bill(4800);

        try {
            $this->bill(5000);
            $this->fail('A second bill for the same provider and month should have been refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString($bill->bill_no, $error->getMessage());
            $this->assertStringContainsString('February 2027', $error->getMessage());
        }

        $this->assertSame(1, UtilityBill::query()->where('provider_id', $bill->provider_id)->count());

        // The desk says the same thing on the page rather than dying.
        $response = $this->actingAs($this->admin)->post(route('business.utilities.store'), [
            'provider_id' => $bill->provider_id,
            'period_month' => '2027-02',
            'issue_date' => '2027-02-05',
            'due_date' => '2027-02-28',
            'amount' => 5000,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString($bill->bill_no, $this->allFlashedErrors());
    }

    /* ------------------------------------------------------------ the approval */

    public function test_a_payment_at_or_above_the_limit_waits_and_moves_nothing(): void
    {
        $this->threshold(4000);
        $bill = $this->bill(4800);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id])
            ->assertSessionHasNoErrors();

        $bill->refresh();

        $this->assertSame(UtilityBill::STATUS_PENDING, $bill->status);
        $this->assertTrue($bill->approval_gate);
        $this->assertNull($bill->journal_entry_id);
        $this->assertNull($bill->paid_on);
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'utility_bill')->count());

        // The desk says so out loud, with the limit in the sentence.
        $this->assertStringContainsString('4,000.00', (string) session('status'));

        $page = $this->actingAs($this->admin)->get(route('business.utilities.show', $bill));
        $page->assertOk();
        $page->assertSee('Waiting for a second signature');
    }

    public function test_under_the_limit_the_same_action_pays_immediately(): void
    {
        $this->threshold(5000);
        $bill = $this->bill(4800);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(UtilityBill::STATUS_PAID, $bill->fresh()->status);
    }

    public function test_approving_a_held_payment_needs_somebody_other_than_the_person_who_filed_it(): void
    {
        $this->threshold(4000);
        $bill = $this->bill(4800);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id]);

        // The maker cannot be the checker.
        $this->actingAs($this->admin)
            ->post(route('business.utilities.approve', $bill), ['decision_note' => 'looks right'])
            ->assertSessionHasErrors('decision_note');

        $this->assertSame(UtilityBill::STATUS_PENDING, $bill->fresh()->status);

        // Somebody else can.
        $checker = $this->userWith(['business.utilities.view', 'business.utilities.manage']);

        $this->actingAs($checker)
            ->post(route('business.utilities.approve', $bill), ['decision_note' => 'checked against the meter'])
            ->assertSessionHasNoErrors();

        $bill->refresh();

        $this->assertSame(UtilityBill::STATUS_PAID, $bill->status);
        $this->assertSame($checker->id, (int) $bill->decided_by);
        $this->assertSame('checked against the meter', $bill->decision_note);
        $this->assertNotNull($bill->journal_entry_id);
    }

    public function test_sending_a_held_payment_back_leaves_an_unpaid_bill_and_nothing_moved(): void
    {
        $this->threshold(4000);
        $bill = $this->bill(4800);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id]);

        $checker = $this->userWith(['business.utilities.view', 'business.utilities.manage']);

        $this->actingAs($checker)
            ->post(route('business.utilities.reject', $bill), ['decision_note' => 'the reading is wrong'])
            ->assertSessionHasNoErrors();

        $bill->refresh();

        $this->assertSame(UtilityBill::STATUS_RECORDED, $bill->status);
        $this->assertFalse($bill->approval_gate);
        $this->assertNull($bill->money_account_id);
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'utility_bill')->count());
    }

    /* ------------------------------------------------------------------ state */

    public function test_overdue_and_due_soon_are_read_from_the_clock(): void
    {
        $overdue = $this->bill(1200, '2027-01', 'DESCO', '2027-01-28');
        $soon = $this->bill(2400, '2027-02', 'WASA', '2027-02-25');
        $later = $this->bill(3600, '2027-02', 'TITAS', '2027-03-20');

        $this->assertTrue($overdue->isOverdue());
        $this->assertSame('overdue', $overdue->state());
        $this->assertSame(23, abs((int) $overdue->daysToDue()));

        $this->assertTrue($soon->isDueWithin());
        $this->assertSame('due_soon', $soon->state());

        $this->assertFalse($later->isDueWithin());
        $this->assertSame('scheduled', $later->state());

        // Time moves, the answer moves with it: no stored flag to be stale.
        Carbon::setTestNow(Carbon::parse('2027-03-25 09:00:00'));
        $this->assertTrue($later->fresh()->isOverdue());
        $this->assertSame('overdue', $later->fresh()->state());
        Carbon::setTestNow(Carbon::parse('2027-02-20 09:00:00'));
    }

    public function test_a_paid_bill_cannot_be_voided_and_an_unpaid_one_can_with_a_reason(): void
    {
        $bill = $this->bill(2000);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('business.utilities.void', $bill), ['void_reason' => 'changed my mind'])
            ->assertSessionHasErrors('void_reason');

        $this->assertSame(UtilityBill::STATUS_PAID, $bill->fresh()->status);

        // An unpaid one is voidable, and the reason is required.
        $other = $this->bill(2000, '2027-03', 'WASA');

        $this->actingAs($this->admin)
            ->post(route('business.utilities.void', $other), ['void_reason' => '   '])
            ->assertSessionHasErrors('void_reason');

        $this->actingAs($this->admin)
            ->post(route('business.utilities.void', $other), ['void_reason' => 'Duplicate of the January bill'])
            ->assertSessionHasNoErrors();

        $other->refresh();
        $this->assertSame(UtilityBill::STATUS_VOID, $other->status);
        $this->assertSame('Duplicate of the January bill', $other->void_reason);

        // A voided row keeps the sequence explainable, and the month can be
        // refiled because the voided row no longer blocks it.
        $refiled = $this->desk()->record([
            'provider_id' => $other->provider_id,
            'period_month' => '2027-03',
            'issue_date' => '2027-03-05',
            'due_date' => '2027-03-25',
            'amount' => 2100,
        ], $this->admin);

        $this->assertSame('UB-000003', $refiled->bill_no);
        $this->assertSame(UtilityBill::STATUS_RECORDED, $refiled->status);
    }

    public function test_a_bill_that_has_been_paid_cannot_be_rewritten(): void
    {
        $bill = $this->bill(2000);

        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->put(route('business.utilities.update', $bill), [
                'provider_id' => $bill->provider_id,
                'period_month' => '2027-02',
                'issue_date' => '2027-02-05',
                'due_date' => '2027-02-28',
                'amount' => 9999,
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame('2000.00', (string) $bill->fresh()->amount);
    }

    public function test_amending_an_unpaid_bill_keeps_the_history(): void
    {
        $bill = $this->bill(4800);

        $this->actingAs($this->admin)
            ->put(route('business.utilities.update', $bill), [
                'provider_id' => $bill->provider_id,
                'period_month' => '2027-02',
                'issue_date' => '2027-02-05',
                'due_date' => '2027-03-05',
                'amount' => 5100,
                'consumption' => 340,
                'narration' => 'Re-read meter',
            ])
            ->assertSessionHasNoErrors();

        $bill->refresh();

        $this->assertSame('5100.00', (string) $bill->amount);
        $this->assertSame('2027-03-05', $bill->due_date?->toDateString());
        $this->assertSame('340.0000', (string) $bill->consumption);

        $audit = DB::table('audit_events')->where('action', 'business.utility_bill_amended')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('4800', (string) $audit->before);
        $this->assertStringContainsString('5100', (string) $audit->after);
    }

    /* ------------------------------------------------------------------ lenses */

    public function test_the_renewals_lens_separates_what_is_late_from_what_is_coming(): void
    {
        $this->bill(1200, '2027-01', 'DESCO', '2027-01-28');
        $this->bill(2400, '2027-02', 'WASA', '2027-02-25');
        $this->bill(3600, '2027-02', 'TITAS', '2027-03-20');

        $lens = $this->desk()->reminders();

        $this->assertSame(1, $lens['overdue']->count());
        $this->assertSame(1, $lens['due']->count());
        $this->assertSame('WASA', $lens['due']->first()->provider->code);
        $this->assertSame(6, $lens['next']->count(), 'Every active provider is on the standing-charge list.');

        $page = $this->actingAs($this->admin)->get(route('business.utilities.renewals'));
        $page->assertOk();
        $page->assertSee('Past their date');
        $page->assertSee('Coming inside 14 days');
        $page->assertSee('The standing charges');
    }

    public function test_the_months_summary_adds_up_and_is_read_per_family(): void
    {
        $this->bill(4800, '2027-02', 'DESCO');
        $this->bill(1200, '2027-02', 'WASA');
        $this->bill(900, '2027-01', 'TITAS');

        $paid = $this->bill(2000, '2027-02', 'RENT');
        $this->actingAs($this->admin)
            ->post(route('business.utilities.pay', $paid), ['money_account_id' => $this->bank()->id, 'paid_on' => '2027-02-18']);

        $summary = $this->desk()->summary('2027-02');

        $this->assertSame('2027-02', $summary['period']);
        $this->assertSame(3, $summary['totals']['bills'], 'January\'s bill is not in February.');
        $this->assertSame(8000.0, $summary['totals']['billed']);      // 4800 + 1200 + 2000
        $this->assertEquals(6000.0, $summary['totals']['outstanding']); // everything but the rent
        $this->assertSame(2000.0, $summary['totals']['paid']);
        $this->assertSame(1, $summary['families']['electricity']['bills']);
        $this->assertSame(4800.0, $summary['families']['electricity']['billed']);
        $this->assertSame(2000.0, $summary['families']['rent']['paid']);
    }

    /* ------------------------------------------------------------- the watch */

    public function test_the_morning_watch_tells_the_desk_once_per_day(): void
    {
        $this->bill(1200, '2027-01', 'DESCO', '2027-01-28');
        $this->bill(2400, '2027-02', 'WASA', '2027-02-25');

        $this->artisan('erp:business:utility-reminders')->assertSuccessful();

        $this->assertSame(2, Notification::query()
            ->whereIn('event_type', ['business.utility.overdue', 'business.utility.due'])
            ->count());

        $this->assertSame(1, Notification::query()
            ->where('event_type', 'business.utility.overdue')
            ->count());

        // Running it again the same day adds nothing: one digest per state per day.
        $this->artisan('erp:business:utility-reminders')->assertSuccessful();
        $this->assertSame(2, Notification::query()
            ->whereIn('event_type', ['business.utility.overdue', 'business.utility.due'])
            ->count());

        // And with nothing to say it says nothing.
        UtilityBill::query()->update(['status' => UtilityBill::STATUS_PAID, 'paid_on' => '2027-02-20']);
        $this->artisan('erp:business:utility-reminders')->assertSuccessful();
        $this->assertSame(2, Notification::query()
            ->whereIn('event_type', ['business.utility.overdue', 'business.utility.due'])
            ->count());
    }

    /* --------------------------------------------------------- the doors */

    public function test_reading_needs_the_view_key_and_paying_needs_the_manage_key(): void
    {
        $bill = $this->bill(4800);

        $reader = $this->userWith(['business.utilities.view']);

        $this->actingAs($reader)->get(route('business.utilities.index'))->assertOk();
        $this->actingAs($reader)->get(route('business.utilities.show', $bill))->assertOk();
        $this->actingAs($reader)->get(route('business.utilities.renewals'))->assertOk();

        // Reading is not paying, filing or editing.
        $this->actingAs($reader)->get(route('business.utilities.create'))->assertForbidden();
        $this->actingAs($reader)->get(route('business.utilities.edit', $bill))->assertForbidden();
        $this->actingAs($reader)->post(route('business.utilities.pay', $bill), ['money_account_id' => $this->bank()->id])->assertForbidden();
        $this->actingAs($reader)->get(route('business.utilities.providers.create'))->assertForbidden();

        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'utility_bill')->count());

        // Somebody with neither key does not get through the door at all.
        $stranger = $this->userWith(['dashboard.view']);
        $this->actingAs($stranger)->get(route('business.utilities.index'))->assertForbidden();
    }

    public function test_a_bill_from_another_company_is_not_found(): void
    {
        $bill = $this->bill(4800);

        // A second company, written by hand because `singleton` is not
        // mass-assignable: the column is the database-level guard that makes this
        // a one-company instance.
        $elsewhere = new Company(['name' => 'Another Company', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        $outsider = User::query()->create([
            'company_id' => $elsewhere->id,
            'name' => 'Outsider',
            'email' => 'outsider@elsewhere.test',
            'password' => self::ADMIN_PASSWORD,
            'status' => 'active',
            'branch_scope' => 'all',
        ]);
        $this->grant($outsider, ['business.utilities.view', 'business.utilities.manage']);
        $this->bindTenantContext($outsider);

        $this->actingAs($outsider)->get(route('business.utilities.show', $bill))->assertNotFound();
        $this->actingAs($outsider)->post(route('business.utilities.pay', $bill), [])->assertNotFound();

        // And the register they see is their own, empty one.
        $this->actingAs($outsider)->get(route('business.utilities.index'))->assertOk();
    }

    public function test_the_catalogue_points_the_six_leaves_at_real_pages(): void
    {
        app(\App\Domain\Foundation\Services\CatalogImporter::class)->sync();

        $group = MenuItem::query()->where('label', 'Utility Bills')->firstOrFail();
        $leaves = $group->children()->get();

        $this->assertCount(6, $leaves, 'The Utility Bills group should have six leaves.');

        $expected = [
            'Electricity (DESCO/DPDC)' => '/app/utility-bills?family=electricity',
            'Water (WASA)' => '/app/utility-bills?family=water',
            'Gas (TITAS)' => '/app/utility-bills?family=gas',
            'Internet & Mobile' => '/app/utility-bills?family=internet',
            'Rent Payments' => '/app/utility-bills?family=rent',
            'Renewal Reminders' => '/app/utility-bills/renewals',
        ];

        foreach ($leaves as $leaf) {
            $this->assertArrayHasKey($leaf->label, $expected, "Unexpected leaf: {$leaf->label}");
            $this->assertSame($expected[$leaf->label], $leaf->route, "{$leaf->label} points at the wrong page.");
            $this->assertTrue($leaf->is_active, "{$leaf->label} should be an active menu row.");

            $this->actingAs($this->admin)->get($leaf->route)->assertOk();
        }
    }

    public function test_the_five_shelves_are_the_same_desk_filtered(): void
    {
        $this->bill(4800, '2027-02', 'DESCO');
        $this->bill(900, '2027-02', 'RENT');

        $electric = $this->actingAs($this->admin)->get(route('business.utilities.index', ['family' => 'electricity']));
        $electric->assertOk();
        $electric->assertSee('DESCO');
        $electric->assertDontSee('Landlord — office rent');

        $rent = $this->actingAs($this->admin)->get(route('business.utilities.index', ['family' => 'rent']));
        $rent->assertOk();
        $rent->assertSee('Landlord — office rent');
        $rent->assertDontSee('DESCO');

        // A family nobody named is not a shelf — it is the whole desk.
        $unknown = $this->actingAs($this->admin)->get(route('business.utilities.index', ['family' => 'nonsense']));
        $unknown->assertOk();
        $unknown->assertSee('DESCO');
    }
}
