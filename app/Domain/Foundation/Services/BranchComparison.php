<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Business\BusinessAsset;
use App\Domain\Business\Task;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Sales\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §12-07 — one branch beside another.
 *
 * A branch comparison is only worth reading if every figure in it is the same
 * figure the branch's own screens show, so nothing here is computed a second
 * way: sales come from the invoice table the sales reports read, stock value
 * from the layers the valuation reads, the money line from posted journal
 * lines on the accounts cash and bank actually sit in, book value from the
 * asset register, and headcount from the employees table. Nothing is
 * estimated, and a branch that has never traded says so rather than showing a
 * zero that looks like a measurement.
 *
 * The period is a month. A comparison is a planning tool — "how did Uttara do
 * against Motijheel" — and the month is the unit everybody in the room is
 * holding in their head. The window is closed at both ends with day
 * comparisons, because a stored date reads back with a time on it.
 */
class BranchComparison
{
    public function __construct(
        protected TenantContext $context,
        protected MoneyAccountService $money,
    ) {}

    /**
     * The branches this reader may stand side by side: their own company's,
     * and — for a branch-scoped user — only the ones they can open. A caller
     * that wants the unreachable ones refused outright checks `isWholeCompany()`
     * first; this method is the filter, not the gate.
     */
    public function branches(?User $reader = null): Collection
    {
        $query = Branch::query()
            ->where('company_id', (int) $this->context->companyId())
            ->orderBy('name');

        $ids = $reader?->accessibleBranchIds() ?? $this->context->accessibleBranchIds();

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /** A comparison only means something to somebody who can see every branch. */
    public function isWholeCompany(?User $reader = null): bool
    {
        $ids = $reader?->accessibleBranchIds() ?? $this->context->accessibleBranchIds();

        return $ids === null;
    }

    /** The month the screen is showing, from `?month=YYYY-MM`. */
    public function period(?string $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }

    /**
     * Every measure, for every branch, for one month.
     *
     * @return array{from: Carbon, to: Carbon, rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>, trading: int}
     */
    public function compare(?string $month = null, ?User $reader = null): array
    {
        $from = $this->period($month);
        $to = $from->copy()->endOfMonth();
        $monthKey = $from->format('Y-m');

        $branches = $this->branches($reader);

        $sales = $this->salesByBranch($from, $to);
        $purchases = $this->purchasesByBranch($from, $to);
        $stock = $this->stockValueByBranch();
        $book = $this->bookValueByBranch();
        $money = $this->moneyByBranch($to);
        $headcount = $this->headcountByBranch();
        $tasks = $this->openTasksByBranch();

        $rows = $branches->map(function (Branch $branch) use ($sales, $purchases, $stock, $book, $money, $headcount, $tasks, $to): array {
            $id = (int) $branch->id;

            $sold = $sales[$id] ?? ['documents' => 0, 'total' => 0.0, 'due' => 0.0];

            return [
                'branch' => $branch,
                'sales' => round((float) $sold['total'], 2),
                'invoices' => (int) $sold['documents'],
                'receivable' => round((float) $sold['due'], 2),
                'purchases' => round((float) ($purchases[$id]['total'] ?? 0), 2),
                'bills' => (int) ($purchases[$id]['documents'] ?? 0),
                'stock' => round((float) ($stock[$id] ?? 0), 2),
                'book' => round((float) ($book[$id] ?? 0), 2),
                'money' => round((float) ($money[$id] ?? 0), 2),
                'people' => (int) ($headcount[$id] ?? 0),
                'tasks' => (int) ($tasks[$id] ?? 0),
                // A branch with nothing anywhere in the month is not a branch
                // that did badly; it is a branch nobody has used yet.
                'dormant' => (float) $sold['total'] === 0.0
                    && (float) ($purchases[$id]['total'] ?? 0) === 0.0
                    && (int) ($tasks[$id] ?? 0) === 0,
            ];
        })->sortByDesc('sales')->values();

        $totals = [
            'sales' => round((float) $rows->sum('sales'), 2),
            'invoices' => (int) $rows->sum('invoices'),
            'receivable' => round((float) $rows->sum('receivable'), 2),
            'purchases' => round((float) $rows->sum('purchases'), 2),
            'stock' => round((float) $rows->sum('stock'), 2),
            'book' => round((float) $rows->sum('book'), 2),
            'money' => round((float) $rows->sum('money'), 2),
            'people' => (int) $rows->sum('people'),
            'tasks' => (int) $rows->sum('tasks'),
        ];

        return [
            'from' => $from,
            'to' => $to,
            'month' => $monthKey,
            'rows' => $rows,
            'totals' => $totals,
            'trading' => $rows->where('sales', '>', 0)->count(),
        ];
    }

    /* ------------------------------------------------------------------ the measures */

    /** Issued invoices — the same three statuses the sales reports count. */
    protected function salesByBranch(Carbon $from, Carbon $to): array
    {
        return Invoice::query()
            ->where('company_id', (int) $this->context->companyId())
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as documents, COALESCE(SUM(grand_total), 0) as total, COALESCE(SUM(due_amount), 0) as due')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id)
            ->map(fn ($row) => ['documents' => (int) $row->documents, 'total' => (float) $row->total, 'due' => (float) $row->due])
            ->all();
    }

    /** Posted bills only: a draft has not reached the ledger, so it is not a fact yet. */
    protected function purchasesByBranch(Carbon $from, Carbon $to): array
    {
        return PurchaseBill::query()
            ->where('company_id', (int) $this->context->companyId())
            ->where('posting_state', 'posted')
            ->whereDate('bill_date', '>=', $from->toDateString())
            ->whereDate('bill_date', '<=', $to->toDateString())
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as documents, COALESCE(SUM(total), 0) as total')
            ->get()
            ->keyBy(fn ($row) => (int) $row->branch_id)
            ->map(fn ($row) => ['documents' => (int) $row->documents, 'total' => (float) $row->total])
            ->all();
    }

    /**
     * Stock on hand at what it cost, by the branch that holds it.
     *
     * Stock layers carry a warehouse, not a branch — a warehouse is where the
     * goods are, and the branch is who they belong to — so the join is the
     * answer, not an approximation.
     */
    protected function stockValueByBranch(): array
    {
        return DB::table('stock_layers as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.company_id', (int) $this->context->companyId())
            ->where('l.qty_remaining', '>', 0)
            ->whereNotNull('w.branch_id')
            ->groupBy('w.branch_id')
            ->selectRaw('w.branch_id, COALESCE(SUM(l.qty_remaining * l.unit_cost), 0) as value')
            ->pluck('value', 'branch_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /** What the register carries: cost less what has been depreciated. */
    protected function bookValueByBranch(): array
    {
        return BusinessAsset::query()
            ->where('company_id', (int) $this->context->companyId())
            ->where('status', '!=', BusinessAsset::STATUS_DISPOSED)
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(COALESCE(acquisition_cost, 0) - COALESCE(accumulated_depreciation, 0)), 0) as value')
            ->pluck('value', 'branch_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * Money on the branch's books: posted movement on the accounts cash, bank
     * and wallets actually sit in, attributed by the entry's branch.
     *
     * Asset accounts are debit-positive, so the balance is debits less credits
     * — the direction is fixed by the account type, not by how the entry was
     * written.
     */
    protected function moneyByBranch(Carbon $asOf): array
    {
        $accountIds = $this->money->accounts()->pluck('id')->all();

        if ($accountIds === []) {
            return [];
        }

        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.account_id', $accountIds)
            ->where('je.posting_state', JournalEntry::STATE_POSTED)
            ->whereDate('je.entry_date', '<=', $asOf->toDateString())
            ->whereNotNull('je.branch_id')
            ->groupBy('je.branch_id')
            ->selectRaw(
                'je.branch_id, COALESCE(SUM(CASE WHEN jl.dc = ? THEN jl.amount ELSE -jl.amount END), 0) as balance',
                [JournalLine::DEBIT],
            )
            ->pluck('balance', 'branch_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    protected function headcountByBranch(): array
    {
        return Employee::query()
            ->where('company_id', (int) $this->context->companyId())
            ->where('status', 'active')
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as people')
            ->pluck('people', 'branch_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /** Work still open: a task that is done or cancelled is history. */
    protected function openTasksByBranch(): array
    {
        return Task::query()
            ->where('company_id', (int) $this->context->companyId())
            ->whereNotIn('status', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->whereNotNull('branch_id')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as open')
            ->pluck('open', 'branch_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /** The months that have anything in them, newest first — the period picker. */
    public function months(int $limit = 12): Collection
    {
        $companyId = (int) $this->context->companyId();

        return Invoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['issued', 'partial', 'paid'])
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->pluck('invoice_date')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->unique()
            ->take($limit)
            ->values()
            ->whenEmpty(fn () => collect([now()->format('Y-m')]));
    }

}
