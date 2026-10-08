<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\CashBank\BankReconciliation;
use App\Domain\CashBank\BankStatementImport;
use App\Domain\CashBank\BankStatementLine;
use App\Domain\CashBank\ReconciliationLine;
use App\Domain\CashBank\Services\BankStatementImportService;
use App\Domain\Foundation\User;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §08-08/08-09/08-12 — reconciling a bank account and a mobile wallet.
 *
 * What this pins, in the order the complaints would arrive:
 *  · a statement is one document, so it imports whole or not at all — a bad row
 *    stops the file and names itself, because half a statement reconciles to a
 *    difference nobody caused;
 *  · the same statement cannot be imported twice, for the same reason doubled;
 *  · the file the desk hands out is a file the desk can read: a statement opens
 *    with a brought-forward figure, and a labelled non-movement row is set aside
 *    rather than refused — otherwise the desk's own template would fail its own
 *    reader, which is exactly the sort of thing a user finds in the first minute;
 *  · the proof is arithmetic: `difference` is the period's unexplained opening
 *    gap — statement opening against book opening — so naming a leftover by hand
 *    restates the lists without pretending to have moved that number;
 *  · a reconciliation that does not add up cannot be signed off, and the person
 *    who prepared it is not the person who signs it;
 *  · a signed-off period is history: its lines are not re-matched, not re-opened
 *    and not picked up by a later reconciliation;
 *  · the closing figure can be corrected while the period is open, because
 *    reading it off the wrong row is the commonest way this desk fails;
 *  · a wallet is reconciled under its own key, and the screen says out loud that
 *    no provider API is connected instead of offering a button that cannot work.
 */
class ReconciliationDeskTest extends TestCase
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

    // ---------------------------------------------------------------- the hubs

    public function test_the_hubs_list_what_can_be_reconciled_and_ask_for_the_right_keys(): void
    {
        $this->actingAs($this->admin)
            ->get(route('cash-bank.reconciliations'))
            ->assertOk()
            ->assertSee('Bank Account');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.wallets'))
            ->assertOk()
            ->assertSee('No mobile wallet has been opened yet');

        // A role that may read geo masters is not a role that has looked at a
        // bank statement — reading one's own books is a different key.
        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['masters.view'])->id);

        $this->actingAs($outsider)->get(route('cash-bank.reconciliations'))->assertForbidden();
        $this->actingAs($outsider)->get(route('cash-bank.wallets'))->assertForbidden();
    }

    // ------------------------------------------------------- reading the file

    public function test_a_statement_imports_whole_or_not_at_all_and_never_twice(): void
    {
        $bank = $this->bank();

        $this->import($bank, $this->statement())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, BankStatementLine::query()
            ->where('account_id', $bank->id)
            ->where('description', 'Opening balance')
            ->count(), 'the brought-forward row is read and set aside, never stored as a movement');

        $this->assertDatabaseHas('bank_statement_imports', [
            'account_id' => $bank->id,
            'status' => BankStatementImport::STATUS_IMPORTED,
            'row_count' => 2,
            'imported_count' => 2,
            'rejected_count' => 0,
        ]);

        // The bank's language, translated once: a statement credit is money in,
        // a statement debit is money out of this account.
        $this->assertSame(0, bccomp($this->line($bank, 'credit')->signedAmount(), '5000.0000', 4));
        $this->assertSame(0, bccomp($this->line($bank, 'debit')->signedAmount(), '-250.0000', 4));

        // One unreadable row stops the file, and the row is named.
        $this->import($bank, $this->statement(chargedAs: 'not-a-number'))
            ->assertSessionHasErrors('statement');

        $this->assertSame(2, $this->lines($bank)->count(), 'nothing lands from a refused file');
        $this->assertDatabaseHas('bank_statement_imports', [
            'account_id' => $bank->id,
            'status' => BankStatementImport::STATUS_REJECTED,
            'rejected_count' => 1,
        ]);

        // The same statement twice would double every line.
        $this->import($bank, $this->statement())
            ->assertSessionHasErrors('statement');

        $this->assertSame(2, $this->lines($bank)->count());
    }

    public function test_the_file_the_desk_hands_out_is_a_file_the_desk_can_read(): void
    {
        $statements = app(BankStatementImportService::class);

        $rows = array_merge([$statements->templateHeadings()], $statements->templateRows());

        $csv = implode("\n", array_map(fn (array $row) => implode(',', $row), $rows));

        $bank = $this->bank();

        // Read it first: a preview stores no line, and says how many rows it saw.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.statement.import', ['account' => $bank->id]), [
                'file' => UploadedFile::fake()->createWithContent('template.csv', $csv),
                'preview' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->lines($bank)->count(), 'a read is not an import');
        $this->assertDatabaseHas('bank_statement_imports', [
            'account_id' => $bank->id,
            'status' => BankStatementImport::STATUS_PREVIEW,
            'imported_count' => 0,
        ]);

        // Now the real thing: the template's four rows are three movements and
        // one bought-forward figure, and the desk imports all three.
        $this->import($bank, $csv)->assertSessionHasNoErrors();

        $this->assertSame(3, $this->lines($bank)->count(), 'the opening row is read and set aside, not refused');
        $this->assertDatabaseHas('bank_statement_imports', [
            'account_id' => $bank->id,
            'status' => BankStatementImport::STATUS_IMPORTED,
            'imported_count' => 3,
            'rejected_count' => 0,
        ]);
    }

    // ------------------------------------------------------------ the proof

    public function test_the_leftovers_explain_the_gap_and_the_signature_needs_a_second_person(): void
    {
        $bank = $this->bank();
        $from = $this->monthStart();
        $to = $this->monthEnd();

        $this->receive($bank, '5000.00', $from->toDateString());

        // The bank records the deposit the next day and takes its quarter's charge.
        $this->import($bank, $this->statement($from->copy()->addDay()->toDateString()))->assertSessionHasNoErrors();

        $this->open($bank, $from->toDateString(), $to->toDateString(), '4750.00')->assertSessionHasNoErrors();

        $reconciliation = $this->latest($bank);

        $this->assertSame(1, $reconciliation->matched_count, 'the receipt and the deposit are the same money');
        $this->assertSame(1, $reconciliation->unmatched_statement_count, 'the charge has no line in these books');
        $this->assertSame(0, $reconciliation->unmatched_book_count);
        $this->assertSame(0, bccomp((string) $reconciliation->unmatched_statement_total, '-250.0000', 4));
        $this->assertSame(0, bccomp((string) $reconciliation->difference, '0.0000', 4));
        $this->assertSame(BankReconciliation::STATUS_BALANCED, $reconciliation->status);
        $this->assertTrue($reconciliation->isProved());

        // The bank's own opening figure is read off its running balance, and the
        // books' opening is read off the ledger — not typed by anybody.
        $this->assertSame(0, bccomp((string) $reconciliation->statement_opening, '0.0000', 4));
        $this->assertSame(0, bccomp((string) $reconciliation->book_opening, '0.0000', 4));
        $this->assertSame(0, bccomp((string) $reconciliation->book_balance, '5000.0000', 4));

        // Three frozen lines: the book receipt, the deposit, the charge.
        $this->assertSame(3, ReconciliationLine::query()->where('reconciliation_id', $reconciliation->id)->count());
        $this->assertSame(2, ReconciliationLine::query()->where('reconciliation_id', $reconciliation->id)
            ->where('state', ReconciliationLine::STATE_MATCHED)->count());

        $this->assertNotNull($this->line($bank, 'credit')->matched_journal_line_id, 'the deposit remembers the book line it found');
        $this->assertNotNull($this->line($bank, 'credit')->matched_at);

        // The pair was made by the amount-and-date rule, so it is not un-picked
        // one line at a time: the period is re-opened instead.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.unmatch', ['reconciliation' => $reconciliation->id]), [
                'line_id' => $this->frozen($reconciliation, ReconciliationLine::SIDE_STATEMENT, matched: true)->id,
            ])
            ->assertSessionHasErrors('reconciliation');

        // The preparer cannot sign it off: that is the point of a signature.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]))
            ->assertSessionHasErrors('reconciliation');

        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['cash.view', 'bank.view', 'bank.reconcile'])->id);

        $this->actingAs($checker)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]))
            ->assertSessionHasNoErrors();

        $reconciliation->refresh();

        $this->assertSame(BankReconciliation::STATUS_SIGNED_OFF, $reconciliation->status);
        $this->assertSame($checker->id, $reconciliation->signed_off_by);
        $this->assertNotNull($reconciliation->signed_off_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'cash_bank.reconciliation_signed_off']);
    }

    public function test_naming_a_leftover_by_hand_restates_the_lists_and_not_the_arithmetic(): void
    {
        $bank = $this->bank();
        $from = $this->monthStart();
        $to = $this->monthEnd();

        $this->receive($bank, '1000.00', $from->toDateString());

        // The bank records this deposit ten days later — outside the seven-day
        // amount rule — and takes its own charge, which no book line explains.
        $late = $from->copy()->addDays(10)->toDateString();
        $this->import($bank, $this->statement($late, credit: '1000.00'))->assertSessionHasNoErrors();

        $this->open($bank, $from->toDateString(), $to->toDateString(), '750.00')->assertSessionHasNoErrors();

        $reconciliation = $this->latest($bank);

        // Nothing was paired by the rule, and the period still adds up: the two
        // leftovers of the same size explain each other, and the bank's charge is
        // the only thing left over — which the books have simply not entered yet.
        $this->assertSame(0, $reconciliation->matched_count);
        $this->assertSame(2, $reconciliation->unmatched_statement_count);
        $this->assertSame(1, $reconciliation->unmatched_book_count);
        $this->assertSame(0, bccomp((string) $reconciliation->difference, '0.0000', 4));
        $this->assertTrue($reconciliation->isProved());

        $bookLine = $this->frozen($reconciliation, ReconciliationLine::SIDE_BOOK);
        $depositLine = $this->frozenByAmount($reconciliation, ReconciliationLine::SIDE_STATEMENT, '1000.0000');
        $chargeLine = $this->frozenByAmount($reconciliation, ReconciliationLine::SIDE_STATEMENT, '-250.0000');

        // Not the same money: refused, with the two amounts named.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.match', ['reconciliation' => $reconciliation->id]), [
                'book_line_id' => $bookLine->id,
                'statement_line_id' => $chargeLine->id,
            ])
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame(0, $reconciliation->refresh()->matched_count);

        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.match', ['reconciliation' => $reconciliation->id]), [
                'book_line_id' => $bookLine->id,
                'statement_line_id' => $depositLine->id,
            ])
            ->assertSessionHasNoErrors();

        $reconciliation->refresh();

        $this->assertSame(1, $reconciliation->matched_count);
        $this->assertSame(1, $reconciliation->unmatched_statement_count);
        $this->assertSame(0, $reconciliation->unmatched_book_count);
        $this->assertSame(0, bccomp((string) $reconciliation->difference, '0.0000', 4), 'naming a leftover does not move the arithmetic');
        $this->assertNotNull($this->line($bank, 'credit')->matched_journal_line_id);

        // Undo it: the pair goes back to its own sides and the line is free again.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.unmatch', ['reconciliation' => $reconciliation->id]), [
                'line_id' => $depositLine->id,
            ])
            ->assertSessionHasNoErrors();

        $reconciliation->refresh();

        $this->assertSame(0, $reconciliation->matched_count);
        $this->assertSame(2, $reconciliation->unmatched_statement_count);
        $this->assertSame(1, $reconciliation->unmatched_book_count);
        $this->assertSame(0, bccomp((string) $reconciliation->difference, '0.0000', 4));
        $this->assertNull($this->line($bank, 'credit')->matched_journal_line_id);
    }

    public function test_a_period_whose_opening_disagrees_cannot_be_signed_off(): void
    {
        $bank = $this->bank();
        $from = $this->monthStart();
        $to = $this->monthEnd();

        // 400 of this account's money was recorded last month and the bank's
        // statement for this period opens without it: a hole in the earlier period.
        $this->receive($bank, '400.00', $this->monthStart()->subDay()->toDateString());
        $this->receive($bank, '600.00', $from->toDateString());

        $this->import($bank, $this->statement($from->copy()->addDay()->toDateString(), credit: '600.00'))->assertSessionHasNoErrors();

        $this->open($bank, $from->toDateString(), $to->toDateString(), '600.00')->assertSessionHasNoErrors();

        $reconciliation = $this->latest($bank);

        // The proof is exactly the unexplained opening gap: the bank opened at
        // 0.00, the books carried 400.00 that its statement has never seen.
        $this->assertSame(0, bccomp((string) $reconciliation->statement_opening, '0.0000', 4));
        $this->assertSame(0, bccomp((string) $reconciliation->book_opening, '400.0000', 4));
        $this->assertSame(0, bccomp((string) $reconciliation->difference, '-400.0000', 4));
        $this->assertSame(BankReconciliation::STATUS_OPEN, $reconciliation->status);
        $this->assertFalse($reconciliation->isProved());

        // Nobody signs this — not the preparer, and not a fresh pair of eyes.
        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['cash.view', 'bank.view', 'bank.reconcile'])->id);

        $this->actingAs($checker)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]))
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame(BankReconciliation::STATUS_OPEN, $reconciliation->refresh()->status);

        $this->actingAs($this->admin)
            ->get(route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id]))
            ->assertOk()
            ->assertSee('400.00')
            ->assertSee('does not explain the whole gap');
    }

    public function test_the_closing_figure_can_be_corrected_while_the_period_is_open(): void
    {
        $bank = $this->bank();
        $from = $this->monthStart();
        $to = $this->monthEnd();

        $this->receive($bank, '1000.00', $from->toDateString());
        $this->import($bank, $this->statement($from->copy()->addDay()->toDateString()))->assertSessionHasNoErrors();

        // The clerk read the balance off the wrong row.
        $this->open($bank, $from->toDateString(), $to->toDateString(), '1200.00')->assertSessionHasNoErrors();

        $reconciliation = $this->latest($bank);

        $this->assertSame(0, bccomp((string) $reconciliation->difference, '200.0000', 4));
        $this->assertFalse($reconciliation->isProved());

        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]))
            ->assertSessionHasErrors('reconciliation');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.restate', ['reconciliation' => $reconciliation->id]), [
                'statement_closing' => '1000.00',
            ])
            ->assertSessionHasNoErrors();

        $reconciliation->refresh();

        $this->assertSame(0, bccomp((string) $reconciliation->difference, '0.0000', 4));
        $this->assertTrue($reconciliation->isProved());
        $this->assertSame(BankReconciliation::STATUS_BALANCED, $reconciliation->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'cash_bank.reconciliation_restated']);

        // The lines that were compared did not move: only the typed figure did.
        $this->assertSame(2, ReconciliationLine::query()->where('reconciliation_id', $reconciliation->id)->count());

        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['cash.view', 'bank.view', 'bank.reconcile'])->id);

        $this->actingAs($checker)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]))
            ->assertSessionHasNoErrors();

        // And once signed off, the figure is history like everything else.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.restate', ['reconciliation' => $reconciliation->id]), [
                'statement_closing' => '9999.00',
            ])
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame(0, bccomp((string) $reconciliation->refresh()->statement_closing, '1000.0000', 4));
    }

    public function test_a_signed_off_period_is_history(): void
    {
        $bank = $this->bank();
        $from = $this->monthStart();
        $to = $this->monthEnd();

        $this->receive($bank, '700.00', $from->toDateString());
        $this->import($bank, $this->statement($from->copy()->addDay()->toDateString(), credit: '700.00', charge: false))
            ->assertSessionHasNoErrors();

        $payload = [
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'statement_closing' => '700.00',
        ];

        $this->open($bank, $from->toDateString(), $to->toDateString(), '700.00')->assertSessionHasNoErrors();

        $first = $this->latest($bank);

        $this->assertTrue($first->isProved());

        $checker = $this->makeUser();
        $checker->roles()->attach($this->roleWith(['cash.view', 'bank.view', 'bank.reconcile'])->id);

        $this->actingAs($checker)
            ->post(route('cash-bank.reconciliations.sign-off', ['reconciliation' => $first->id]))
            ->assertSessionHasNoErrors();

        // Nothing inside a signed-off reconciliation moves any more.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.unmatch', ['reconciliation' => $first->id]), [
                'line_id' => $this->frozen($first->refresh(), ReconciliationLine::SIDE_STATEMENT, matched: true)->id,
            ])
            ->assertSessionHasErrors('reconciliation');

        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconciliations.match', ['reconciliation' => $first->id]), [
                'book_line_id' => $this->frozen($first, ReconciliationLine::SIDE_BOOK, matched: true)->id,
                'statement_line_id' => $this->frozen($first, ReconciliationLine::SIDE_STATEMENT, matched: true)->id,
            ])
            ->assertSessionHasErrors('reconciliation');

        // A later reconciliation does not pick the closed period's lines up again:
        // they were accounted for there, and counting them twice would reconcile
        // the same money twice.
        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconcile.open', ['account' => $bank->id]), $payload)
            ->assertSessionHasNoErrors();

        $second = $this->latest($bank);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, ReconciliationLine::query()
            ->where('reconciliation_id', $second->id)
            ->where('side', ReconciliationLine::SIDE_STATEMENT)
            ->count());

        // The book line is still there, on its own now: the books hold money the
        // bank's closed period already explained.
        $this->assertSame(1, ReconciliationLine::query()
            ->where('reconciliation_id', $second->id)
            ->where('side', ReconciliationLine::SIDE_BOOK)
            ->count());
    }

    // ------------------------------------------------------------ the wallet

    public function test_a_wallet_is_reconciled_under_its_own_key_and_the_screen_says_no_api_is_connected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.accounts.store'), [
                'instrument' => 'wallet', 'code' => '1131', 'name' => 'bKash merchant float',
                'wallet_provider' => 'bkash', 'account_number' => '01711000000',
            ])
            ->assertSessionHasNoErrors();

        $wallet = $this->account('1131');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.wallets'))
            ->assertOk()
            ->assertSee('bKash merchant float');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.wallets.statement', ['account' => $wallet->id]))
            ->assertOk()
            ->assertSee('No provider API is connected')
            ->assertSee('bKash');

        // The bank desk will not pretend a wallet is a bank account, and a cash
        // drawer is counted rather than reconciled against anybody's statement.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.statement', ['account' => $wallet->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('cash-bank.statement', ['account' => $this->account('1110')->id]))
            ->assertNotFound();

        // Declaring a wallet and reconciling one are different powers.
        $walletClerk = $this->makeUser();
        $walletClerk->roles()->attach($this->roleWith(['cash.view', 'wallets.accounts', 'wallets.reconcile'])->id);

        $this->actingAs($walletClerk)
            ->post(route('cash-bank.wallets.statement.import', ['account' => $wallet->id]), [
                'file' => UploadedFile::fake()->createWithContent('bkash.csv', $this->statement(charge: false)),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->lines($wallet)->count());

        // A wallet reconciliation is opened on the wallet desk, by its own key.
        $this->actingAs($walletClerk)
            ->post(route('cash-bank.wallets.reconcile.open', ['account' => $wallet->id]), [
                'period_start' => $this->monthStart()->toDateString(),
                'period_end' => $this->monthEnd()->toDateString(),
                'statement_closing' => '5000.00',
            ])
            ->assertSessionHasNoErrors();

        $reconciliation = BankReconciliation::query()
            ->where('account_id', $wallet->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($wallet->id, $reconciliation->account_id);
        $this->assertTrue($reconciliation->isProved(), 'the wallet statement explains the whole balance');

        $bankClerk = $this->makeUser();
        $bankClerk->roles()->attach($this->roleWith(['cash.view', 'bank.view', 'bank.reconcile'])->id);

        $this->actingAs($bankClerk)->get(route('cash-bank.wallets'))->assertForbidden();
        $this->actingAs($bankClerk)->get(route('cash-bank.wallets.statement', ['account' => $wallet->id]))->assertForbidden();
        $this->actingAs($bankClerk)
            ->post(route('cash-bank.wallets.statement.import', ['account' => $wallet->id]), [
                'file' => UploadedFile::fake()->createWithContent('bkash.csv', $this->statement(charge: false)),
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- the edges

    public function test_a_ledger_account_that_is_not_a_money_account_is_not_reconciled(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreign = Account::query()->create([
            'company_id' => $otherCompany,
            'code' => '1121',
            'name' => 'Someone else’s bank',
            'type' => 'asset',
            'is_group' => false,
            'is_active' => true,
            'is_bank' => true,
            'instrument' => 'bank',
            'bank_name' => 'Another bank',
            'currency' => 'BDT',
        ]);

        // Another company's account is not found, not refused — it does not exist
        // as far as this company's desk is concerned.
        $this->actingAs($this->admin)
            ->get(route('cash-bank.statement', ['account' => $foreign->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('cash-bank.reconcile.open', ['account' => $foreign->id]), [
                'period_start' => $this->monthStart()->toDateString(),
                'period_end' => $this->monthEnd()->toDateString(),
                'statement_closing' => '0.00',
            ])
            ->assertNotFound();

        // And an expense account is not a money account, however postable it is.
        $salaries = $this->account('5210');

        $this->actingAs($this->admin)
            ->get(route('cash-bank.statement', ['account' => $salaries->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('cash-bank.reconcile', ['account' => $salaries->id]))
            ->assertNotFound();
    }

    public function test_an_empty_period_is_refused_rather_than_opened(): void
    {
        $bank = $this->bank();

        // Nothing posted, nothing imported: there is no argument to make.
        $this->open($bank, $this->monthStart()->subMonths(3)->startOfMonth()->toDateString(),
            $this->monthStart()->subMonths(3)->endOfMonth()->toDateString(), '0.00')
            ->assertSessionHasErrors('reconciliation');

        $this->assertSame(0, BankReconciliation::query()->where('account_id', $bank->id)->count());
    }

    // ------------------------------------------------------------------ helpers

    /** A bank account declared through the desk, as an operator would. */
    protected function bank(): Account
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
            ->assertSessionHasNoErrors();

        return $this->account('1121');
    }

    protected function account(string $code): Account
    {
        return Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();
    }

    /** A real receipt through the cash desk, so the book lines are posted ones. */
    protected function receive(Account $account, string $amount, string $on): void
    {
        $this->actingAs($this->admin)
            ->post(route('cash-bank.receipts.store'), [
                'money_account_id' => $account->id,
                'counter_account_id' => $this->account('4200')->id,
                'amount' => $amount,
                'moved_on' => $on,
                'party_name' => 'Rahmania Store',
                'narration' => 'Counter sales settled to the bank',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, JournalEntry::query()
            ->whereDate('entry_date', $on)
            ->where('posting_state', JournalEntry::STATE_POSTED)
            ->count());
    }

    /**
     * A statement in the bank's own shape: a brought-forward row, a deposit, and
     * — when the bank charges for the quarter — a debit that no book line explains.
     */
    protected function statement(
        ?string $creditOn = null,
        string $credit = '5000.00',
        bool $charge = true,
        string $chargeAmount = '250.00',
        string $chargedAs = '',
    ): string {
        $creditOn ??= $this->monthStart()->toDateString();
        $chargeOn = Carbon::parse($creditOn)->addDays(3)->toDateString();

        $rows = [
            ['Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'],
            [$creditOn, 'Opening balance', '', '', '', '0.00'],
            [$creditOn, 'Transfer received — Rahmania Store', '', '', $credit, number_format((float) $credit, 2, '.', '')],
        ];

        if ($charge) {
            $rows[] = [
                $chargeOn,
                'Bank charge — quarterly',
                '',
                $chargedAs !== '' ? $chargedAs : $chargeAmount,
                '',
                number_format((float) $credit - (float) ($chargedAs !== '' ? 0 : $chargeAmount), 2, '.', ''),
            ];
        }

        return implode("\n", array_map(fn (array $row) => implode(',', $row), $rows));
    }

    protected function import(Account $account, string $csv): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('cash-bank.statement.import', ['account' => $account->id]), [
                'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
            ]);
    }

    protected function open(Account $account, string $from, string $to, string $closing): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('cash-bank.reconcile.open', ['account' => $account->id]), [
                'period_start' => $from,
                'period_end' => $to,
                'statement_closing' => $closing,
            ]);
    }

    protected function latest(Account $account): BankReconciliation
    {
        return BankReconciliation::query()
            ->where('account_id', $account->id)
            ->latest('id')
            ->firstOrFail();
    }

    /** The statement line that moved money one way, in the bank's own columns. */
    protected function line(Account $account, string $column): BankStatementLine
    {
        return BankStatementLine::query()
            ->where('account_id', $account->id)
            ->where($column, '>', 0)
            ->orderBy('value_date')
            ->orderBy('id')
            ->firstOrFail();
    }

    /** The frozen statement lines read for one account, in the bank's date order. */
    protected function lines(Account $account): \Illuminate\Database\Eloquent\Collection
    {
        return BankStatementLine::query()
            ->where('account_id', $account->id)
            ->orderBy('value_date')
            ->orderBy('id')
            ->get();
    }

    protected function frozen(BankReconciliation $reconciliation, string $side, bool $matched = false, bool $second = false): ReconciliationLine
    {
        $query = ReconciliationLine::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->where('side', $side)
            ->where('state', $matched ? ReconciliationLine::STATE_MATCHED : ReconciliationLine::STATE_UNMATCHED)
            ->orderBy('id');

        return $second ? $query->skip(1)->firstOrFail() : $query->firstOrFail();
    }

    protected function frozenByAmount(BankReconciliation $reconciliation, string $side, string $amount): ReconciliationLine
    {
        return ReconciliationLine::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->where('side', $side)
            ->where('amount', $amount)
            ->firstOrFail();
    }

    protected function monthStart(): Carbon
    {
        return now()->startOfMonth();
    }

    protected function monthEnd(): Carbon
    {
        return now()->endOfMonth();
    }
}
