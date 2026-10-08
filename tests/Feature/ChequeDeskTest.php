<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\Cheque;
use App\Domain\Documents\Document;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-13 / §08-14 — the cheque register and the cheque's printed record.
 *
 * What this pins, in the order the complaints would arrive:
 *  · a cheque is a promise, not a posting: writing one in, depositing it and
 *    presenting it move nothing in the ledger, and the entry appears the day a
 *    bank pays it — once, and never twice;
 *  · a post-dated cheque is a real instrument with a real trap: the register
 *    takes it happily and then refuses to bank it before the date written on it,
 *    because an early deposit comes back and the return is a mark for nothing;
 *  · a cheque that failed before clearing has nothing to reverse — the ledger
 *    never heard about it — and one that failed after clearing must reverse,
 *    because the money really did come back out;
 *  · clearing through anything but a cash, bank or wallet account is refused, and
 *    "settling" a cheque against another account money sits in is refused too:
 *    that is a transfer, and it has its own desk;
 *  · the same slip cannot be written into one account twice — that is what the
 *    unique index and the double-entry check are both for;
 *  · the six catalogue leaves are views of one register, each one active and
 *    filtered, and every state a cheque can be in is named and badged;
 *  · the printed record carries the amount in words in both languages, is filed
 *    in the document register exactly as printed, and a cheque the company
 *    received is not ours to print at all.
 */
class ChequeDeskTest extends TestCase
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

    /** How many entries the cheque register has posted — the number that must stay still. */
    /**
     * Every ledger entry the cheque desk has written.
     *
     * A reversal is written by the accounting engine as a `journal_entry`
     * pointing back at the original — the original is marked reversed rather
     * than edited — so counting only `source_type = cheque` would read a
     * bounced cheque as “nothing happened after the clearing”.
     */
    protected function posted(): int
    {
        return JournalEntry::query()
            ->where(fn ($query) => $query
                ->where('source_type', 'cheque')
                ->orWhereIn('reversal_of_id', JournalEntry::query()->where('source_type', 'cheque')->select('id')))
            ->count();
    }

    /** @return array<string, mixed> */
    protected function payload(Account $money, array $overrides = []): array
    {
        return array_merge([
            'direction' => Cheque::DIRECTION_RECEIVED,
            'cheque_no' => '004512',
            'cheque_date' => now()->toDateString(),
            'bank_name' => 'Islami Bank, Motijheel',
            'account_id' => $money->id,
            'counter_account_id' => $this->account('4200')->id,
            'party_name' => 'Rahman Traders',
            'amount' => '26000.00',
            'reference' => 'SO-2026-00091',
            'narration' => 'Settlement of the March balance',
        ], $overrides);
    }

    protected function record(Account $money, array $overrides = []): Cheque
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.cheques.store'), $this->payload($money, $overrides))
            ->assertSessionHasNoErrors();

        return Cheque::query()->orderByDesc('id')->firstOrFail();
    }

    protected function transition(Cheque $cheque, array $payload): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('cash-bank.cheques.transition', ['cheque' => $cheque->id]), $payload);
    }

    // ------------------------------------------------------------- the desk

    public function test_the_register_is_a_real_menu_entry_and_writing_a_cheque_in_posts_nothing(): void
    {
        $bank = $this->account('1120');

        // Six catalogue leaves, each a filtered view of the one register — and the
        // menu only carries them because the routes behind them exist.
        $received = MenuItem::query()->where('label', 'Received Cheques')->firstOrFail();
        $this->assertSame('active', $received->status);
        $this->assertSame('/app/cash-bank/cheques?direction=received', $received->route);
        $this->assertSame('cheques.view', $received->permission?->key);

        $print = MenuItem::query()->where('label', 'Cheque Print')->firstOrFail();
        $this->assertSame('active', $print->status);
        $this->assertSame('/app/cash-bank/cheques?direction=issued', $print->route);
        $this->assertSame('cheques.print', $print->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques'))
            ->assertOk()
            ->assertSee('The cheque register');

        // A user holding no key at all does not read the book of promises.
        $this->actingAs($this->makeUser())->get(route('cash-bank.cheques'))->assertForbidden();

        $cheque = $this->record($bank);

        $this->assertSame(Cheque::STATUS_RECEIVED, $cheque->status);
        $this->assertNull($cheque->journal_entry_id);
        $this->assertSame(0, $this->posted());

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques'))
            ->assertOk()
            ->assertSee('004512')
            ->assertSee('Rahman Traders');
    }

    public function test_reading_writing_and_clearing_a_cheque_are_three_different_keys(): void
    {
        $bank = $this->account('1120');
        $cheque = $this->record($bank, ['cheque_no' => '300001']);

        // A clerk who may read the register but not write in it.
        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith(['portal.erp.access', 'cheques.view'])->id);

        $this->actingAs($reader)->get(route('cash-bank.cheques'))->assertOk();
        $this->actingAs($reader)
            ->post(route('cash-bank.cheques.store'), $this->payload($bank, ['cheque_no' => '300002']))
            ->assertForbidden();
        $this->actingAs($reader)
            ->post(route('cash-bank.cheques.transition', ['cheque' => $cheque->id]), ['action' => 'deposit'])
            ->assertForbidden();

        // A clerk who may write cheques in but may not decide the bank paid one:
        // that is the moment the ledger moves, and it is somebody else's key.
        $writer = $this->makeUser();
        $writer->roles()->attach($this->roleWith(['portal.erp.access', 'cheques.view', 'cheques.manage'])->id);

        $this->actingAs($writer)->get(route('cash-bank.cheques'))->assertOk();
        $this->actingAs($writer)
            ->post(route('cash-bank.cheques.store'), $this->payload($bank, ['cheque_no' => '300003']))
            ->assertSessionHasNoErrors();

        $this->actingAs($writer)
            ->post(route('cash-bank.cheques.transition', ['cheque' => $cheque->id]), [
                'action' => 'deposit',
                'on' => now()->toDateString(),
            ])
            ->assertForbidden();

        // Neither of them moved anything.
        $this->assertSame(Cheque::STATUS_RECEIVED, $cheque->refresh()->status);
        $this->assertSame(2, Cheque::query()->count());
        $this->assertSame(0, $this->posted());
    }

    public function test_a_post_dated_cheque_is_taken_in_and_then_refused_before_its_date(): void
    {
        $bank = $this->account('1120');
        $future = now()->addDays(10)->toDateString();

        $cheque = $this->record($bank, ['cheque_no' => '771001', 'cheque_date' => $future, 'amount' => '1500.00']);

        $this->assertTrue($cheque->isPostDated());

        $register = $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['state' => 'post_dated']))
            ->assertOk()
            ->assertSee('771001');
        $this->assertNotNull($register);

        // The date written on it is a date, not a suggestion.
        $this->transition($cheque, ['action' => 'deposit', 'on' => now()->toDateString()])
            ->assertSessionHasErrors('cheque');
        $this->assertStringContainsString('post-dated', $this->allFlashedErrors());

        $cheque->refresh();
        $this->assertSame(Cheque::STATUS_RECEIVED, $cheque->status);
        $this->assertNull($cheque->deposited_on);

        // On its own day it is an ordinary cheque again.
        $this->transition($cheque, ['action' => 'deposit', 'on' => $future])->assertSessionHasNoErrors();
        $this->assertSame(Cheque::STATUS_DEPOSITED, $cheque->refresh()->status);
    }

    public function test_depositing_is_not_paying_and_clearing_posts_exactly_once(): void
    {
        $bank = $this->account('1120');
        $revenue = $this->account('4200');

        $cheque = $this->record($bank);

        // A cheque the bank has never seen cannot have cleared.
        $this->transition($cheque, ['action' => 'clear', 'on' => now()->toDateString()])
            ->assertSessionHasErrors('cheque');
        $this->assertSame(Cheque::STATUS_RECEIVED, $cheque->refresh()->status);

        $this->transition($cheque, ['action' => 'deposit', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

        $cheque->refresh();
        $this->assertSame(Cheque::STATUS_DEPOSITED, $cheque->status);
        $this->assertNotNull($cheque->deposited_on);
        // Handed in is still not paid.
        $this->assertNull($cheque->journal_entry_id);
        $this->assertSame(0, $this->posted());

        $this->transition($cheque, ['action' => 'clear', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

        $cheque->refresh();
        $this->assertSame(Cheque::STATUS_CLEARED, $cheque->status);
        $this->assertNotNull($cheque->cleared_on);
        $this->assertSame(1, $this->posted());

        $entry = $cheque->journalEntry()->firstOrFail()->load('lines');
        $this->assertSame(2, $entry->lines->count());
        $this->assertSame(0, bccomp((string) $entry->total_debit, '26000.0000', 4));
        $this->assertSame(0, bccomp((string) $entry->total_credit, '26000.0000', 4));

        $debit = $entry->lines->first(fn ($line) => $line->isDebit());
        $credit = $entry->lines->first(fn ($line) => $line->isCredit());

        // Money arrived in the bank account, against what the cheque settled.
        $this->assertSame($bank->id, (int) $debit->account_id);
        $this->assertSame($revenue->id, (int) $credit->account_id);

        // And it is posted once. Clearing it again is refused, not repeated.
        $this->transition($cheque, ['action' => 'clear', 'on' => now()->toDateString()])
            ->assertSessionHasErrors('cheque');
        $this->assertSame(1, $this->posted());
    }

    public function test_a_bounce_before_clearing_reverses_nothing_and_one_after_clearing_reverses_the_entry(): void
    {
        $bank = $this->account('1120');

        // Bounced in the drawer: the ledger never heard about this cheque, so
        // there is nothing to reverse — and inventing a reversal would put money
        // back that never arrived.
        $inHand = $this->record($bank, ['cheque_no' => '991001', 'amount' => '1500.00']);
        $this->transition($inHand, ['action' => 'deposit', 'on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->transition($inHand, ['action' => 'fail', 'reason' => 'Insufficient funds', 'on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $inHand->refresh();
        $this->assertSame(Cheque::STATUS_BOUNCED, $inHand->status);
        $this->assertSame('Insufficient funds', $inHand->bounced_reason);
        $this->assertNull($inHand->reversal_entry_id);
        $this->assertSame(0, $this->posted());

        // Cleared and then returned: the money came back out, and the original
        // entry stays where it is — the reversal answers it.
        $cleared = $this->record($bank, ['cheque_no' => '991002', 'amount' => '5000.00']);
        $this->transition($cleared, ['action' => 'deposit', 'on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->transition($cleared, ['action' => 'clear', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

        $cleared->refresh();
        $original = $cleared->journal_entry_id;
        $this->assertNotNull($original);
        $this->assertSame(1, $this->posted());

        $this->transition($cleared, ['action' => 'fail', 'reason' => 'Signature mismatch', 'on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $cleared->refresh();
        $this->assertSame(Cheque::STATUS_BOUNCED, $cleared->status);
        $this->assertSame($original, $cleared->journal_entry_id);
        $this->assertNotNull($cleared->reversal_entry_id);
        $this->assertSame(2, $this->posted());

        $reversal = JournalEntry::query()->findOrFail($cleared->reversal_entry_id)->load('lines');
        $this->assertSame(2, $reversal->lines->count());
        $this->assertSame(0, bccomp((string) $reversal->total_debit, '5000.0000', 4));

        // A promise fails once: re-opening a failed cheque is how a drawer
        // disagrees with itself.
        $this->transition($cleared, ['action' => 'fail', 'reason' => 'Again?', 'on' => now()->toDateString()])
            ->assertSessionHasErrors('cheque');
        $this->assertSame(2, $this->posted());

        // The desk kept the story of both attempts.
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_bank.cheque_cleared')->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'cash_bank.cheque_failed')->count());
    }

    public function test_an_issued_cheque_is_presented_then_cleared_and_its_record_prints(): void
    {
        $bank = $this->account('1120');
        $utilities = $this->account('5230');

        $cheque = $this->record($bank, [
            'direction' => Cheque::DIRECTION_ISSUED,
            'cheque_no' => '334455',
            'cheque_date' => now()->toDateString(),
            'bank_name' => 'Dutch-Bangla Bank, Dhanmondi',
            'counter_account_id' => $utilities->id,
            'party_name' => 'Dhaka WASA',
            'amount' => '12640.00',
            'narration' => 'Water bill for August',
        ]);

        $this->assertSame(Cheque::STATUS_ISSUED, $cheque->status);

        // Ours cannot clear without having been presented.
        $this->transition($cheque, ['action' => 'clear', 'on' => now()->toDateString()])
            ->assertSessionHasErrors('cheque');
        $this->assertSame(0, $this->posted());

        $this->transition($cheque, ['action' => 'present', 'on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(Cheque::STATUS_PRESENTED, $cheque->refresh()->status);

        $this->transition($cheque, ['action' => 'clear', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

        $cheque->refresh();
        $this->assertSame(Cheque::STATUS_CLEARED, $cheque->status);

        // Ours: the bank account is credited and the expense is debited.
        $entry = $cheque->journalEntry()->firstOrFail()->load('lines');
        $this->assertSame($bank->id, (int) $entry->lines->first(fn ($line) => $line->isCredit())->account_id);
        $this->assertSame($utilities->id, (int) $entry->lines->first(fn ($line) => $line->isDebit())->account_id);

        // §08-14 — the printed record, in both languages, filed as printed.
        Storage::disk('local')->deleteDirectory('generated');

        $response = $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques.print', ['cheque' => $cheque->id]))
            ->assertOk()
            ->assertSee('Cheque record')
            ->assertSee('334455')
            ->assertSee('Dhaka WASA')
            ->assertSee('Taka twelve thousand six hundred and forty only')
            ->assertSee('১২,৬৪০.০০');

        $html = (string) $response->getContent();

        $filed = Document::query()
            ->where('owner_id', $cheque->id)
            ->where('owner_type', Cheque::class)
            ->firstOrFail();

        $this->assertSame(hash('sha256', $html), $filed->checksum);
        $this->assertTrue(Storage::disk('local')->exists($filed->path));
        $this->assertSame('cheque', $filed->documentType?->code);

        // Every print is attributable: who, from where, how many.
        $this->assertSame(1, PrintHistory::query()->where('printable_id', $cheque->id)->count());

        // A cheque the company received is the customer's slip, not ours to print.
        $received = $this->record($bank, ['cheque_no' => '556677', 'amount' => '800.00']);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques.print', ['cheque' => $received->id]))
            ->assertForbidden();
    }

    public function test_a_cheque_clears_through_a_money_account_only_and_never_settles_another_one(): void
    {
        $bank = $this->account('1120');
        $cash = $this->account('1110');
        $revenue = $this->account('4200');

        // An account money does not sit in cannot be what a cheque clears through.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.cheques.store'), $this->payload($revenue, ['cheque_no' => 'A10001']))
            ->assertSessionHasErrors('cheque');
        $this->assertStringContainsString('not a cash, bank or wallet account', $this->allFlashedErrors());

        // And a cheque cannot "settle" between two of the company's own accounts:
        // that is a transfer, which is a different desk with different rules.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.cheques.store'), $this->payload($bank, [
                'cheque_no' => 'A10002',
                'counter_account_id' => $cash->id,
            ]))
            ->assertSessionHasErrors('cheque');
        $this->assertStringContainsString('transfer', $this->allFlashedErrors());

        $this->assertSame(0, Cheque::query()->count());

        // The same slip, the same account, the same direction is one cheque.
        $this->record($bank, ['cheque_no' => 'A10003']);
        $this->actingAs($this->admin)
            ->post(route('cash-bank.cheques.store'), $this->payload($bank, ['cheque_no' => 'A10003']))
            ->assertSessionHasErrors('cheque');
        $this->assertSame(1, Cheque::query()->count());
    }

    public function test_the_register_filters_the_way_the_catalogue_leaves_ask_it_to(): void
    {
        $bank = $this->account('1120');

        $inHand = $this->record($bank, ['cheque_no' => '200001', 'amount' => '1000.00']);
        $postDated = $this->record($bank, [
            'cheque_no' => '200002',
            'amount' => '2000.00',
            'cheque_date' => now()->addDays(5)->toDateString(),
        ]);
        $issued = $this->record($bank, [
            'direction' => Cheque::DIRECTION_ISSUED,
            'cheque_no' => '200003',
            'amount' => '3000.00',
            'counter_account_id' => $this->account('5230')->id,
            'party_name' => 'Landlord',
        ]);
        $bounced = $this->record($bank, ['cheque_no' => '200004', 'amount' => '4000.00']);

        $this->transition($bounced, ['action' => 'fail', 'reason' => 'Account closed', 'on' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $cleared = $this->record($bank, ['cheque_no' => '200005', 'amount' => '5000.00']);
        $this->transition($cleared, ['action' => 'deposit', 'on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->transition($cleared, ['action' => 'clear', 'on' => now()->toDateString()])->assertSessionHasNoErrors();

        // Direction: the two halves of the register are two halves of a business.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['direction' => 'issued']))
            ->assertOk()
            ->assertSee('200003')
            ->assertDontSee('200001');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['direction' => 'received']))
            ->assertOk()
            ->assertSee('200001')
            ->assertDontSee('200003');

        // State: what the register is read for.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['state' => 'post_dated']))
            ->assertOk()
            ->assertSee('200002')
            ->assertDontSee('200001');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['state' => 'failed']))
            ->assertOk()
            ->assertSee('200004')
            ->assertDontSee('200005');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['state' => 'cleared']))
            ->assertOk()
            ->assertSee('200005')
            ->assertDontSee('200001');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['state' => 'outstanding']))
            ->assertOk()
            ->assertSee('200002')
            ->assertDontSee('200005');

        // An account: what is still hanging over it, which is what a bank book
        // needs before anybody signs it off.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['account' => $bank->id]))
            ->assertOk()
            ->assertSee('Still hanging over')
            ->assertSee($bank->name);

        // And a search: the number on the slip is how a cheque is found again.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques', ['q' => '200003']))
            ->assertOk()
            ->assertSee('200003')
            ->assertDontSee('200001');

        $this->assertSame((int) $bank->id, (int) $inHand->account_id);
        $this->assertTrue($postDated->isPostDated());
        $this->assertSame(Cheque::STATUS_BOUNCED, $bounced->refresh()->status);
        $this->assertSame(Cheque::STATUS_CLEARED, $cleared->refresh()->status);
    }

    public function test_every_state_a_cheque_can_be_in_is_named_and_badged(): void
    {
        $bank = $this->account('1120');

        // A cheque we wrote starts in `issued`, and leaving that out of the
        // vocabulary would make our own cheques unfilterable on our own register.
        $this->assertArrayHasKey(Cheque::STATUS_ISSUED, Cheque::STATUSES);
        $this->assertArrayHasKey(Cheque::STATUS_RECEIVED, Cheque::STATUSES);

        $tones = [];

        foreach (array_keys(Cheque::STATUSES) as $index => $status) {
            $cheque = Cheque::query()->create([
                'company_id' => $this->admin->company_id,
                'direction' => $status === Cheque::STATUS_ISSUED || $status === Cheque::STATUS_PRESENTED
                    ? Cheque::DIRECTION_ISSUED
                    : Cheque::DIRECTION_RECEIVED,
                'cheque_no' => 'V'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                'cheque_date' => now()->toDateString(),
                'bank_name' => 'Islami Bank',
                'account_id' => $bank->id,
                'counter_account_id' => $this->account('4200')->id,
                'party_name' => 'Vocabulary check',
                'amount' => '100.00',
                'status' => $status,
            ]);

            $this->assertNotSame($status, $cheque->statusLabel(), "every state has a label of its own ({$status})");
            $tones[] = $cheque->statusTone();
        }

        // Each state wears a badge, and a cheque in the drawer is not green: the
        // positive word `received` belongs to a purchase that arrived.
        $this->assertSame('outstanding', Cheque::query()->where('status', Cheque::STATUS_RECEIVED)->firstOrFail()->statusTone());
        $this->assertSame('failed', Cheque::query()->where('status', Cheque::STATUS_BOUNCED)->firstOrFail()->statusTone());
        $this->assertSame('cleared', Cheque::query()->where('status', Cheque::STATUS_CLEARED)->firstOrFail()->statusTone());
        $this->assertNotContains('received', $tones);
        $this->assertCount(count(Cheque::STATUSES), $tones);
    }

    public function test_another_company_cheque_is_not_found_and_not_movable(): void
    {
        $bank = $this->account('1120');

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = Cheque::query()->create([
            'company_id' => $otherCompany,
            'direction' => Cheque::DIRECTION_RECEIVED,
            'cheque_no' => '900001',
            'cheque_date' => now()->toDateString(),
            'bank_name' => 'Another bank',
            'account_id' => $bank->id,
            'counter_account_id' => $this->account('4200')->id,
            'party_name' => 'Not ours',
            'amount' => '999.00',
            'status' => Cheque::STATUS_RECEIVED,
        ]);

        // Another company's cheque is not refused, it does not exist as far as
        // this desk is concerned.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques.show', ['cheque' => $foreign->id]))
            ->assertNotFound();

        $this->transition($foreign, ['action' => 'deposit', 'on' => now()->toDateString()])->assertNotFound();
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cheques.print', ['cheque' => $foreign->id]))
            ->assertNotFound();

        $this->assertSame(Cheque::STATUS_RECEIVED, $foreign->refresh()->status);
        $this->assertSame(0, $this->posted());
    }
}
