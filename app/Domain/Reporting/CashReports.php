<?php

namespace App\Domain\Reporting;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\PosSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The cash reports (§08-22) — the four questions the money desk is asked, and
 * the one rule that makes all four trustworthy: **every figure is the ledger's.**
 *
 *   · the cash book — one money account's book, opening brought forward and a
 *     running balance per row, the way a bank statement reads;
 *   · the bank book — the same book for every bank and wallet in the company,
 *     side by side, with what each one moved in and out over the period;
 *   · the cash flow — where money came from and where it went, by the *other*
 *     side of each posting: the counter-account is what makes a receipt a sale
 *     and a payment a rent bill, so the report is built from the counterpart
 *     accounts rather than from document types. Transfers between the company's
 *     own accounts are shown separately and excluded from the totals, because
 *     moving money between two pockets is not cash flow;
 *   · the session variance — what the tills counted against what the drawers
 *     should have held, session by session.
 *
 * Nothing here is modelled or estimated. A figure that cannot be read off posted
 * journal lines does not appear on these screens; the session variance page is
 * the one place that reads the till's own record, and it says so.
 */
class CashReports
{
    public function __construct(
        protected MoneyAccountService $money,
        protected LedgerService $ledger,
        protected TenantContext $context,
    ) {}

    /**
     * One money account's book (§08-22 cash book / bank book), straight off
     * `LedgerService::accountLedger()` so the report and the ledger screen can
     * never drift apart.
     *
     * @return array<string, mixed>
     */
    public function book(Account $account, ?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): array
    {
        // Opening before the window, so the first row of the report is the
        // balance the account walked in with rather than a number that starts
        // counting halfway through the month.
        $ledger = $this->ledger->accountLedger($account, $from, $to, $branchId);

        $opening = 0.0;
        $in = 0.0;
        $out = 0.0;
        $days = [];

        foreach ($ledger['rows'] as $row) {
            if (! empty($row['is_opening'])) {
                $opening = (float) $row['running_balance'];
                continue;
            }

            $debit = (float) $row['debit'];
            $credit = (float) $row['credit'];
            $in += $debit;
            $out += $credit;
            $date = (string) ($row['entry_date'] ?? '');

            if ($date !== '') {
                $days[$date] ??= ['date' => $date, 'in' => 0.0, 'out' => 0.0, 'rows' => 0];
                $days[$date]['in'] = round($days[$date]['in'] + $debit, 4);
                $days[$date]['out'] = round($days[$date]['out'] + $credit, 4);
                $days[$date]['rows']++;
            }
        }

        return [
            'account' => $account,
            'instrument' => $this->money->instrumentOf($account),
            'label' => $this->money->label($account),
            'opening' => number_format($opening, 4, '.', ''),
            'in' => number_format($in, 4, '.', ''),
            'out' => number_format($out, 4, '.', ''),
            'closing' => $ledger['balance'],
            'rows' => $ledger['rows'],
            'days' => array_values($days),
            'movement' => $ledger['rows'][count($ledger['rows']) - 1]['entry_date'] ?? null,
        ];
    }

    /**
     * Every bank and wallet in the company, side by side — the page a treasurer
     * reads on a Monday morning.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, instruments: array<int, string>}
     */
    public function bankBook(?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): array
    {
        $rows = collect();
        $totals = ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'closing' => 0.0];

        foreach ($this->money->accounts() as $account) {
            $instrument = $this->money->instrumentOf($account);

            if (! in_array($instrument, ['bank', 'wallet'], true)) {
                continue;
            }

            $book = $this->book($account, $from, $to, $branchId);

            $totals['opening'] += (float) $book['opening'];
            $totals['in'] += (float) $book['in'];
            $totals['out'] += (float) $book['out'];
            $totals['closing'] += (float) $book['closing'];

            $rows->push([
                'account' => $account,
                'instrument' => $instrument,
                'label' => $book['label'],
                'opening' => $book['opening'],
                'in' => $book['in'],
                'out' => $book['out'],
                'closing' => $book['closing'],
                'rows' => count($book['rows']) - 1,
                'last_movement' => $book['movement'],
            ]);
        }

        return [
            'rows' => $rows->sortByDesc(fn (array $row) => (float) $row['closing'])->values(),
            'totals' => array_map(fn (float $value): string => number_format($value, 4, '.', ''), $totals),
            'instruments' => $rows->pluck('instrument')->unique()->values()->all(),
        ];
    }

    /**
     * Where money came from and where it went (§08-22 cash flow).
     *
     * Built from the counterpart account of every posted line on a money
     * account: the account on the *other* side of the entry is what names the
     * movement, so a receipt from a customer is a customer receipt whether the
     * clerk recorded it as a receipt, a sale or a journal correction. Each
     * posting is counted once — by its counterpart — which is what makes the
     * sum of the inflow groups equal to the money accounts' own debit total.
     *
     * A transfer between two of the company's own money accounts has a money
     * account on *both* sides, so it is reported apart and left out of the
     * totals: moving money between two pockets is not cash flow, it is a change
     * of address.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null): array
    {
        $moneyIds = $this->money->accounts()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($moneyIds === []) {
            return [
                'inflows' => collect(), 'outflows' => collect(), 'transfers' => collect(),
                'by_month' => [], 'by_account' => collect(),
                'totals' => ['in' => '0.0000', 'out' => '0.0000', 'net' => '0.0000',
                    'transfer_in' => '0.0000', 'transfer_out' => '0.0000', 'rows' => 0],
            ];
        }

        // The other side of every line that touches a money account, so a
        // movement is named by what it was for rather than by how it was filed.
        $lines = JournalLine::query()
            ->where('journal_lines.company_id', $this->context->companyId())
            ->whereIn('journal_lines.account_id', $moneyIds)
            ->whereHas('entry', function ($query) use ($from, $to, $branchId) {
                $query->where('posting_state', 'posted')
                    ->when($from, fn ($inner) => $inner->whereDate('entry_date', '>=', $from))
                    ->when($to, fn ($inner) => $inner->whereDate('entry_date', '<=', $to))
                    ->when($branchId !== null, fn ($inner) => $inner->where('branch_id', $branchId));
            })
            ->with(['entry:id,entry_date,entry_no,description,source_type,source_event,branch_id', 'account:id,code,name,type'])
            ->get();

        // Each entry's lines grouped, so a line's counterpart can be read without
        // a second query per row.
        $byEntry = $lines->groupBy('journal_entry_id');

        $inflows = [];
        $outflows = [];
        $transfers = [];
        $byMonth = [];
        $byAccount = [];
        $totals = ['in' => 0.0, 'out' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'rows' => 0];

        foreach ($byEntry as $entryId => $entryLines) {
            $all = JournalLine::query()
                ->where('journal_entry_id', $entryId)
                ->with('account:id,code,name,type')
                ->get();

            $entry = $entryLines->first()->entry;
            $month = $entry?->entry_date?->format('Y-m') ?? '—';
            $byMonth[$month] ??= ['month' => $month, 'in' => 0.0, 'out' => 0.0, 'net' => 0.0];

            foreach ($entryLines as $line) {
                $debit = $line->isDebit();
                $amount = (float) $line->amount;

                /*
                 * The counterpart: the other side of the entry. A document with
                 * several lines on both sides (a payroll run, an invoice
                 * settling against three income accounts) has more than one
                 * candidate, and a line counted against each of them would make
                 * this report claim the company moved money it did not move — so
                 * the line is attributed to its **largest** counterpart and to
                 * that alone. Every line is then counted exactly once, which is
                 * what keeps the inflow and outflow groups adding up to the
                 * money accounts' own debit and credit totals.
                 */
                $other = $all
                    ->filter(fn ($candidate): bool => (int) $candidate->id !== (int) $line->id
                        && $candidate->isDebit() !== $debit)
                    ->sortByDesc(fn ($candidate): float => (float) $candidate->amount)
                    ->first();

                if ($other === null) {
                    continue;
                }

                $counterAccount = $other->account;
                $counterIsMoney = in_array((int) $other->account_id, $moneyIds, true);
                $key = $counterIsMoney
                    ? 'own-'.min((int) $line->account_id, (int) $other->account_id).'-'.max((int) $line->account_id, (int) $other->account_id)
                    : 'acc-'.$other->account_id;

                $bucket = &$inflows;

                if (! $debit) {
                    $bucket = &$outflows;
                }

                if ($counterIsMoney) {
                    $bucket = &$transfers;
                }

                $label = trim(((string) $counterAccount?->code).' — '.((string) $counterAccount?->name), ' —');

                if ($counterIsMoney) {
                    $label = 'Between our own accounts · '.$label;
                }

                $bucket[$key] ??= [
                    'key' => $key,
                    'counter_account' => $label,
                    'account_type' => $counterAccount?->type,
                    'rows' => 0,
                    'amount' => 0.0,
                    'months' => [],
                ];

                $bucket[$key]['rows']++;
                $bucket[$key]['amount'] = round($bucket[$key]['amount'] + $amount, 4);
                $bucket[$key]['months'][$month] = round(($bucket[$key]['months'][$month] ?? 0) + $amount, 4);

                $totals['rows']++;

                if ($counterIsMoney) {
                    $totals[$debit ? 'transfer_in' : 'transfer_out'] += $amount;
                } else {
                    $totals[$debit ? 'in' : 'out'] += $amount;
                    $byMonth[$month][$debit ? 'in' : 'out'] = round($byMonth[$month][$debit ? 'in' : 'out'] + $amount, 4);
                }

                $accountKey = (int) $line->account_id;
                $byAccount[$accountKey] ??= ['account' => $line->account, 'in' => 0.0, 'out' => 0.0];

                if (! $counterIsMoney) {
                    $byAccount[$accountKey][$debit ? 'in' : 'out'] += $amount;
                }
            }
        }

        foreach ($byMonth as $month => $figures) {
            $byMonth[$month]['net'] = round($figures['in'] - $figures['out'], 4);
        }

        ksort($byMonth);

        return [
            'inflows' => $this->grouped($inflows),
            'outflows' => $this->grouped($outflows),
            'transfers' => $this->grouped($transfers),
            'by_month' => array_values($byMonth),
            'by_account' => collect($byAccount)->map(fn (array $row): array => [
                'account' => $row['account'],
                'in' => number_format($row['in'], 4, '.', ''),
                'out' => number_format($row['out'], 4, '.', ''),
                'net' => number_format($row['in'] - $row['out'], 4, '.', ''),
            ])->values(),
            'totals' => [
                'in' => number_format($totals['in'], 4, '.', ''),
                'out' => number_format($totals['out'], 4, '.', ''),
                'net' => number_format($totals['in'] - $totals['out'], 4, '.', ''),
                'transfer_in' => number_format($totals['transfer_in'], 4, '.', ''),
                'transfer_out' => number_format($totals['transfer_out'], 4, '.', ''),
                'rows' => $totals['rows'],
            ],
        ];
    }

    /**
     * What each till counted against what its drawer should have held (§08-22
     * session variance).
     *
     * This is the one report in the module that does not read the ledger: a
     * session's expected cash is the till's own arithmetic (opening float + cash
     * sales + cash in − cash out), and the count is what a person said was in the
     * tin. The two are recorded on the session, and the difference between them
     * is the figure a manager chases — which is exactly why the page says where
     * the numbers come from instead of implying they are postings.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, mixed>, branches: Collection<int, Branch>}
     */
    public function sessionVariance(?Carbon $from = null, ?Carbon $to = null, ?int $branchId = null, ?string $state = null): array
    {
        $from ??= now()->subDays(30)->startOfDay();
        $to ??= now()->endOfDay();

        $sessions = PosSession::query()
            ->where('company_id', $this->context->companyId())
            ->whereBetween('opened_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->when($state !== null, fn ($query) => $query->where('status', $state))
            ->with(['branch:id,name', 'opener:id,name', 'closer:id,name'])
            ->orderByDesc('opened_at')
            ->get();

        $rows = $sessions->map(function (PosSession $session): array {
            $counted = $session->closing_counted !== null ? (float) $session->closing_counted : null;
            $expected = (float) $session->expected_cash;
            $variance = $counted !== null ? round($counted - $expected, 4) : null;

            return [
                'session' => $session,
                'session_no' => $session->session_no,
                'branch' => $session->branch?->name,
                'opened_by' => $session->opener?->name,
                'closed_by' => $session->closer?->name,
                'opened_at' => $session->opened_at,
                'closed_at' => $session->closed_at,
                'status' => $session->status,
                'float' => number_format((float) $session->opening_float, 4, '.', ''),
                'cash_sales' => number_format((float) $session->cash_sales, 4, '.', ''),
                'non_cash_sales' => number_format((float) $session->non_cash_sales, 4, '.', ''),
                'cash_in' => number_format((float) $session->cash_in, 4, '.', ''),
                'cash_out' => number_format((float) $session->cash_out, 4, '.', ''),
                'expected' => number_format($expected, 4, '.', ''),
                'counted' => $counted !== null ? number_format($counted, 4, '.', '') : null,
                'variance' => $variance !== null ? number_format($variance, 4, '.', '') : null,
                'short' => $variance !== null && $variance < -0.00005,
                'over' => $variance !== null && $variance > 0.00005,
                'has_variance' => $variance !== null && abs($variance) > 0.00005,
            ];
        });

        $closed = $rows->where('counted', '!==', null);
        $variances = $rows->pluck('variance')->filter(fn ($value) => $value !== null);

        return [
            'rows' => $rows,
            'totals' => [
                'sessions' => $rows->count(),
                'open' => $rows->where('status', 'open')->count(),
                'closed' => $closed->count(),
                'cash_sales' => number_format((float) $sessions->sum('cash_sales'), 4, '.', ''),
                'non_cash_sales' => number_format((float) $sessions->sum('non_cash_sales'), 4, '.', ''),
                'cash_in' => number_format((float) $sessions->sum('cash_in'), 4, '.', ''),
                'cash_out' => number_format((float) $sessions->sum('cash_out'), 4, '.', ''),
                'counted_short' => number_format((float) $variances->filter(fn ($v) => (float) $v < -0.00005)->sum(), 4, '.', ''),
                'counted_over' => number_format((float) $variances->filter(fn ($v) => (float) $v > 0.00005)->sum(), 4, '.', ''),
                'net_variance' => number_format((float) $variances->sum(), 4, '.', ''),
                'with_variance' => $rows->where('has_variance', true)->count(),
                'worst' => $rows->where('has_variance', true)->sortBy(fn (array $row) => abs((float) $row['variance']))->last(),
            ],
            'branches' => Branch::query()
                ->where('company_id', $this->context->companyId())
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return Collection<int, array<string, mixed>>
     */
    protected function grouped(array $groups): Collection
    {
        $total = array_sum(array_map(fn (array $group): float => (float) $group['amount'], $groups));

        return collect($groups)
            ->sortByDesc('amount')
            ->values()
            ->map(function (array $group) use ($total): array {
                $group['share'] = $total > 0 ? round(((float) $group['amount'] / $total) * 100, 1) : 0.0;
                $group['amount'] = number_format((float) $group['amount'], 4, '.', '');

                return $group;
            });
    }
}
