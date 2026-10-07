<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\RunningBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ledger projections over journal_lines (§7.4). journal_lines is the
 * source of truth; running_balances is a rebuildable display cache.
 */
class LedgerService
{
    /**
     * General ledger for one account within an optional date window.
     * Returns ordered rows with a per-row running balance.
     *
     * @return array{rows: array<int, array<string, mixed>>, debit_total: string, credit_total: string, balance: string}
     */
    public function accountLedger(
        Account $account,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $branchId = null,
    ): array {
        $query = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('entry', function ($q) use ($from, $to, $branchId) {
                $q->where('posting_state', JournalEntry::STATE_POSTED)
                    ->when($from, fn ($qq) => $qq->whereDate('entry_date', '>=', $from))
                    ->when($to, fn ($qq) => $qq->whereDate('entry_date', '<=', $to))
                    ->when($branchId !== null, fn ($qq) => $qq->where('branch_id', $branchId));
            })
            ->with('entry')
            ->orderBy('entry_date')
            ->orderBy('journal_entry_id')
            ->orderBy('line_no');

        // Opening balance = all posted lines before $from
        $openingDebit = '0.0000';
        $openingCredit = '0.0000';

        if ($from !== null) {
            $opening = JournalLine::query()
                ->where('account_id', $account->id)
                ->whereHas('entry', function ($q) use ($from, $branchId) {
                    $q->where('posting_state', JournalEntry::STATE_POSTED)
                        ->whereDate('entry_date', '<', $from)
                        ->when($branchId !== null, fn ($qq) => $qq->where('branch_id', $branchId));
                })
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN dc = 'debit' THEN amount ELSE 0 END), 0) as d,
                     COALESCE(SUM(CASE WHEN dc = 'credit' THEN amount ELSE 0 END), 0) as c",
                )
                ->first();

            $openingDebit = number_format((float) ($opening->d ?? 0), 4, '.', '');
            $openingCredit = number_format((float) ($opening->c ?? 0), 4, '.', '');
        }

        $running = (float) $openingDebit - (float) $openingCredit;
        $debitTotal = (float) $openingDebit;
        $creditTotal = (float) $openingCredit;

        $rows = [[
            'label' => 'Opening balance',
            'entry_date' => $from?->toDateString(),
            'entry_no' => null,
            'description' => 'Brought forward',
            'dc' => null,
            'amount' => null,
            'debit' => $openingDebit,
            'credit' => $openingCredit,
            'running_balance' => number_format($running, 4, '.', ''),
            'is_opening' => true,
        ]];

        foreach ($query->get() as $line) {
            $amount = (float) $line->amount;
            $isDebit = $line->isDebit();

            $debitTotal += $isDebit ? $amount : 0;
            $creditTotal += $isDebit ? 0 : $amount;
            $running += $isDebit ? $amount : -$amount;

            $rows[] = [
                'label' => $line->entry?->entry_no,
                'entry_date' => $line->entry?->entry_date?->toDateString(),
                'entry_no' => $line->entry?->entry_no,
                'journal_entry_id' => $line->journal_entry_id,
                'description' => $line->narration ?: ($line->entry?->description ?? ''),
                'dc' => $line->dc,
                'amount' => $line->amount,
                'debit' => $isDebit ? $line->amount : '0.0000',
                'credit' => $isDebit ? '0.0000' : $line->amount,
                'running_balance' => number_format($running, 4, '.', ''),
                'is_opening' => false,
            ];
        }

        // For credit-normal accounts expose natural balance on the summary
        $balance = $account->isDebitNormal()
            ? number_format($running, 4, '.', '')
            : number_format(-$running, 4, '.', '');

        return [
            'rows' => $rows,
            'debit_total' => number_format($debitTotal, 4, '.', ''),
            'credit_total' => number_format($creditTotal, 4, '.', ''),
            'balance' => $balance,
        ];
    }

    /**
     * Trial balance across all postable accounts as at $asAt (or all posted history).
     * D = C is asserted by the caller (TrialBalanceService / tests).
     * Uses raw debit/credit sums — never natural-side netting, which would
     * break the ΣD = ΣC invariant.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool}
     */
    public function trialBalance(?Carbon $asAt = null, ?int $branchId = null): array
    {
        $rows = [];
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        $accounts = Account::query()
            ->postable()
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $agg = JournalLine::query()
                ->where('account_id', $account->id)
                ->whereHas('entry', function ($q) use ($asAt, $branchId) {
                    $q->where('posting_state', JournalEntry::STATE_POSTED)
                        ->when($asAt, fn ($qq) => $qq->whereDate('entry_date', '<=', $asAt))
                        ->when($branchId !== null, fn ($qq) => $qq->where('branch_id', $branchId));
                })
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN dc = 'debit' THEN amount ELSE 0 END), 0) as d,
                     COALESCE(SUM(CASE WHEN dc = 'credit' THEN amount ELSE 0 END), 0) as c",
                )
                ->first();

            $debit = (float) ($agg->d ?? 0);
            $credit = (float) ($agg->c ?? 0);

            if ($debit == 0.0 && $credit == 0.0) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => number_format($debit, 4, '.', ''),
                'credit' => number_format($credit, 4, '.', ''),
                'raw_debit' => number_format($debit, 4, '.', ''),
                'raw_credit' => number_format($credit, 4, '.', ''),
                'natural_balance' => $account->naturalBalance(
                    number_format($debit, 4, '.', ''),
                    number_format($credit, 4, '.', ''),
                ),
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
     * Recompute running_balances from journal_lines (mandatory after
     * backdated postings). Returns rows written.
     */
    public function rebuildRunningBalances(int $companyId): int
    {
        return DB::transaction(function () use ($companyId) {
            $aggregates = JournalLine::query()
                ->where('journal_lines.company_id', $companyId)
                ->whereHas('entry', fn ($q) => $q->where('posting_state', JournalEntry::STATE_POSTED))
                ->leftJoin('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.posting_state', JournalEntry::STATE_POSTED)
                ->groupBy('journal_lines.account_id', 'journal_lines.company_id', 'journal_entries.branch_id', 'journal_lines.currency')
                ->selectRaw(
                    'journal_lines.account_id,
                     journal_lines.company_id,
                     journal_entries.branch_id,
                     journal_lines.currency,
                     SUM(CASE WHEN journal_lines.dc = \'debit\' THEN journal_lines.amount ELSE 0 END) as debit_total,
                     SUM(CASE WHEN journal_lines.dc = \'credit\' THEN journal_lines.amount ELSE 0 END) as credit_total',
                )
                ->get();

            // Reset then rewrite
            RunningBalance::query()->where('company_id', $companyId)->delete();

            foreach ($aggregates as $row) {
                $debit = number_format((float) $row->debit_total, 4, '.', '');
                $credit = number_format((float) $row->credit_total, 4, '.', '');

                RunningBalance::create([
                    'company_id' => $companyId,
                    'account_id' => $row->account_id,
                    'branch_id' => $row->branch_id,
                    'debit_total' => $debit,
                    'credit_total' => $credit,
                    'balance' => number_format((float) $debit - (float) $credit, 4, '.', ''),
                    'currency' => $row->currency,
                    'computed_at' => now(),
                ]);
            }

            return $aggregates->count();
        });
    }
}
