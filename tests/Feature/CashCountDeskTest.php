<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\CashCount;
use App\Domain\CashBank\Services\CashCountService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\CashBank\Services\MoneyMovementService;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Role;
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
 * §08-05 — the drawer counted by a person and answered for by the books.
 *
 * What this pins, in the order the complaints would arrive:
 *  · counting a drawer posts the *difference* and nothing else — the sales that
 *    made the tin short are not restated, the cash account is corrected;
 *  · a count that matches the books posts nothing at all, because there is
 *    nothing to correct, and the sheet says so;
 *  · a count that does not match has to say why, and a breakdown of notes that
 *    does not add up to the counted total is refused rather than silently fixed;
 *  · above the company's tolerance the difference waits — nothing in the ledger —
 *    and the person who held the tin cannot be the person who approves it;
 *  · a refused count leaves the books exactly as they were, with a reason on the
 *    record, and a rejection without one is refused;
 *  · a drawer cannot be counted twice while one of its differences is pending,
 *    because that would ask somebody to approve the same gap twice;
 *  · only a cash drawer can be counted: a bank or wallet is proved against a
 *    statement, not against a pile of notes;
 *  · another company's drawer cannot be counted from here.
 */
class CashCountDeskTest extends TestCase
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

    /** Put money into the drawer so it has something to be counted against. */
    protected function fill(float $amount, string $accountCode = '1110'): void
    {
        app(MoneyMovementService::class)->receive([
            'money_account_id' => $this->account($accountCode)->id,
            'counter_account_id' => $this->account('4200')->id,
            'amount' => $amount,
            'received_on' => now()->toDateString(),
            'payer' => 'Counter sales',
            'narration' => 'Cash takings banked into the drawer',
        ], $this->admin->id);
    }

    protected function balance(string $accountCode = '1110'): string
    {
        return app(MoneyAccountService::class)->balanceOf($this->account($accountCode));
    }

    protected function tolerance(float $amount): void
    {
        app(SettingService::class)->set(
            CashCountService::SETTING_GROUP,
            CashCountService::SETTING_KEY,
            $amount,
            null,
            $this->admin,
        );
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->account('1110')->id,
            'branch_id' => $this->defaultBranch()->id,
            'counted_on' => now()->toDateString(),
            'counted_amount' => '9700.00',
            'difference_reason' => 'Change given wrong at the counter, vouchers filed late',
        ], $overrides);
    }

    protected function count(array $overrides = [], ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.cash-counts.store'), $this->payload($overrides));
    }

    /** The counts the desk has posted — the number that must stay still. */
    protected function posted(): int
    {
        return JournalEntry::query()->where('source_type', 'cash_count')->count();
    }

    // ------------------------------------------------------- the desk is real

    public function test_the_cash_count_leaf_is_mapped_and_counting_is_its_own_key(): void
    {
        $leaf = MenuItem::query()->where('route', '/app/cash-bank/cash-counts')->first();

        $this->assertNotNull($leaf, 'the catalogue leaf for the cash count was not mapped');
        $this->assertSame('active', $leaf->status);
        $this->assertSame('cash.counts', $leaf->permission?->key);

        // The desk is readable with the module floor; counting is not.
        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith(['cash.view']));

        $this->actingAs($reader)->get(route('cash-bank.cash-counts'))->assertOk();
        $this->actingAs($reader)->post(route('cash-bank.cash-counts.store'), $this->payload())
            ->assertForbidden();

        // …and the storekeeper counts the till without being able to sign off
        // what is missing from it.
        $storekeeper = Role::query()
            ->where('company_id', $this->admin->company_id)
            ->where('slug', 'storekeeper')
            ->firstOrFail();

        $keys = $storekeeper->permissions()->pluck('key')->all();

        $this->assertContains('cash.counts', $keys);
        $this->assertNotContains('cash.counts.approve', $keys);
    }

    // -------------------------------------------------------- posting the gap

    public function test_a_counted_drawer_posts_only_the_difference_and_nothing_else(): void
    {
        $this->fill(10000);
        $before = JournalEntry::query()->count();

        $this->count()
            ->assertRedirect();

        $count = CashCount::query()->sole();

        $this->assertTrue($count->isPosted());
        $this->assertSame('10000.0000', $count->expected_amount);
        $this->assertSame('9700.0000', $count->counted_amount);
        $this->assertSame('-300.0000', $count->variance);
        $this->assertTrue($count->isShort());
        $this->assertNotNull($count->journal_entry_id);

        // Exactly one new entry: Dr Cash Over & Short, Cr the drawer.
        $this->assertSame($before + 1, JournalEntry::query()->count());
        $this->assertSame(1, $this->posted());

        $entry = $count->journalEntry;
        $short = $entry->lines->firstWhere('dc', 'debit');
        $cash = $entry->lines->firstWhere('dc', 'credit');

        $this->assertSame($this->account('5250')->id, $short->account_id);
        $this->assertSame($this->account('1110')->id, $cash->account_id);
        $this->assertEqualsWithDelta(300.0, (float) $entry->lines->sum('amount') / 2, 0.0001);

        // The drawer now reads as counted, and the ledger agrees with the tin.
        $this->assertSame('9700.0000', $this->balance());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_bank.cash_count_posted')->count());
    }

    public function test_an_exact_count_posts_nothing_at_all(): void
    {
        $this->fill(5000);
        $before = JournalEntry::query()->count();

        $this->count([
            'counted_amount' => '5000.00',
            'difference_reason' => null,
        ])->assertRedirect();

        $count = CashCount::query()->sole();

        $this->assertTrue($count->isPosted());
        $this->assertTrue($count->balanced());
        $this->assertNull($count->journal_entry_id);
        $this->assertSame($before, JournalEntry::query()->count());
        $this->assertSame(0, $this->posted());

        // …and the sheet says so rather than showing an empty ledger link.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.cash-counts.show', $count))
            ->assertOk()
            ->assertSee('nothing to correct');
    }

    public function test_an_overage_is_income_and_a_shortage_is_an_expense(): void
    {
        $this->fill(4000);

        $this->count(['counted_amount' => '4150.00', 'difference_reason' => 'A customer paid too much and left']);

        $count = CashCount::query()->sole();
        $over = $count->journalEntry->lines->firstWhere('dc', 'credit');

        $this->assertTrue($count->isOver());
        $this->assertSame($this->account('4200')->id, $over->account_id);
        $this->assertSame($this->account('1110')->id, $count->journalEntry->lines->firstWhere('dc', 'debit')->account_id);
        $this->assertSame('4150.0000', $this->balance());
    }

    // ------------------------------------------------------------ the checks

    public function test_a_count_that_does_not_match_the_books_has_to_say_why(): void
    {
        $this->fill(1000);

        $this->count(['counted_amount' => '900.00', 'difference_reason' => null])
            ->assertSessionHasErrors('cash_count');

        $this->assertSame(0, CashCount::query()->count());
        $this->assertSame(0, $this->posted());
        $this->assertSame('1000.0000', $this->balance());

        // A count through an exact figure needs no excuse, because there is no
        // difference to excuse.
        $this->count(['counted_amount' => '1000.00', 'difference_reason' => null])
            ->assertSessionHasNoErrors();
    }

    public function test_the_denominations_have_to_add_up_to_what_was_in_the_tin(): void
    {
        $this->fill(5000);

        $matched = [
            ['kind' => 'note', 'face_value' => '1000', 'quantity' => 4],
            ['kind' => 'note', 'face_value' => '500', 'quantity' => 2],
        ];

        $this->count([
            'counted_amount' => '5000.00',
            'difference_reason' => null,
            'denominations' => $matched,
        ])->assertSessionHasNoErrors();

        $count = CashCount::query()->sole();

        $this->assertSame(2, $count->lines()->count());
        $this->assertSame('4000.0000', (string) $count->lines()->orderBy('position')->first()->amount);
        $this->assertSame(4, $count->lines()->orderBy('position')->first()->quantity);

        // A breakdown that adds up to something else is refused, not tidied up.
        $this->count([
            'counted_amount' => '5000.00',
            'difference_reason' => null,
            'denominations' => [
                ['kind' => 'note', 'face_value' => '1000', 'quantity' => 4],
            ],
        ])->assertSessionHasErrors('cash_count');

        $this->assertSame(1, CashCount::query()->count());
    }

    // ---------------------------------------------------------- the tolerance

    public function test_the_person_who_counted_the_drawer_cannot_answer_for_it(): void
    {
        $this->tolerance(100);
        $this->fill(10000);

        // A manager who holds both keys, counting a drawer alone at night.
        $both = $this->makeUser(['name' => 'Manager With Keys']);
        $both->roles()->attach($this->roleWith(['cash.view', 'cash.counts', 'cash.counts.approve']));

        $other = $this->makeUser(['name' => 'Second Signatory']);
        $other->roles()->attach($this->roleWith(['cash.view', 'cash.counts.approve']));

        $this->count(['counted_amount' => '9600.00', 'difference_reason' => 'Banked 400 short by mistake'], $both)
            ->assertSessionHasNoErrors();

        $count = CashCount::query()->sole();

        $this->assertTrue($count->isPending());

        // Holding the key is not enough: the same pair of hands does not decide
        // both halves of the story.
        $this->actingAs($both)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'approve', 'note' => 'My own count'])
            ->assertSessionHasErrors('cash_count');

        $this->assertTrue($count->refresh()->isPending());
        $this->assertNull($count->journal_entry_id);

        $this->actingAs($both)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'reject', 'note' => 'My own count'])
            ->assertSessionHasErrors('cash_count');

        $this->assertTrue($count->refresh()->isPending());

        // Somebody else's answer is the only kind that counts.
        $this->actingAs($other)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'approve', 'note' => 'Checked the banking slip'])
            ->assertSessionHasNoErrors();

        $count->refresh();

        $this->assertTrue($count->isPosted());
        $this->assertSame($other->id, $count->decided_by);
        $this->assertNotNull($count->journal_entry_id);
    }

    public function test_above_the_tolerance_the_difference_waits_and_the_counter_cannot_approve_it(): void
    {
        $this->tolerance(100);
        $this->fill(10000);
        $before = JournalEntry::query()->count();

        $counter = $this->makeUser(['name' => 'Till Counter']);
        $counter->roles()->attach($this->roleWith(['cash.view', 'cash.counts']));

        $approver = $this->makeUser(['name' => 'Desk Manager']);
        $approver->roles()->attach($this->roleWith(['cash.view', 'cash.counts', 'cash.counts.approve']));

        $this->count(['counted_amount' => '9500.00', 'difference_reason' => 'Two courier payments came out of the till'], $counter)
            ->assertRedirect();

        $count = CashCount::query()->sole();

        $this->assertTrue($count->isPending());
        $this->assertNull($count->journal_entry_id);
        $this->assertSame('100.0000', $count->tolerance);

        // Nothing reached the ledger, and the drawer still reads as it did.
        $this->assertSame($before, JournalEntry::query()->count());
        $this->assertSame('10000.0000', $this->balance());

        // Counting without the second key is not a decision anybody can make.
        $this->actingAs($counter)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'approve'])
            ->assertForbidden();

        $this->assertTrue($count->refresh()->isPending());
        $this->assertSame($before, JournalEntry::query()->count());

        // Somebody else does, and that is the moment it posts. The approver may
        // also count a drawer of their own — this one is not theirs.
        $this->actingAs($approver)
            ->post(route('cash-bank.cash-counts.decide', $count), [
                'action' => 'approve',
                'note' => 'The vouchers arrived the next morning',
            ])
            ->assertRedirect();

        $count->refresh();

        $this->assertTrue($count->isPosted());
        $this->assertSame($approver->id, $count->decided_by);
        $this->assertNotNull($count->journal_entry_id);
        $this->assertSame($before + 1, JournalEntry::query()->count());
        $this->assertSame('9500.0000', $this->balance());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_bank.cash_count_approved')->count());

        // And a decided count is history, not a button.
        $this->actingAs($approver)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'approve'])
            ->assertSessionHasErrors('cash_count');
        $this->assertSame($before + 1, JournalEntry::query()->count());
    }

    public function test_below_the_tolerance_the_counter_corrects_it_themselves(): void
    {
        $this->tolerance(500);
        $this->fill(3000);

        $counter = $this->makeUser();
        $counter->roles()->attach($this->roleWith(['cash.view', 'cash.counts']));

        // A 200 gap is under a 500 tolerance, so it posts as it is counted.
        $this->count(['counted_amount' => '2800.00', 'difference_reason' => 'Tea money'], $counter)
            ->assertSessionHasNoErrors();

        $count = CashCount::query()->sole();

        $this->assertTrue($count->isPosted());
        $this->assertNotNull($count->journal_entry_id);
        $this->assertNull($count->decided_by);
        $this->assertSame('2800.0000', $this->balance());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_bank.cash_count_posted')->count());
    }

    public function test_a_refused_count_leaves_the_books_exactly_as_they_were(): void
    {
        $this->tolerance(100);
        $this->fill(6000);

        $counter = $this->makeUser();
        $counter->roles()->attach($this->roleWith(['cash.view', 'cash.counts']));

        $approver = $this->makeUser();
        $approver->roles()->attach($this->roleWith(['cash.view', 'cash.counts.approve']));

        $this->count(['counted_amount' => '5700.00', 'difference_reason' => 'Missing voucher'], $counter)
            ->assertSessionHasNoErrors();

        $count = CashCount::query()->sole();

        // A refusal without a reason is refused: the next counter has to be able
        // to read why this one was not accepted.
        $this->actingAs($approver)
            ->post(route('cash-bank.cash-counts.decide', $count), ['action' => 'reject'])
            ->assertSessionHasErrors('cash_count');

        $this->actingAs($approver)
            ->post(route('cash-bank.cash-counts.decide', $count), [
                'action' => 'reject',
                'note' => 'Recount with the supervisor before anything is written off',
            ])
            ->assertRedirect();

        $count->refresh();

        $this->assertSame(CashCount::STATUS_REJECTED, $count->status);
        $this->assertSame($approver->id, $count->decided_by);
        $this->assertNull($count->journal_entry_id);
        $this->assertSame(0, $this->posted());
        $this->assertSame('6000.0000', $this->balance());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'cash_bank.cash_count_rejected')->count());
    }

    public function test_a_drawer_cannot_be_counted_twice_while_a_difference_is_waiting(): void
    {
        $this->tolerance(50);
        $this->fill(2000);

        $this->count(['counted_amount' => '1800.00', 'difference_reason' => 'Short at the till'])->assertSessionHasNoErrors();

        $this->assertSame(1, CashCount::query()->count());

        // Counting it again would ask somebody to approve the same gap twice.
        $this->count(['counted_amount' => '1900.00', 'difference_reason' => 'Recount'])->assertSessionHasErrors('cash_count');
        $this->assertSame(1, CashCount::query()->count());

        // Another drawer is a different question entirely: the pending count of
        // one tin does not stop anybody counting the tin beside it.
        $second = app(MoneyAccountService::class)->create([
            'instrument' => 'cash',
            'name' => 'Front counter tin',
            'code' => '1110-C2',
        ], $this->admin->id);

        $this->count([
            'account_id' => $second->id,
            'counted_amount' => '0.00',
            'difference_reason' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CashCount::query()->count());
        $this->assertSame('-200.0000', (string) CashCount::query()->orderBy('id')->first()->variance);
    }

    // ------------------------------------------------------------- the rules

    public function test_only_a_cash_drawer_can_be_counted(): void
    {
        $this->fill(1000, '1120');

        $this->count(['account_id' => $this->account('1120')->id, 'counted_amount' => '1000.00', 'difference_reason' => null])
            ->assertSessionHasErrors('cash_count');

        $this->assertSame(0, CashCount::query()->count());

        // And a drawer belonging to somebody else cannot be counted from here.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignAccount = (int) DB::table('accounts')->insertGetId([
            'company_id' => $otherCompany,
            'code' => '1110',
            'name' => 'Their drawer',
            'type' => 'asset',
            'sub_type' => 'cash',
            'is_cash' => true,
            'is_group' => false,
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->count(['account_id' => $foreignAccount, 'counted_amount' => '10.00'])->assertSessionHasErrors('account_id');
        $this->assertSame(0, CashCount::query()->count());
    }

    public function test_the_desk_shows_the_drawers_and_the_sheet_reads_back_the_count(): void
    {
        $this->fill(8000);

        $this->count([
            'counted_amount' => '7850.00',
            'difference_reason' => 'Courier paid from the till',
            'denominations' => [
                ['kind' => 'note', 'face_value' => '1000', 'quantity' => 7],
                ['kind' => 'note', 'face_value' => '500', 'quantity' => 1],
                ['kind' => 'note', 'face_value' => '200', 'quantity' => 1],
                ['kind' => 'note', 'face_value' => '100', 'quantity' => 1],
                ['kind' => 'note', 'face_value' => '50', 'quantity' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $count = CashCount::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cash-counts'))
            ->assertOk()
            ->assertSee('Counting the drawer')
            ->assertSee('Cash in Hand')
            ->assertSee('7,850.00');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cash-counts.show', $count))
            ->assertOk()
            ->assertSee('Courier paid from the till')
            ->assertSee('৳1000 note')
            ->assertSee('7,850.00');

        // Another company's count is not this company's sheet.
        $foreign = (int) DB::table('cash_counts')->insertGetId([
            'company_id' => (int) DB::table('companies')->insertGetId([
                'singleton' => 0,
                'name' => 'Shadow Traders Ltd',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'account_id' => $this->account('1110')->id,
            'counted_on' => now()->toDateString(),
            'expected_amount' => '10.0000',
            'counted_amount' => '10.0000',
            'variance' => '0.0000',
            'tolerance' => '0.0000',
            'status' => CashCount::STATUS_POSTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.cash-counts.show', $foreign))
            ->assertNotFound();
    }
}
