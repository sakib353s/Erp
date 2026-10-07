<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use Illuminate\Support\Carbon;

/**
 * Trial balance gate: Σdebits = Σcredits across all posted lines (§8.2
 * DOUBLE-ENTRY-01). Exposed as a report AND used as a reconciliation
 * assertion in tests.
 */
class TrialBalanceService
{
    public function __construct(protected LedgerService $ledger) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool}
     */
    public function build(?Carbon $asAt = null, ?int $branchId = null): array
    {
        return $this->ledger->trialBalance($asAt, $branchId);
    }

    /**
     * Opening trial balance: only OPENING-type journal entries (09-02).
     *
     * @return array{total_debit: string, total_credit: string, is_balanced: bool, rows: array<int, array<string, mixed>>}
     */
    public function opening(?Carbon $asAt = null): array
    {
        $rows = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        $lines = JournalLine::query()
            ->whereHas('entry', function ($q) use ($asAt) {
                $q->where('journal_type', 'opening')
                    ->where('posting_state', JournalEntry::STATE_POSTED)
                    ->when($asAt, fn ($qq) => $qq->whereDate('entry_date', '<=', $asAt));
            })
            ->with('account')
            ->get()
            ->groupBy('account_id');

        foreach ($lines as $accountId => $group) {
            $account = $group->first()->account;
            if ($account === null) {
                continue;
            }

            $debit = $group->filter(fn ($l) => $l->isDebit())->sum(fn ($l) => (float) $l->amount);
            $credit = $group->filter(fn ($l) => $l->isCredit())->sum(fn ($l) => (float) $l->amount);

            $rows[] = [
                'account_id' => $accountId,
                'code' => $account->code,
                'name' => $account->name,
                'debit' => number_format($debit, 4, '.', ''),
                'credit' => number_format($credit, 4, '.', ''),
            ];

            $totalDebit = bcadd($totalDebit, number_format($debit, 4, '.', ''), 4);
            $totalCredit = bcadd($totalCredit, number_format($credit, 4, '.', ''), 4);
        }

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => bccomp($totalDebit, $totalCredit, 4) === 0,
        ];
    }

    /**
     * Full-ledger integrity check: every posted entry has D = C, and the
     * grand total across all entries balances. Used by reconciliation.
     *
     * @return array{entries_checked: int, unbalanced_entry_ids: array<int, int>, total_debit: string, total_credit: string, is_balanced: bool}
     */
    public function reconcile(int $companyId): array
    {
        $entries = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('posting_state', JournalEntry::STATE_POSTED)
            ->get();

        $unbalanced = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($entries as $entry) {
            [$d, $c] = $this->lineSums($entry->id);

            $headerD = number_format((float) $entry->total_debit, 4, '.', '');
            $headerC = number_format((float) $entry->total_credit, 4, '.', '');

            if (bccomp($d, $c, 4) !== 0 || bccomp($headerD, $headerC, 4) !== 0
                || bccomp($d, $headerD, 4) !== 0) {
                $unbalanced[] = $entry->id;
            }

            $totalDebit = bcadd($totalDebit, $d, 4);
            $totalCredit = bcadd($totalCredit, $c, 4);
        }

        return [
            'entries_checked' => $entries->count(),
            'unbalanced_entry_ids' => $unbalanced,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $unbalanced === [] && bccomp($totalDebit, $totalCredit, 4) === 0,
        ];
    }

    /** @return array{0: string, 1: string} debit total, credit total for one entry */
    protected function lineSums(int $entryId): array
    {
        $row = JournalLine::query()
            ->where('journal_entry_id', $entryId)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN dc = 'debit' THEN amount ELSE 0 END), 0) as d,
                 COALESCE(SUM(CASE WHEN dc = 'credit' THEN amount ELSE 0 END), 0) as c",
            )
            ->first();

        return [
            number_format((float) ($row->d ?? 0), 4, '.', ''),
            number_format((float) ($row->c ?? 0), 4, '.', ''),
        ];
    }
}
