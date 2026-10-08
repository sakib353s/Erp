<?php

namespace App\Http\Controllers;

use App\Domain\CashBank\Expense;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Branch;
use App\Domain\Reporting\CashReports;
use App\Domain\Reporting\ExpenseReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The §08 report family — 08-20 (expense reports) and 08-22 (cash reports).
 *
 * Five screens, one method each, because each answers one question and reads one
 * service:
 *
 *   GET /app/reports/cash/expenses            where the money went, by category, branch and month
 *   GET /app/reports/cash/book                one money account's book, the way a statement reads
 *   GET /app/reports/cash/bank-book           every bank and wallet side by side
 *   GET /app/reports/cash/flow                where money came from and where it went
 *   GET /app/reports/cash/sessions            what the tills counted against what they held
 *
 * Every one of them exports the rows it is showing — filters included — as CSV,
 * because a report nobody can send to an accountant is half a report.
 */
class CashReportController extends Controller
{
    public function __construct(
        protected ExpenseReport $expenses,
        protected CashReports $cash,
        protected MoneyAccountService $money,
    ) {}

    /* ------------------------------------------------------------ §08-20 */

    public function expenses(Request $request): View|StreamedResponse
    {
        $filters = $this->expenseFilters($request);
        $result = $this->expenses->forCompany($this->companyId($request), $filters);

        if ($this->wantsCsv($request)) {
            return $this->csv('expense-report', $result['rows'], [
                'Expense', 'Date', 'Category', 'Account', 'Branch', 'Payee', 'Paid or owed', 'From', 'Amount', 'Entry',
            ], fn (array $row) => [
                $row['expense_no'],
                $row['expense_date'],
                $row['category'],
                $row['account_code'].' — '.$row['account_name'],
                $row['branch'] ?? 'Company-wide',
                $row['payee'],
                $row['settled_label'],
                $row['money_account'],
                number_format((float) $row['amount'], 2, '.', ''),
                $row['entry_no'],
            ], $this->labels($result['totals'], ['rows' => 'Expenses', 'amount' => 'Total', 'money' => 'Paid out', 'payable' => 'Still owed']));
        }

        return view('cash-bank.reports.expenses', [
            'filters' => $filters,
            'report' => $result,
        ]);
    }

    /* ------------------------------------------------------------ §08-22 */

    /** One account's book — a cash drawer, a bank account or a wallet. */
    public function book(Request $request): View|StreamedResponse
    {
        $accounts = $this->money->accounts();
        $account = $accounts->firstWhere('id', (int) $request->query('account')) ?? $accounts->first();

        abort_if($account === null, 404, 'This company has no money account to report on yet.');

        $from = $this->date($request, 'from', now()->startOfMonth());
        $to = $this->date($request, 'to', now());
        $branchId = $request->filled('branch') ? (int) $request->query('branch') : null;

        $book = $this->cash->book($account, $from, $to, $branchId);

        if ($this->wantsCsv($request)) {
            return $this->csv('cash-book-'.strtolower((string) $account->code), collect($book['rows']), [
                'Date', 'Entry', 'Particulars', 'Debit', 'Credit', 'Balance',
            ], fn (array $row) => [
                $row['entry_date'],
                $row['entry_no'],
                $row['description'],
                (float) $row['debit'],
                (float) $row['credit'],
                $row['running_balance'],
            ], [
                'Account' => $book['label'],
                'Opening' => $book['opening'],
                'Money in' => $book['in'],
                'Money out' => $book['out'],
                'Closing' => $book['closing'],
            ]);
        }

        return view('cash-bank.reports.book', [
            'account' => $account,
            'accounts' => $accounts,
            'book' => $book,
            'filters' => [
                'account' => (int) $account->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $branchId,
            ],
            'branches' => $this->branches($request),
        ]);
    }

    /** Every bank and wallet at once. */
    public function bankBook(Request $request): View|StreamedResponse
    {
        $from = $this->date($request, 'from', now()->startOfMonth());
        $to = $this->date($request, 'to', now());
        $branchId = $request->filled('branch') ? (int) $request->query('branch') : null;

        $result = $this->cash->bankBook($from, $to, $branchId);

        if ($this->wantsCsv($request)) {
            return $this->csv('bank-book', $result['rows'], [
                'Code', 'Account', 'Kind', 'Opening', 'Money in', 'Money out', 'Closing', 'Movements', 'Last movement',
            ], fn (array $row) => [
                $row['account']->code,
                $row['account']->name,
                $row['instrument'],
                (float) $row['opening'],
                (float) $row['in'],
                (float) $row['out'],
                (float) $row['closing'],
                $row['rows'],
                $row['last_movement'],
            ], $this->labels($result['totals'], [
                'opening' => 'Opening', 'in' => 'Money in', 'out' => 'Money out', 'closing' => 'Closing',
            ]));
        }

        return view('cash-bank.reports.bank-book', [
            'result' => $result,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'branch' => $branchId],
            'branches' => $this->branches($request),
        ]);
    }

    /** Where the money came from and where it went. */
    public function flow(Request $request): View|StreamedResponse
    {
        $from = $this->date($request, 'from', now()->startOfMonth());
        $to = $this->date($request, 'to', now());
        $branchId = $request->filled('branch') ? (int) $request->query('branch') : null;

        $result = $this->cash->cashFlow($from, $to, $branchId);

        if ($this->wantsCsv($request)) {
            $rows = $result['inflows']->map(fn (array $row): array => ['in', $row])->toBase()
                ->concat($result['outflows']->map(fn (array $row): array => ['out', $row]))
                ->concat($result['transfers']->map(fn (array $row): array => ['transfer', $row]));

            return $this->csv('cash-flow', $rows, [
                'Direction', 'Counterpart account', 'Account type', 'Rows', 'Amount', 'Share %',
            ], fn (array $row) => [
                $row[0],
                $row[1]['counter_account'],
                $row[1]['account_type'],
                $row[1]['rows'],
                (float) $row[1]['amount'],
                $row[1]['share'],
            ], $this->labels($result['totals'], [
                'in' => 'Money in', 'out' => 'Money out', 'net' => 'Net',
                'transfer_in' => 'Moved between our own accounts (in)', 'transfer_out' => 'Moved between our own accounts (out)',
            ]));
        }

        return view('cash-bank.reports.flow', [
            'result' => $result,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'branch' => $branchId],
            'branches' => $this->branches($request),
        ]);
    }

    /** What the tills counted against what they should have held. */
    public function sessions(Request $request): View|StreamedResponse
    {
        $from = $this->date($request, 'from', now()->subDays(30));
        $to = $this->date($request, 'to', now());
        $branchId = $request->filled('branch') ? (int) $request->query('branch') : null;
        $state = in_array($request->query('state'), ['open', 'closed'], true) ? (string) $request->query('state') : null;

        $result = $this->cash->sessionVariance($from, $to->copy()->endOfDay(), $branchId, $state);

        if ($this->wantsCsv($request)) {
            return $this->csv('session-variance', $result['rows'], [
                'Session', 'Branch', 'Opened', 'Closed', 'Opened by', 'Closed by', 'State',
                'Float', 'Cash sales', 'Other sales', 'Cash in', 'Cash out', 'Expected', 'Counted', 'Difference',
            ], fn (array $row) => [
                $row['session_no'],
                $row['branch'],
                $row['opened_at']?->format('Y-m-d H:i'),
                $row['closed_at']?->format('Y-m-d H:i'),
                $row['opened_by'],
                $row['closed_by'],
                $row['status'],
                (float) $row['float'],
                (float) $row['cash_sales'],
                (float) $row['non_cash_sales'],
                (float) $row['cash_in'],
                (float) $row['cash_out'],
                (float) $row['expected'],
                $row['counted'] !== null ? (float) $row['counted'] : null,
                $row['variance'] !== null ? (float) $row['variance'] : null,
            ], [
                'Sessions' => $result['totals']['sessions'],
                'Closed' => $result['totals']['closed'],
                'Still open' => $result['totals']['open'],
                'Cash sales' => $result['totals']['cash_sales'],
                'Counted short' => $result['totals']['counted_short'],
                'Counted over' => $result['totals']['counted_over'],
                'Net difference' => $result['totals']['net_variance'],
            ]);
        }

        return view('cash-bank.reports.sessions', [
            'result' => $result,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'branch' => $branchId,
                'state' => $state,
            ],
            'branches' => $result['branches'],
        ]);
    }

    /* ---------------------------------------------------------- internals */

    /** @return array<string, mixed> */
    protected function expenseFilters(Request $request): array
    {
        return [
            'from' => $this->date($request, 'from', now()->startOfMonth())->toDateString(),
            'to' => $this->date($request, 'to', now())->toDateString(),
            'category_id' => $request->filled('category') ? (int) $request->query('category') : null,
            'branch_id' => $request->filled('branch') ? (int) $request->query('branch') : null,
            'settled_with' => in_array($request->query('settled'), array_keys(Expense::SETTLED_WITH), true)
                ? (string) $request->query('settled')
                : null,
            'q' => trim((string) $request->query('q')) !== '' ? trim((string) $request->query('q')) : null,
        ];
    }

    protected function date(Request $request, string $key, Carbon $default): Carbon
    {
        $value = (string) $request->query($key, '');

        return $value !== '' && strtotime($value) !== false
            ? Carbon::parse($value)->startOfDay()
            : $default->copy()->startOfDay();
    }

    protected function branches(Request $request)
    {
        return Branch::query()
            ->where('company_id', $this->companyId($request))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);
    }

    protected function companyId(Request $request): int
    {
        return (int) $request->user()->company_id;
    }

    protected function wantsCsv(Request $request): bool
    {
        return strtolower((string) $request->query('format')) === 'csv';
    }

    /** @param  array<string, mixed>  $totals */
    protected function labels(array $totals, array $wanted): array
    {
        $labels = [];

        foreach ($wanted as $key => $label) {
            if (array_key_exists($key, $totals)) {
                $labels[$label] = is_float($totals[$key]) || is_int($totals[$key])
                    ? number_format((float) $totals[$key], 2, '.', '')
                    : $totals[$key];
            }
        }

        return $labels;
    }

    /**
     * Stream the rows the screen is showing. Every report in this family exports
     * the same rows under the same filters, so a spreadsheet and a printed page
     * can never say different things.
     *
     * @param  iterable<mixed>  $rows
     * @param  array<int, string>  $headings
     * @param  callable(mixed): array<int, mixed>  $map
     * @param  array<string, mixed>  $totals
     */
    protected function csv(string $name, iterable $rows, array $headings, callable $map, array $totals = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headings, $map, $totals) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $map($row));
            }

            if ($totals !== []) {
                fputcsv($out, []);
                fputcsv($out, ['Totals']);

                foreach ($totals as $label => $value) {
                    fputcsv($out, [$label, $value]);
                }
            }

            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
