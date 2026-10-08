<?php

namespace App\Domain\Reporting;

use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\Foundation\Branch;
use Illuminate\Support\Facades\DB;

/**
 * The expense report (§08-20) — where the money went, by category, by branch and
 * by period.
 *
 * The one thing this report must never do is disagree with the general ledger,
 * so it counts **posted** expenses only: an expense waiting for a signature has
 * no journal entry (that is the whole design of §08-15), and a reversed one has
 * been answered by another entry, so counting either of them here would produce
 * a total that cannot be tied to an account. The GL agreement is therefore not
 * an assertion made from the report's own numbers — it is checked against
 * `journal_lines` for the accounts the categories point at, and the difference is
 * reported rather than hidden. A non-zero difference is not a rounding artefact;
 * it is a question for whoever posted a journal by hand.
 *
 * Reversals are shown where they land: a reversed expense is not subtracted from
 * its category's total (its reversal is a separate entry, already in the ledger),
 * it is listed as its own fact so the two agree.
 */
class ExpenseReport
{
    /**
     * @param  array{from?:?string, to?:?string, category_id?:?int, branch_id?:?int, settled_with?:?string, q?:?string}  $filters
     * @return array{
     *   from: string, to: string,
     *   rows: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *   by_category: array<int, array<string, mixed>>,
     *   by_month: array<int, array<string, mixed>>,
     *   by_branch: array<int, array<string, mixed>>,
     *   accounts: array<int, array<string, mixed>>,
     *   totals: array<string, mixed>,
     *   ledger: array<string, mixed>,
     *   categories: \Illuminate\Support\Collection<int, ExpenseCategory>,
     *   branches: \Illuminate\Support\Collection<int, Branch>,
     * }
     */
    public function forCompany(int $companyId, array $filters = []): array
    {
        $from = ($filters['from'] ?? null) ?: now()->startOfMonth()->toDateString();
        $to = ($filters['to'] ?? null) ?: now()->toDateString();
        $categoryId = $filters['category_id'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $settledWith = $filters['settled_with'] ?? null;
        $search = $filters['q'] ?? null;

        $posted = $this->posted($companyId, $from, $to, $categoryId, $branchId, $settledWith, $search);

        // `expenses.branch_id` is a plain column, so the branch names come in one
        // query rather than an N+1 behind a relation the model does not carry.
        $branchNames = Branch::query()
            ->where('company_id', $companyId)
            ->pluck('name', 'id');

        $rows = $posted
            ->sortBy([['expense_date', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (Expense $expense): array => [
                'expense_id' => (int) $expense->id,
                'expense_no' => $expense->expense_no,
                'expense_date' => $expense->expense_date?->toDateString(),
                'month' => $expense->expense_date?->format('Y-m'),
                'category' => $expense->category?->name,
                'category_id' => (int) $expense->category_id,
                'account_code' => $expense->categoryAccount()?->code,
                'account_name' => $expense->categoryAccount()?->name,
                'branch' => $expense->branch_id !== null ? ($branchNames[$expense->branch_id] ?? null) : null,
                'branch_id' => $expense->branch_id !== null ? (int) $expense->branch_id : null,
                'payee' => $expense->payee,
                'settled_with' => $expense->settled_with,
                'settled_label' => $expense->settlementLabel(),
                'money_account' => $expense->moneyAccount?->name,
                'narration' => $expense->narration,
                'amount' => round((float) $expense->amount, 4),
                'entry_no' => $expense->journalEntry?->entry_no,
                'status' => $expense->status,
                'is_generated' => $expense->isGenerated(),
            ]);

        $byCategory = $this->group($rows, 'category_id', fn (array $row): array => [
            'category_id' => $row['category_id'],
            'category' => $row['category'],
            'account' => trim(($row['account_code'] ?? '').' — '.($row['account_name'] ?? ''), ' —'),
            'rows' => 0,
            'amount' => 0.0,
            'share' => 0.0,
        ]);

        $byMonth = $this->group($rows, 'month', fn (array $row): array => [
            'month' => $row['month'],
            'rows' => 0,
            'amount' => 0.0,
            'share' => 0.0,
        ]);

        $byBranch = $this->group($rows, 'branch_id', fn (array $row): array => [
            'branch_id' => $row['branch_id'],
            'branch' => $row['branch'] ?? 'Company-wide',
            'rows' => 0,
            'amount' => 0.0,
            'share' => 0.0,
        ]);

        $total = round((float) $rows->sum('amount'), 4);
        $money = round((float) $rows->where('settled_with', Expense::SETTLED_MONEY)->sum('amount'), 4);
        $payable = round((float) $rows->where('settled_with', Expense::SETTLED_PAYABLE)->sum('amount'), 4);

        // What the ledger itself says about the same accounts over the same
        // window. Debits are what an expense posts, so a credit line on one of
        // these accounts (a reversal, or a hand-written correction) is a real
        // difference and is named rather than absorbed.
        $ledger = $this->ledgerCheck($companyId, $from, $to, $byCategory, $branchId);

        $waiting = $this->waiting($companyId, $from, $to, $categoryId, $branchId, $search);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'by_category' => $this->withShares(array_values($byCategory), $total),
            'by_month' => $this->withShares(array_values($byMonth), $total),
            'by_branch' => $this->withShares(array_values($byBranch), $total),
            'accounts' => $ledger['accounts'],
            'totals' => [
                'rows' => $rows->count(),
                'amount' => $total,
                'money' => $money,
                'payable' => $payable,
                'categories' => count($byCategory),
                'branches' => count($byBranch),
                'months' => count($byMonth),
                'biggest' => $rows->sortByDesc('amount')->first(),
                'average' => $rows->count() > 0 ? round($total / $rows->count(), 4) : 0.0,
                'generated' => $rows->where('is_generated', true)->count(),
            ],
            'ledger' => $ledger,
            'waiting' => $waiting,
            'categories' => ExpenseCategory::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(),
            'branches' => Branch::query()
                ->where('company_id', $companyId)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
        ];
    }

    // ------------------------------------------------------------- internals

    /** @return \Illuminate\Support\Collection<int, Expense> */
    protected function posted(
        int $companyId,
        string $from,
        string $to,
        ?int $categoryId,
        ?int $branchId,
        ?string $settledWith,
        ?string $search,
    ) {
        return Expense::query()
            ->where('expenses.company_id', $companyId)
            ->where('expenses.status', Expense::STATUS_POSTED)
            /*
             * `whereDate`, not `whereBetween`: `expense_date` is a date column
             * and a stored day reads back as `2026-10-08 00:00:00`, so a window
             * ending `'2026-10-08'` would sort *before* today's own row and
             * quietly drop the last day of every range — including "today" on a
             * report whose window usually ends today.
             */
            ->whereDate('expenses.expense_date', '>=', $from)
            ->whereDate('expenses.expense_date', '<=', $to)
            ->when($categoryId !== null, fn ($query) => $query->where('expenses.category_id', $categoryId))
            ->when($branchId !== null, fn ($query) => $query->where('expenses.branch_id', $branchId))
            ->when($settledWith !== null, fn ($query) => $query->where('expenses.settled_with', $settledWith))
            ->when($search !== null, fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('expenses.expense_no', 'like', '%'.$search.'%')
                    ->orWhere('expenses.payee', 'like', '%'.$search.'%')
                    ->orWhere('expenses.narration', 'like', '%'.$search.'%');
            }))
            ->with(['category.account', 'moneyAccount', 'journalEntry'])
            ->get();
    }

    /** The expenses that are recorded but not in the ledger yet — the report's footnote. */
    protected function waiting(
        int $companyId,
        string $from,
        string $to,
        ?int $categoryId,
        ?int $branchId,
        ?string $search,
    ): array {
        $query = Expense::query()
            ->where('expenses.company_id', $companyId)
            ->where('expenses.status', Expense::STATUS_PENDING)
            ->whereDate('expenses.expense_date', '>=', $from)
            ->whereDate('expenses.expense_date', '<=', $to)
            ->when($categoryId !== null, fn ($query) => $query->where('expenses.category_id', $categoryId))
            ->when($branchId !== null, fn ($query) => $query->where('expenses.branch_id', $branchId))
            ->when($search !== null, fn ($query) => $query->where(function ($inner) use ($search) {
                $inner->where('expenses.expense_no', 'like', '%'.$search.'%')
                    ->orWhere('expenses.payee', 'like', '%'.$search.'%');
            }));

        return [
            'rows' => (clone $query)->count(),
            'amount' => round((float) (clone $query)->sum('amount'), 4),
            'reversed' => round((float) Expense::query()
                ->where('company_id', $companyId)
                ->where('status', Expense::STATUS_REVERSED)
                ->whereDate('expense_date', '>=', $from)
                ->whereDate('expense_date', '<=', $to)
                ->sum('amount'), 4),
        ];
    }

    /**
     * The accounts the categories point at, with what the report counted and what
     * the ledger carries. The difference is the point of the table.
     *
     * @param  array<int|string, array<string, mixed>>  $byCategory
     * @return array{accounts: array<int, array<string, mixed>>, reported: float, ledger: float, difference: float, agrees: bool}
     */
    protected function ledgerCheck(int $companyId, string $from, string $to, array $byCategory, ?int $branchId): array
    {
        $categories = ExpenseCategory::query()
            ->whereIn('id', array_column($byCategory, 'category_id'))
            ->with('account')
            ->get()
            ->keyBy('id');

        $accountIds = $categories->pluck('account_id')->unique()->values()->all();

        $lines = $accountIds === [] ? collect() : DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)
            ->whereIn('l.account_id', $accountIds)
            ->where('e.posting_state', 'posted')
            ->whereDate('e.entry_date', '>=', $from)
            ->whereDate('e.entry_date', '<=', $to)
            ->when($branchId !== null, fn ($query) => $query->where('e.branch_id', $branchId))
            ->groupBy('l.account_id')
            ->selectRaw("l.account_id,
                COALESCE(SUM(CASE WHEN l.dc = 'debit' THEN l.amount ELSE 0 END), 0) as debit,
                COALESCE(SUM(CASE WHEN l.dc = 'credit' THEN l.amount ELSE 0 END), 0) as credit")
            ->get()
            ->keyBy('account_id');

        $accounts = [];
        $reported = 0.0;
        $ledger = 0.0;

        foreach ($byCategory as $row) {
            $category = $categories[$row['category_id']] ?? null;

            if ($category === null) {
                continue;
            }

            $debit = (float) ($lines[$category->account_id]->debit ?? 0);
            $credit = (float) ($lines[$category->account_id]->credit ?? 0);
            $reported += (float) $row['amount'];
            $ledger += $debit - $credit;

            $accounts[] = [
                'account_id' => (int) $category->account_id,
                'account' => trim(((string) $category->account?->code).' — '.((string) $category->account?->name), ' —'),
                'category' => $category->name,
                'reported' => round((float) $row['amount'], 4),
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
                'ledger' => round($debit - $credit, 4),
                'difference' => round((float) $row['amount'] - ($debit - $credit), 4),
            ];
        }

        $reported = round($reported, 4);
        $ledger = round($ledger, 4);

        return [
            'accounts' => $accounts,
            'reported' => $reported,
            'ledger' => $ledger,
            'difference' => round($reported - $ledger, 4),
            'agrees' => abs($reported - $ledger) < 0.00005,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<int|string, array<string, mixed>>
     */
    protected function group($rows, string $key, callable $shape): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groupKey = $row[$key] ?? '—';

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = $shape($row);
            }

            $groups[$groupKey]['rows']++;
            $groups[$groupKey]['amount'] = round($groups[$groupKey]['amount'] + $row['amount'], 4);
        }

        return $groups;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function withShares(array $groups, float $total): array
    {
        usort($groups, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return array_map(function (array $group) use ($total): array {
            $group['share'] = $total > 0 ? round(($group['amount'] / $total) * 100, 1) : 0.0;

            return $group;
        }, $groups);
    }
}
