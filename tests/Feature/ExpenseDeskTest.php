<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\PostingRule;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\Services\ExpenseService;
use App\Domain\CashBank\Support\AmountInWords;
use App\Domain\Documents\Document;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-15…§08-18 — the expense desk.
 *
 * What this pins, in the order the complaints would arrive:
 *  · recording an expense posts it — once — debiting the category's own ledger
 *    account, because a category that is not an account is a report nobody can
 *    reconcile;
 *  · at or above the approval limit it posts nothing at all until somebody else
 *    approves it, and the person who recorded it can never be that somebody;
 *  · a refused expense leaves no trace in the ledger, and a paid one leaves a
 *    credit on the account the money actually left;
 *  · an unpaid expense is a liability resolved through the company's posting
 *    rules — and when those rules are not configured the whole thing is refused
 *    rather than half-written;
 *  · reversing a posted expense writes a second entry instead of editing the
 *    first, because money that has moved has moved;
 *  · reading the register, recording an expense, approving one and re-pointing a
 *    category are four separate keys;
 *  · the leaves the catalogue promises are real pages, each one filtered;
 *  · a receipt, when there is one, is filed in the document register and stays
 *    attached to the expense it explains.
 */
class ExpenseDeskTest extends TestCase
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
            'expense_date' => now()->toDateString(),
            'payee' => 'Dhaka WASA',
            'amount' => '6400.00',
            'settled_with' => Expense::SETTLED_MONEY,
            'money_account_id' => $this->account('1110')->id,
            'narration' => 'March water bill for the Uttara warehouse',
        ], $overrides);
    }

    protected function record(ExpenseCategory $category, array $overrides = [], ?User $actor = null): Expense
    {
        $this->actingAs($actor ?? $this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category, $overrides))
            ->assertSessionHasNoErrors();

        return Expense::query()->orderByDesc('id')->firstOrFail();
    }

    /** Put the approval limit where the desk reads it. */
    protected function limit(float $amount): void
    {
        app(SettingService::class)->set(ExpenseService::SETTING_GROUP, ExpenseService::SETTING_KEY, $amount, null, $this->admin);
    }

    // ------------------------------------------------------------- the desk

    public function test_the_register_is_a_real_menu_entry_and_recording_an_expense_posts_it_once(): void
    {
        $category = $this->category('5230', ['code' => 'UTILITIES', 'name' => 'Utilities']);

        // The catalogue's leaves are real pages, each one filtered.
        $all = MenuItem::query()->where('label', 'All Expenses')->firstOrFail();
        $this->assertSame('active', $all->status);
        $this->assertSame('/app/cash-bank/expenses', $all->route);
        $this->assertSame('expenses.view', $all->permission?->key);

        // Matched by the page it opens, not by its label: three modules have a
        // leaf called "Pending Approval", and the desk this one belongs to is the
        // expense register, filtered.
        $pending = MenuItem::query()->where('route', '/app/cash-bank/expenses?status=pending_approval')->firstOrFail();
        $this->assertSame('active', $pending->status);
        $this->assertSame('Pending Approval', $pending->label);
        $this->assertSame('expenses.view', $pending->permission?->key);

        $categories = MenuItem::query()->where('label', 'Expense Categories')->firstOrFail();
        $this->assertSame('active', $categories->status);
        $this->assertSame('/app/cash-bank/expense-categories', $categories->route);
        $this->assertSame('expenses.categories', $categories->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses'))
            ->assertOk()
            ->assertSee('What the company spent');

        $this->actingAs($this->makeUser())->get(route('cash-bank.expenses'))->assertForbidden();

        $expense = $this->record($category);

        $this->assertSame(Expense::STATUS_POSTED, $expense->status);
        $this->assertStringStartsWith('EXP-', $expense->expense_no);
        $this->assertSame(1, $this->posted());

        // Dr the category's account, Cr the account the money left — and nothing else.
        $entry = $expense->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame('expense', $entry->source_type);
        $this->assertSame($expense->id, (int) $entry->source_id);

        $legs = $entry->lines()->get();
        $this->assertCount(2, $legs);
        $this->assertSame($this->account('5230')->id, (int) $legs->firstWhere('dc', 'debit')->account_id);
        $this->assertSame($this->account('1110')->id, (int) $legs->firstWhere('dc', 'credit')->account_id);
        $this->assertSame('6400.0000', (string) $legs->firstWhere('dc', 'debit')->amount);

        // The register shows it, with the account the category books to.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses'))
            ->assertOk()
            ->assertSee('Dhaka WASA')
            ->assertSee($expense->expense_no);

        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_recorded']);
    }

    public function test_an_expense_at_or_above_the_limit_waits_and_posts_only_when_somebody_approves(): void
    {
        $this->limit(5000);
        $category = $this->category();

        $expense = $this->record($category, ['amount' => '6400.00']);

        // Waiting is not posted: no entry, and the document says why it waited.
        $this->assertSame(Expense::STATUS_PENDING, $expense->status);
        $this->assertNull($expense->journal_entry_id);
        $this->assertTrue($expense->approval_gate);
        $this->assertSame('5000.0000', (string) $expense->approval_threshold);
        $this->assertSame(0, $this->posted());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_submitted']);

        // Under the limit the same desk posts immediately.
        $small = $this->record($category, ['amount' => '4999.99', 'payee' => 'Rickshaw fare']);
        $this->assertSame(Expense::STATUS_POSTED, $small->status);
        $this->assertSame(1, $this->posted());

        // The person who approves is somebody else.
        $approver = $this->makeUser();
        $approver->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.view', 'expenses.approve'])->id);

        $this->actingAs($approver)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), [
                'action' => 'approve',
                'note' => 'Water bill, matches the meter reading',
            ])
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(Expense::STATUS_POSTED, $expense->status);
        $this->assertNotNull($expense->journal_entry_id);
        $this->assertSame($approver->id, (int) $expense->decided_by);
        $this->assertSame(2, $this->posted());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_approved']);

        // Approving it again is not a second posting — it is a refusal.
        $this->actingAs($approver)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), ['action' => 'approve'])
            ->assertSessionHasErrors('expense');
        $this->assertSame(2, $this->posted());
    }

    public function test_nobody_approves_their_own_expense(): void
    {
        $this->limit(1000);
        $category = $this->category();

        // A manager who records expenses and holds the approval key too.
        $manager = $this->makeUser();
        $manager->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.view', 'expenses.create', 'expenses.approve'])->id);

        $expense = $this->record($category, ['amount' => '2000.00'], $manager);

        $this->actingAs($manager)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), ['action' => 'approve'])
            ->assertSessionHasErrors('expense');

        $this->assertStringContainsString('cannot approve it', $this->allFlashedErrors());
        $this->assertSame(Expense::STATUS_PENDING, $expense->refresh()->status);
        $this->assertSame(0, $this->posted());
    }

    public function test_a_refused_expense_never_reaches_the_ledger(): void
    {
        $this->limit(100);
        $category = $this->category();
        $expense = $this->record($category, ['amount' => '9000.00']);

        $approver = $this->makeUser();
        $approver->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.approve'])->id);

        $this->actingAs($approver)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), [
                'action' => 'reject',
                'note' => 'No receipt and no purchase order',
            ])
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(Expense::STATUS_REJECTED, $expense->status);
        $this->assertNull($expense->journal_entry_id);
        $this->assertSame(0, $this->posted());
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_rejected']);
    }

    public function test_an_unpaid_expense_is_a_liability_and_a_missing_rule_refuses_the_whole_thing(): void
    {
        $category = $this->category();
        $supplier = $this->makeSupplier('Meghna Enterprise');

        $expense = $this->record($category, [
            'settled_with' => Expense::SETTLED_PAYABLE,
            'money_account_id' => null,
            'supplier_id' => $supplier,
            'amount' => '12000.00',
        ]);

        $this->assertNull($expense->money_account_id);
        $this->assertSame(1, $this->posted());

        $credit = $expense->journalEntry->lines()->get()->firstWhere('dc', 'credit');
        // Which account carries the liability is the company's decision — it comes
        // from the posting rules, never from a code written into this module.
        $this->assertSame($this->account('2110')->id, (int) $credit->account_id);
        $this->assertSame('supplier', $credit->party_type);
        $this->assertSame($supplier, (int) $credit->party_id);

        // With no rule configured the desk refuses rather than half-writing: the
        // expense is not stored, because an expense whose credit side cannot be
        // written is not a document, it is a hole in the ledger.
        PostingRule::query()->where('event_type', ExpenseService::PAYABLE_EVENT)->delete();

        $before = Expense::query()->count();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category, [
                'settled_with' => Expense::SETTLED_PAYABLE,
                'money_account_id' => null,
                'amount' => '500.00',
            ]))
            ->assertSessionHasErrors('expense');

        $this->assertSame($before, Expense::query()->count());
        $this->assertSame(1, $this->posted());
    }

    public function test_a_category_has_to_book_to_a_real_expense_account(): void
    {
        // A liability account is not a place an expense belongs.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expense-categories.store'), [
                'code' => 'WRONG-SIDE',
                'name' => 'Wrong side',
                'account_id' => $this->account('2110')->id,
            ])
            ->assertSessionHasErrors('category');

        $this->assertStringContainsString('expense account', $this->allFlashedErrors());
        $this->assertSame(0, ExpenseCategory::query()->count());

        // A group account is refused too: nothing can be posted to a group.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expense-categories.store'), [
                'code' => 'GROUPED',
                'name' => 'Grouped',
                'account_id' => $this->account('5200')->id,
            ])
            ->assertSessionHasErrors('category');

        // The real thing saves, and the code is what reports will carry.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expense-categories.store'), [
                'code' => 'office-rent',
                'name' => 'Office rent',
                'account_id' => $this->account('5220')->id,
                'sort_order' => 5,
            ])
            ->assertSessionHasNoErrors();

        $category = ExpenseCategory::query()->firstOrFail();
        $this->assertSame('OFFICE-RENT', $category->code);
        $this->assertSame('5220 — Rent Expense', $category->accountLabel());

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expense-categories'))
            ->assertOk()
            ->assertSee('OFFICE-RENT');

        // And a category can be switched off without touching what it already held.
        $this->actingAs($this->admin)
            ->put(route('cash-bank.expense-categories.update', ['category' => $category->id]), [
                'code' => 'OFFICE-RENT',
                'name' => 'Office rent',
                'account_id' => $this->account('5220')->id,
                'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($category->refresh()->isActive());

        // A switched-off category cannot receive new money, and nothing is guessed
        // in its place.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category))
            ->assertSessionHasErrors('expense');

        $this->assertSame(0, $this->posted());
    }

    public function test_only_a_money_account_can_be_paid_from(): void
    {
        $category = $this->category();

        // Revenue is not an account money leaves from.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category, [
                'money_account_id' => $this->account('4200')->id,
            ]))
            ->assertSessionHasErrors('expense');

        $this->assertStringContainsString('money accounts', $this->allFlashedErrors());
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(0, $this->posted());

        // A paid expense that names no account is refused by the form, before the
        // ledger is ever asked.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category, ['money_account_id' => null]))
            ->assertSessionHasErrors('money_account_id');

        $this->assertSame(0, $this->posted());
    }

    public function test_a_posted_expense_is_reversed_rather_than_edited(): void
    {
        $category = $this->category();
        $expense = $this->record($category);
        $original = $expense->journalEntry;

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), ['action' => 'reverse'])
            ->assertSessionHasErrors('note');

        $this->assertSame(Expense::STATUS_POSTED, $expense->refresh()->status);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), [
                'action' => 'reverse',
                'note' => 'Recorded twice — the receipt belongs to the March bill',
            ])
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(Expense::STATUS_REVERSED, $expense->status);
        $this->assertNotNull($expense->reversal_entry_id);

        // The original entry is untouched and still stands: money that moved moved.
        $this->assertSame($original->id, (int) $expense->journal_entry_id);
        $this->assertSame('6400.0000', (string) $original->lines()->where('dc', 'debit')->firstOrFail()->amount);
        $this->assertSame('reversal', $expense->reversalEntry->journal_type);
        $this->assertSame(1, $this->posted());

        // Reversing twice would take the money out of the books twice.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), [
                'action' => 'reverse',
                'note' => 'Again',
            ])
            ->assertSessionHasErrors('expense');

        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_reversed']);
    }

    public function test_reading_recording_approving_and_configuring_are_four_keys(): void
    {
        $category = $this->category();

        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.view'])->id);

        $this->actingAs($reader)->get(route('cash-bank.expenses'))->assertOk();
        $this->actingAs($reader)->get(route('cash-bank.expense-categories'))->assertOk();
        $this->actingAs($reader)
            ->post(route('cash-bank.expenses.store'), $this->payload($category))
            ->assertForbidden();
        $this->actingAs($reader)
            ->get(route('cash-bank.expenses.create'))
            ->assertForbidden();
        $this->actingAs($reader)
            ->post(route('cash-bank.expense-categories.store'), [
                'code' => 'NOPE',
                'name' => 'Nope',
                'account_id' => $this->account('5220')->id,
            ])
            ->assertForbidden();

        // A clerk who records expenses cannot approve one or re-point a category.
        $clerk = $this->makeUser();
        $clerk->roles()->attach($this->roleWith(['portal.erp.access', 'expenses.view', 'expenses.create'])->id);

        $expense = $this->record($category, ['amount' => '300.00'], $clerk);

        $this->actingAs($clerk)
            ->post(route('cash-bank.expenses.decide', ['expense' => $expense->id]), ['action' => 'approve'])
            ->assertForbidden();
        $this->actingAs($clerk)
            ->put(route('cash-bank.expense-categories.update', ['category' => $category->id]), [
                'code' => $category->code,
                'name' => 'Renamed',
                'account_id' => $this->account('5220')->id,
            ])
            ->assertForbidden();

        $this->assertSame('Office rent', $category->refresh()->name);
        $this->assertSame(1, $this->posted());
    }

    public function test_a_receipt_is_filed_in_the_document_register_and_stays_with_the_expense(): void
    {
        Storage::fake('local');

        $category = $this->category();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), array_merge($this->payload($category), [
                'receipt' => UploadedFile::fake()->image('wasa-bill.jpg'),
            ]))
            ->assertSessionHasNoErrors();

        $expense = Expense::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotNull($expense->receipt_document_id);

        $document = Document::query()->findOrFail($expense->receipt_document_id);
        $this->assertSame('wasa-bill.jpg', $document->original_name);
        $this->assertSame('expense_receipt', $document->purpose);
        $this->assertSame(Expense::class, $document->owner_type);
        $this->assertSame($expense->id, (int) $document->owner_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'cash.expense_receipt_attached']);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.show', ['expense' => $expense->id]))
            ->assertOk()
            ->assertSee('wasa-bill.jpg')
            ->assertSee('6,400.00');

        // A voucher reads its amount in words; the same words function the cheque
        // sheet uses, so the two printed papers cannot drift apart.
        $this->assertSame('Taka six thousand four hundred only', AmountInWords::en((string) $expense->amount));

        // An expense with no paper at all is still a complete expense.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.store'), $this->payload($category, ['payee' => 'Rickshaw fare', 'amount' => '60.00']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Expense::query()->orderByDesc('id')->firstOrFail()->receipt_document_id);
    }

    public function test_another_companys_expense_is_not_found_and_not_decidable(): void
    {
        $category = $this->category();

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = Expense::query()->create([
            'company_id' => $otherCompany,
            'expense_no' => 'EXP-2026-00099',
            'category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'payee' => 'Not ours',
            'amount' => '999.00',
            'settled_with' => Expense::SETTLED_MONEY,
            'money_account_id' => $this->account('1110')->id,
            'status' => Expense::STATUS_PENDING,
            'approval_gate' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.expenses.show', ['expense' => $foreign->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.expenses.decide', ['expense' => $foreign->id]), ['action' => 'approve'])
            ->assertNotFound();

        $this->assertSame(Expense::STATUS_PENDING, $foreign->refresh()->status);
        $this->assertSame(0, $this->posted());
    }

    // ------------------------------------------------------------- internals

    protected function makeSupplier(string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'company_id' => $this->admin->company_id,
            'code' => 'SUP-'.strtoupper(substr(md5($name), 0, 6)),
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
