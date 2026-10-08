<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\RecurringExpense;
use App\Domain\CashBank\Services\ExpenseService;
use App\Domain\CashBank\Services\RecurringExpenseService;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-19 — the expenses that come round again.
 *
 * A schedule is a promise about the future, and this is what the desk has to get
 * right about it:
 *  · writing the schedule posts nothing at all — a plan is not a payment;
 *  · on its own day it produces an ordinary expense through the ordinary desk,
 *    which means the approval limit applies to it exactly as it applies to an
 *    expense somebody typed, and the person who wrote the schedule cannot be the
 *    person who signs for what it produced;
 *  · a second run is a no-op, not a second month of rent: the unique
 *    (schedule, date) index is the referee and the date only moves once;
 *  · a refusal — a category that has been switched off, say — leaves the date
 *    where it was, so the month stays visibly unpaid instead of being skipped;
 *  · a monthly schedule keeps its day of the month and clamps to the end of a
 *    short month (the 31st is the 28th in February, and the 31st again in
 *    March), because a rent day is a day in a contract;
 *  · a schedule with a last date stops itself rather than generating into a
 *    period nobody agreed to;
 *  · the daily command is registered and does the same thing without a browser.
 */
class RecurringExpenseDeskTest extends TestCase
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

    /** How many entries the expense desk has posted — the number that must stay still. */
    protected function posted(): int
    {
        return JournalEntry::query()->where('source_type', 'expense')->count();
    }

    protected function category(string $accountCode = '5220', array $overrides = []): ExpenseCategory
    {
        return ExpenseCategory::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'OFFICE-RENT',
            'name' => 'Office rent',
            'account_id' => $this->account($accountCode)->id,
            'is_active' => true,
            'sort_order' => 0,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function payload(ExpenseCategory $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'payee' => 'Khan Properties',
            'amount' => '35000.00',
            'currency' => 'BDT',
            'settled_with' => Expense::SETTLED_MONEY,
            'money_account_id' => $this->account('1110')->id,
            'narration' => 'Monthly rent — Uttara warehouse',
            'frequency' => RecurringExpense::MONTHLY,
            'day_of_month' => 3,
            'starts_on' => now()->toDateString(),
            'is_active' => 1,
        ], $overrides);
    }

    protected function schedule(ExpenseCategory $category, array $overrides = [], ?User $actor = null): RecurringExpense
    {
        $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, $overrides))
            ->assertSessionHasNoErrors();

        return RecurringExpense::query()->orderByDesc('id')->firstOrFail();
    }

    /** The button that does what the daily run would do tomorrow morning. */
    protected function run(): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('cash-bank.expenses.recurring.run'));
    }

    /** Put the approval limit where the desk reads it. */
    protected function limit(float $amount): void
    {
        app(SettingService::class)->set(ExpenseService::SETTING_GROUP, ExpenseService::SETTING_KEY, $amount, null, $this->admin);
    }

    // ----------------------------------------------------------- the schedule

    public function test_the_recurring_desk_is_a_real_menu_entry_and_a_schedule_posts_nothing_by_itself(): void
    {
        $category = $this->category();

        // The catalogue's leaf is a real page, filtered by nothing but the key.
        $leaf = MenuItem::query()->where('route', '/app/cash-bank/expenses/recurring')->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('Recurring Expenses', $leaf->label);
        $this->assertSame('expenses.recurring', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.recurring'))
            ->assertOk()
            ->assertSee('The expenses that come round again')
            ->assertSee('No expense is scheduled yet');

        $this->actingAs($this->makeUser())
            ->get(route('cash-bank.expenses.recurring'))
            ->assertForbidden();

        $schedule = $this->schedule($category);

        // A plan is not a payment: the schedule exists, the ledger has not heard
        // about it, and the first run is the start date itself.
        $this->assertSame($category->id, (int) $schedule->category_id);
        $this->assertSame('35000.0000', (string) $schedule->amount);
        $this->assertSame(RecurringExpense::MONTHLY, $schedule->frequency);
        $this->assertTrue($schedule->isActive());
        $this->assertSame(now()->toDateString(), $schedule->next_due_on?->toDateString());
        $this->assertSame(0, (int) $schedule->generated_count);
        $this->assertSame('every month on the 3rd', $schedule->rhythm());
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, $this->posted());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_saved']);

        // The desk reads the schedule back, and the register says it is due.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.recurring'))
            ->assertOk()
            ->assertSee('Khan Properties')
            ->assertSee('every month on the 3rd')
            ->assertSee('1 schedule(s) have come due');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses'))
            ->assertOk()
            ->assertSee('standing expense(s) are due');
    }

    public function test_a_due_schedule_generates_one_expense_through_the_ordinary_desk(): void
    {
        $category = $this->category();
        $schedule = $this->schedule($category);

        $this->run()->assertSessionHasNoErrors();

        $expense = Expense::query()->sole();

        $this->assertSame($schedule->id, (int) $expense->recurring_expense_id);
        $this->assertTrue($expense->isGenerated());
        $this->assertStringStartsWith('EXP-', $expense->expense_no);
        $this->assertSame(now()->toDateString(), $expense->expense_date?->toDateString());
        $this->assertSame('35000.0000', (string) $expense->amount);
        $this->assertSame(Expense::STATUS_POSTED, $expense->status);
        $this->assertSame(1, $this->posted());

        // Dr the category's account, Cr the account the money left — the same
        // two legs a typed expense would have written.
        $legs = $expense->journalEntry->lines()->get();
        $this->assertCount(2, $legs);
        $this->assertSame($this->account('5220')->id, (int) $legs->firstWhere('dc', 'debit')->account_id);
        $this->assertSame($this->account('1110')->id, (int) $legs->firstWhere('dc', 'credit')->account_id);

        // The date moved exactly one period, keeping the day of the month.
        $schedule->refresh();
        $this->assertSame(1, (int) $schedule->generated_count);
        $this->assertSame(now()->toDateString(), $schedule->last_generated_on?->toDateString());
        $this->assertSame(3, (int) $schedule->next_due_on?->day);
        $this->assertTrue($schedule->next_due_on->gt(now()->endOfDay()));
        $this->assertFalse($schedule->isDue());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_generated']);

        // Nothing is due any more, so the button says so and writes nothing.
        $this->run()->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, $this->posted());
    }

    public function test_a_second_run_on_the_same_date_is_a_no_op_not_a_second_month_of_rent(): void
    {
        $category = $this->category();
        $schedule = $this->schedule($category);

        $service = app(RecurringExpenseService::class);

        $first = $service->generateOne($schedule->refresh(), $this->admin);

        // Simulate the overlap the index exists for: a run that produced the
        // expense but had not yet moved the date when a second run arrived.
        $schedule->forceFill(['next_due_on' => $first->expense_date->toDateString()])->save();

        $second = $service->generateOne($schedule->refresh(), $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Expense::query()->where('recurring_expense_id', $schedule->id)->count());
        $this->assertSame(1, $this->posted());

        // Adopting the existing expense does not inflate the count — the money
        // only left once.
        $this->assertSame(1, (int) $schedule->refresh()->generated_count);
    }

    public function test_a_generated_expense_waits_for_a_signature_from_somebody_else(): void
    {
        $this->limit(1000);
        $category = $this->category();

        // A manager who writes schedules and holds the approval key too.
        $manager = $this->makeUser();
        $manager->roles()->attach($this->roleWith(['portal.erp.access', 
            'expenses.view', 'expenses.create', 'expenses.approve', 'expenses.recurring',
        ])->id);

        $schedule = $this->schedule($category, ['amount' => '35000.00'], $manager);
        $this->assertSame($manager->id, (int) $schedule->created_by);

        // Generating as the schedule's author: the same gate as a typed expense.
        app(RecurringExpenseService::class)->generateDue(null, null, $manager);

        $expense = Expense::query()->sole();
        $this->assertSame(Expense::STATUS_PENDING, $expense->status);
        $this->assertNull($expense->journal_entry_id);
        $this->assertTrue($expense->approval_gate);
        $this->assertSame('1000.0000', (string) $expense->approval_threshold);
        $this->assertSame(0, $this->posted());

        // Automation is never a way round a signature: the person who wrote the
        // schedule cannot approve what it produced.
        $this->actingAs($manager)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), ['action' => 'approve'])
            ->assertSessionHasErrors('expense');
        $this->assertStringContainsString('cannot approve it', $this->allFlashedErrors());
        $this->assertSame(0, $this->posted());

        $approver = $this->makeUser();
        $approver->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.approve'])->id);

        $this->actingAs($approver)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), [
                'action' => 'approve',
                'note' => 'Rent for the month, matches the lease.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Expense::STATUS_POSTED, $expense->refresh()->status);
        $this->assertSame(1, $this->posted());
    }

    public function test_a_refused_generation_keeps_its_date_and_never_skips_a_month(): void
    {
        $category = $this->category();
        $schedule = $this->schedule($category);

        // The category is switched off — the account it books to no longer
        // describes anything. Nothing posts, and nothing is skipped either.
        $category->forceFill(['is_active' => false])->save();

        $this->run();

        $schedule->refresh();
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, $this->posted());
        $this->assertTrue($schedule->isActive());
        $this->assertSame(now()->toDateString(), $schedule->next_due_on?->toDateString());
        $this->assertSame(0, (int) $schedule->generated_count);
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_refused']);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.recurring'))
            ->assertOk()
            ->assertSee('switched off');

        // Put it right and the same schedule pays the month it owes.
        $category->forceFill(['is_active' => true])->save();

        $this->run()->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, $this->posted());
        $this->assertSame(1, (int) $schedule->refresh()->generated_count);
    }

    public function test_a_schedule_stops_itself_at_its_last_date(): void
    {
        $category = $this->category();

        // A one-month contract: it pays this month and then has nothing left.
        $schedule = $this->schedule($category, [
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ]);

        $this->run()->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::query()->count());
        $this->assertFalse($schedule->refresh()->isActive());
        $this->assertSame(1, (int) $schedule->generated_count);

        // The next run generates nothing for it: the contract is over.
        $this->run();
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, $this->posted());
    }

    public function test_the_daily_command_generates_for_a_named_company_without_a_browser(): void
    {
        $category = $this->category();
        $this->schedule($category, ['payee' => 'Aarong Dairy', 'amount' => '2500.00']);

        $this->artisan('erp:cash:recurring-expenses', ['--company' => $this->admin->company_id])
            ->expectsOutputToContain('1 expense(s) generated')
            ->assertSuccessful();

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, $this->posted());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_generated']);

        // The dry run is honest about what it did — nothing.
        $this->schedule($category, ['payee' => 'Office water', 'amount' => '600.00', 'day_of_month' => 5]);

        $this->artisan('erp:cash:recurring-expenses', ['--company' => $this->admin->company_id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, $this->posted());

        // A command nobody schedules is a command nobody runs.
        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertIsString($console);
        $this->assertStringContainsString('erp:cash:recurring-expenses', $console);
        $this->assertStringContainsString('dailyAt(', $console);
    }

    public function test_pausing_a_schedule_stops_the_run_and_resuming_keeps_the_date(): void
    {
        $category = $this->category();
        $schedule = $this->schedule($category);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.toggle', ['schedule' => $schedule->id]))
            ->assertSessionHasNoErrors();

        $schedule->refresh();
        $this->assertFalse($schedule->isActive());
        $this->assertSame(now()->toDateString(), $schedule->next_due_on?->toDateString());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_paused']);

        $this->run();
        $this->assertSame(0, Expense::query()->count());

        // Resuming keeps the date it owed all along — pausing is not a reset.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.toggle', ['schedule' => $schedule->id]))
            ->assertSessionHasNoErrors();

        $schedule->refresh();
        $this->assertTrue($schedule->isActive());
        $this->assertSame(now()->toDateString(), $schedule->next_due_on?->toDateString());
        $this->assertSame(1, app(RecurringExpenseService::class)->dueCount());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.recurring_expense_resumed']);

        $this->run()->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::query()->count());
    }

    public function test_a_schedule_cannot_pay_nothing_end_before_it_starts_or_repeat_never(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, ['amount' => '0']))
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, [
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->subMonth()->toDateString(),
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, ['frequency' => 'sometimes']))
            ->assertSessionHasErrors('frequency');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, ['day_of_month' => 45]))
            ->assertSessionHasErrors('day_of_month');

        // Paid from an account means naming the account — the ledger has to know
        // which one the money leaves.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.store'), $this->payload($category, ['money_account_id' => null]))
            ->assertSessionHasErrors('money_account_id');

        $this->assertSame(0, RecurringExpense::query()->count());
        $this->assertSame(0, Expense::query()->count());

        // The desk itself refuses a schedule it could never honour.
        $inactive = $this->category('5230', ['code' => 'UTILITIES', 'name' => 'Utilities', 'is_active' => false]);
        $schedule = $this->schedule($inactive);

        $service = app(RecurringExpenseService::class);

        try {
            $service->generateOne($schedule->refresh(), $this->admin);
            $this->fail('A schedule pointing at a switched-off category should not generate.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('switched off', $error->getMessage());
        }

        $this->assertSame(0, Expense::query()->count());

        // Money can only leave an account money sits in. A schedule pointed at an
        // expense account is refused where it is written rather than on the
        // morning it was supposed to pay somebody.
        try {
            $service->save(
                $this->payload($category, ['money_account_id' => $this->account('5220')->id]),
                null,
                $this->admin,
            );
            $this->fail('A schedule paid from an expense account should be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Money cannot leave from', $error->getMessage());
        }

        $this->assertSame(1, RecurringExpense::query()->count());
    }

    public function test_another_companys_schedule_is_neither_shown_nor_runnable(): void
    {
        $category = $this->category();

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = RecurringExpense::query()->create([
            'company_id' => $otherCompany,
            'category_id' => $category->id,
            'payee' => 'Not ours',
            'amount' => '1000.0000',
            'currency' => 'BDT',
            'settled_with' => Expense::SETTLED_MONEY,
            'money_account_id' => $this->account('1110')->id,
            'frequency' => RecurringExpense::MONTHLY,
            'day_of_month' => 1,
            'starts_on' => now()->toDateString(),
            'next_due_on' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.recurring'))
            ->assertOk()
            ->assertDontSee('Not ours');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.recurring.toggle', ['schedule' => $foreign->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->put(route('cash-bank.expenses.recurring.update', ['schedule' => $foreign->id]),
                $this->payload($category, ['payee' => 'Not ours']))
            ->assertNotFound();

        $this->assertTrue($foreign->refresh()->isActive());
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_recurring_key_is_its_own_and_generating_also_needs_the_expense_key(): void
    {
        $category = $this->category();
        $this->schedule($category);

        // A clerk who may read the schedules but not record expenses can see
        // what is due — and cannot turn it into money.
        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.recurring'])->id);

        $this->actingAs($reader)
            ->get(route('cash-bank.expenses.recurring'))
            ->assertOk()
            ->assertSee('Khan Properties');

        $this->actingAs($reader)
            ->post(route('cash-bank.expenses.recurring.run'))
            ->assertForbidden();

        $this->assertSame(0, Expense::query()->count());

        // The manager's bundle carries the key; the category desk stays out of it.
        $manager = \App\Domain\Foundation\Role::query()->where('slug', 'manager')->firstOrFail();
        $keys = $manager->permissions()->pluck('key')->all();

        $this->assertContains('expenses.recurring', $keys);
        $this->assertContains('expenses.view', $keys);
        $this->assertNotContains('expenses.categories', $keys);
    }

    public function test_a_month_end_schedule_clamps_and_returns_to_the_31st(): void
    {
        $category = $this->category();

        // Arithmetic asserted on the schedule itself: a January date that runs
        // into February, then back into a long month. Rent on the 31st is the
        // 28th in February and the 31st again in March — never the 3rd of March.
        $schedule = RecurringExpense::query()->create([
            'company_id' => $this->admin->company_id,
            'category_id' => $category->id,
            'payee' => 'Khan Properties',
            'amount' => '35000.0000',
            'currency' => 'BDT',
            'settled_with' => Expense::SETTLED_MONEY,
            'money_account_id' => $this->account('1110')->id,
            'frequency' => RecurringExpense::MONTHLY,
            'day_of_month' => 31,
            'starts_on' => '2026-01-31',
            'next_due_on' => '2026-01-31',
            'is_active' => true,
        ]);

        $this->assertSame('every month on the 31st', $schedule->rhythm());

        $february = $schedule->nextDueAfter(Carbon::parse('2026-01-31'));
        $this->assertSame('2026-02-28', $february->toDateString());

        $march = $schedule->nextDueAfter($february);
        $this->assertSame('2026-03-31', $march->toDateString());

        // A future date is not late, and a past one says how late.
        $this->assertFalse($schedule->isDue(Carbon::parse('2026-01-30')));
        $this->assertTrue($schedule->isDue(Carbon::parse('2026-02-02')));
        $this->assertSame(2, $schedule->daysLate(Carbon::parse('2026-02-02')));

        // Weekly schedules own a weekday, not a day of the month.
        $weekly = $this->schedule($category, ['frequency' => RecurringExpense::WEEKLY, 'payee' => 'Office water']);
        $this->assertNull($weekly->day_of_month);
        $this->assertSame(
            Carbon::parse(now()->toDateString())->addWeek()->toDateString(),
            $weekly->nextDueAfter(now()->startOfDay())->toDateString()
        );
    }
}
