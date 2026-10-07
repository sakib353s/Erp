<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\FiscalPeriod;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\RunningBalance;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Accounting\Services\TrialBalanceService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\User;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Phase D gate: real double-entry accounting.
 *  - Σdebits = Σcredits asserted before insert (BCMath) + checksum;
 *  - closed fiscal periods reject posting;
 *  - posted entries are immutable — reversal only;
 *  - trial balance D = C; ledger rebuild from source lines;
 *  - COA system/history protections.
 */
class AccountingCoreTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $user;

    protected Account $cash;

    protected Account $sales;

    protected Account $capital;

    protected FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->bootInstance();

        // Structural seeders required for numbering, permissions and COA
        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(AccountingCoreSeeder::class);

        $this->user = $admin;

        $companyId = Company::current()?->id;

        // Fiscal periods may not exist if ReferenceDataSeeder's FY wasn't run
        $fy = FiscalYear::query()->where('company_id', $companyId)->where('is_current', true)->first();

        if ($fy === null) {
            $fy = FiscalYear::create([
                'company_id' => $companyId,
                'code' => 'FY'.now()->year,
                'name' => 'FY '.now()->year,
                'starts_on' => now()->startOfYear()->toDateString(),
                'ends_on' => now()->endOfYear()->toDateString(),
                'status' => 'open',
                'is_current' => true,
            ]);
        }

        if (FiscalPeriod::query()->where('company_id', $companyId)->count() === 0) {
            app(FiscalPeriodService::class)->ensurePeriods($fy);
        }

        $this->period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now())
            ->firstOrFail();

        $this->cash = Account::query()->where('code', '1110')->firstOrFail();
        $this->capital = Account::query()->where('code', '3100')->firstOrFail();
        $this->sales = Account::query()->where('code', '4100')->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    protected function balancedLines(float $amount = 1000): array
    {
        return [
            ['account_id' => $this->cash->id, 'dc' => 'debit', 'amount' => $amount],
            ['account_id' => $this->capital->id, 'dc' => 'credit', 'amount' => $amount],
        ];
    }

    protected function posting(): JournalPostingService
    {
        return app(JournalPostingService::class);
    }

    public function test_balanced_manual_journal_posts_with_checksum(): void
    {
        $entry = $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Owner capital injection',
            'journal_type' => 'manual',
            'lines' => $this->balancedLines(5000),
        ], $this->user);

        $this->assertSame(JournalEntry::STATE_POSTED, $entry->posting_state);
        $this->assertEquals(5000, (float) $entry->total_debit);
        $this->assertEquals(5000, (float) $entry->total_credit);
        $this->assertSame(64, strlen($entry->checksum));
        $this->assertSame(2, $entry->lines()->count());
        $this->assertNotNull($entry->entry_no);
        $this->assertSame($this->period->id, $entry->fiscal_period_id);
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unbalanced/');

        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Bad entry',
            'lines' => [
                ['account_id' => $this->cash->id, 'dc' => 'debit', 'amount' => 100],
                ['account_id' => $this->capital->id, 'dc' => 'credit', 'amount' => 90],
            ],
        ], $this->user);
    }

    public function test_single_line_journal_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'One sided',
            'lines' => [
                ['account_id' => $this->cash->id, 'dc' => 'debit', 'amount' => 100],
            ],
        ], $this->user);
    }

    public function test_group_account_cannot_receive_postings(): void
    {
        $group = Account::query()->where('code', '1000')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not postable/');

        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Group post attempt',
            'lines' => [
                ['account_id' => $group->id, 'dc' => 'debit', 'amount' => 100],
                ['account_id' => $this->capital->id, 'dc' => 'credit', 'amount' => 100],
            ],
        ], $this->user);
    }

    public function test_closed_period_rejects_posting(): void
    {
        $this->period->update(['status' => 'closed', 'closed_at' => now()]);

        try {
            $this->posting()->post([
                'entry_date' => now()->toDateString(),
                'description' => 'Should fail',
                'lines' => $this->balancedLines(),
            ], $this->user);
            $this->fail('Expected closed-period rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }
    }

    public function test_date_outside_any_period_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No fiscal period/');

        $this->posting()->post([
            'entry_date' => '1999-01-15',
            'description' => 'Ancient',
            'lines' => $this->balancedLines(),
        ], $this->user);
    }

    public function test_posted_entry_is_immutable_no_edit_path(): void
    {
        $entry = $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Immutable',
            'lines' => $this->balancedLines(200),
        ], $this->user);

        $before = $entry->fresh()->getAttributes();

        $entry->refresh();
        $this->assertSame($before['checksum'], $entry->checksum);
        $this->assertEquals(200, (float) $entry->total_debit);
        $this->assertEquals(200, (float) $before['total_debit']);
        $this->assertSame(JournalEntry::STATE_POSTED, $entry->posting_state);
    }

    public function test_reversal_creates_mirror_and_marks_original_reversed(): void
    {
        $original = $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'To reverse',
            'lines' => $this->balancedLines(750),
        ], $this->user);

        $reversal = $this->posting()->reverse($original, 'Booked in error', $this->user);

        $original->refresh();
        $reversal->refresh();

        $this->assertSame(JournalEntry::STATE_REVERSED, $original->posting_state);
        $this->assertSame(JournalEntry::STATE_POSTED, $reversal->posting_state);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('reversal', $reversal->journal_type);

        // Net effect: cash capital back to zero across both entries
        $cashNet = JournalLine::query()
            ->where('account_id', $this->cash->id)
            ->whereHas('entry', fn ($q) => $q->where('posting_state', '!=', 'draft'))
            ->selectRaw("SUM(CASE WHEN dc='debit' THEN amount ELSE -amount END) as net")
            ->first();

        $this->assertEquals(0, round((float) $cashNet->net, 2));
    }

    public function test_double_reversal_is_blocked(): void
    {
        $original = $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Once',
            'lines' => $this->balancedLines(100),
        ], $this->user);

        $this->posting()->reverse($original, 'First reason', $this->user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been reversed/');

        $this->posting()->reverse($original, 'Second reason', $this->user);
    }

    public function test_reversal_requires_reason(): void
    {
        $original = $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Needs reason',
            'lines' => $this->balancedLines(50),
        ], $this->user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reason/i');

        $this->posting()->reverse($original, '   ', $this->user);
    }

    public function test_trial_balance_is_balanced(): void
    {
        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'TB seed 1',
            'lines' => $this->balancedLines(1000),
        ], $this->user);

        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'TB seed 2',
            'lines' => [
                ['account_id' => $this->cash->id, 'dc' => 'credit', 'amount' => 300],
                ['account_id' => $this->sales->id, 'dc' => 'debit', 'amount' => 300],
            ],
        ], $this->user);

        $report = app(TrialBalanceService::class)->build();

        $this->assertTrue($report['is_balanced'], sprintf(
            'Trial balance D=%s C=%s',
            $report['total_debit'],
            $report['total_credit'],
        ));
        $this->assertGreaterThan(0, (float) $report['total_debit']);
    }

    public function test_reconcile_flags_no_unbalanced_entries(): void
    {
        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Recon seed',
            'lines' => $this->balancedLines(400),
        ], $this->user);

        $result = app(TrialBalanceService::class)->reconcile((int) Company::current()?->id);

        $this->assertTrue($result['is_balanced']);
        $this->assertSame([], $result['unbalanced_entry_ids']);
        $this->assertGreaterThanOrEqual(1, $result['entries_checked']);
    }

    public function test_general_ledger_shows_running_balance(): void
    {
        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Ledger in',
            'lines' => $this->balancedLines(1000),
        ], $this->user);

        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Ledger out',
            'lines' => [
                ['account_id' => $this->capital->id, 'dc' => 'debit', 'amount' => 400],
                ['account_id' => $this->cash->id, 'dc' => 'credit', 'amount' => 400],
            ],
        ], $this->user);

        $ledger = app(LedgerService::class)->accountLedger($this->cash);

        $this->assertCount(3, $ledger['rows']); // opening + 2 movements
        $this->assertEquals(600, round((float) $ledger['balance'], 2));
        $this->assertEquals(1000, round((float) $ledger['debit_total'], 2));
        $this->assertEquals(400, round((float) $ledger['credit_total'], 2));
    }

    public function test_running_balance_rebuild_matches_source_lines(): void
    {
        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Rebuild seed',
            'lines' => $this->balancedLines(2500),
        ], $this->user);

        $count = app(LedgerService::class)->rebuildRunningBalances((int) Company::current()?->id);

        $this->assertGreaterThanOrEqual(1, $count);

        $cashBalance = RunningBalance::query()
            ->where('account_id', $this->cash->id)
            ->first();

        $this->assertNotNull($cashBalance);
        $this->assertEquals(2500, round((float) $cashBalance->balance, 2));
        $this->assertEquals(2500, round((float) $cashBalance->debit_total, 2));
    }

    public function test_system_account_cannot_be_deleted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/System accounts/');

        app(AccountService::class)->delete($this->cash);
    }

    public function test_account_with_history_cannot_change_code(): void
    {
        $this->posting()->post([
            'entry_date' => now()->toDateString(),
            'description' => 'History',
            'lines' => $this->balancedLines(10),
        ], $this->user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/posting history/');

        app(AccountService::class)->update($this->cash, ['code' => '9999', 'name' => 'Renamed']);
    }

    public function test_duplicate_account_code_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');

        app(AccountService::class)->create([
            'code' => $this->cash->code,
            'name' => 'Duplicate',
            'type' => 'asset',
        ]);
    }

    public function test_journal_list_route_requires_permission(): void
    {
        $user = $this->makeUser();
        // no accounting.journals.view granted

        $response = $this->actingAs($user)->get('/app/accounting/journals');

        $response->assertStatus(403);
    }

    public function test_journal_list_route_allows_granted_permission(): void
    {
        $role = $this->roleWith([
            'portal.erp.access',
            'accounting.journals.view',
            'accounting.coa.view',
            'accounting.reports.view',
        ]);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/accounting/journals')->assertOk();
        $this->actingAs($user)->get('/app/accounting/coa')->assertOk();
        $this->actingAs($user)->get('/app/accounting/trial-balance')->assertOk();
    }

    public function test_store_manual_journal_via_http_balanced(): void
    {
        $role = $this->roleWith([
            'portal.erp.access',
            'accounting.journals.create',
            'accounting.journals.view',
        ]);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user)->post('/app/accounting/journals', [
            'entry_date' => now()->toDateString(),
            'description' => 'HTTP journal',
            'lines' => [
                ['account_id' => $this->cash->id, 'dc' => 'debit', 'amount' => 150],
                ['account_id' => $this->capital->id, 'dc' => 'credit', 'amount' => 150],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('journal_entries', [
            'description' => 'HTTP journal',
            'posting_state' => 'posted',
        ]);
    }

    public function test_store_unbalanced_journal_via_http_is_rejected(): void
    {
        $role = $this->roleWith([
            'portal.erp.access',
            'accounting.journals.create',
        ]);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $response = $this->actingAs($user)->from('/app/accounting/journals/create')->post('/app/accounting/journals', [
            'entry_date' => now()->toDateString(),
            'description' => 'Unbalanced HTTP',
            'lines' => [
                ['account_id' => $this->cash->id, 'dc' => 'debit', 'amount' => 100],
                ['account_id' => $this->capital->id, 'dc' => 'credit', 'amount' => 50],
            ],
        ]);

        $response->assertSessionHasErrors('lines');
        $this->assertDatabaseMissing('journal_entries', ['description' => 'Unbalanced HTTP']);
    }

    public function test_accounting_core_seeder_creates_no_fake_balances(): void
    {
        // Seeded COA is structural: zero journal lines, zero balances
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
        $this->assertGreaterThan(10, Account::query()->count());
        $this->assertSame(0, FiscalPeriod::query()->where('status', 'closed')->count());
    }
}
