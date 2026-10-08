<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\CashBank\CashCount;
use App\Domain\CashBank\Services\CashCountService;
use App\Domain\Foundation\Branch;
use App\Http\Requests\CashCountDecisionRequest;
use App\Http\Requests\StoreCashCountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * §08-05 — counting the drawer.
 *
 * Two screens: the desk, which lists the drawers and the counts made of them, and
 * the sheet, which is one count read on its own — what the books said, what was
 * in the tin, the notes and coins that were added up to get there, and who
 * answered for the difference. Nothing here computes a balance: the expected
 * figure is the ledger's and it is frozen on the count when it is made.
 */
class CashCountController extends Controller
{
    public function __construct(protected CashCountService $counts) {}

    /** The desk: the drawers, what they hold, and every count made of them. */
    public function index(Request $request): View
    {
        $filters = [
            'account' => $request->query('account') !== null ? (int) $request->query('account') : null,
            'status' => $this->stringOrNull($request->query('status')),
            'from' => $this->stringOrNull($request->query('from')),
            'to' => $this->stringOrNull($request->query('to')),
        ];

        return view('cash-bank.cash-counts', [
            'filters' => $filters,
            'drawers' => $this->counts->drawers(),
            'counts' => $this->counts->counts($filters),
            'summary' => $this->counts->summary(),
            'statuses' => CashCount::STATUSES,
            'denominations' => CashCount::DENOMINATIONS,
            'branches' => $this->branches($request),
            'mayCount' => (bool) $request->user()->can('cash.counts'),
            'mayApprove' => (bool) $request->user()->can('cash.counts.approve'),
        ]);
    }

    public function store(StoreCashCountRequest $request): RedirectResponse
    {
        $account = Account::query()
            ->where('company_id', $request->user()->company_id)
            ->find((int) $request->validated('account_id'));

        abort_if($account === null, 404);

        try {
            $count = $this->counts->countCash($account, $request->validated(), $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['cash_count' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.cash-counts.show', $count)
            ->with('status', $this->story($count));
    }

    /** One count, read on its own. */
    public function show(Request $request, CashCount $cashCount): View
    {
        $this->assertOwned($request, $cashCount);

        return view('cash-bank.cash-count', [
            'count' => $cashCount->load(['account', 'branch', 'counter', 'decider', 'lines', 'journalEntry']),
            'mayApprove' => (bool) $request->user()->can('cash.counts.approve'),
            'drawer' => $this->counts->drawers()->firstWhere(fn ($row) => $row['account']->id === $cashCount->account_id),
        ]);
    }

    public function decide(CashCountDecisionRequest $request, CashCount $cashCount): RedirectResponse
    {
        $this->assertOwned($request, $cashCount);

        $action = (string) $request->validated('action');
        $note = $request->validated('note');

        try {
            if ($action === 'approve') {
                $decided = $this->counts->approve($cashCount, $request->user(), is_string($note) ? $note : null);

                return redirect()
                    ->route('cash-bank.cash-counts.show', $decided)
                    ->with('status', 'Approved — the difference is posted against Cash Over & Short and the drawer now reads as counted.');
            }

            $this->counts->reject($cashCount, $request->user(), is_string($note) ? $note : null);
        } catch (RuntimeException $error) {
            return back()->withErrors(['cash_count' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.cash-counts.show', $cashCount)
            ->with('status', 'Refused — the books are left exactly as they were, with your reason on the record.');
    }

    // ------------------------------------------------------------- internals

    /** The sentence the counter reads after the count, which is the point of it. */
    protected function story(CashCount $count): string
    {
        if ($count->balanced()) {
            return 'Counted exactly: the tin held what the books said it would. Nothing to correct.';
        }

        if ($count->isPending()) {
            return sprintf(
                'Counted %s, expected %s — a difference of %s, which is at or above the tolerance of %s. Nothing has been posted: somebody other than you has to approve it.',
                number_format((float) $count->counted_amount, 2),
                number_format((float) $count->expected_amount, 2),
                $count->varianceLabel(),
                $count->tolerance,
            );
        }

        return sprintf(
            'Counted %s, expected %s — a difference of %s, posted as %s.',
            number_format((float) $count->counted_amount, 2),
            number_format((float) $count->expected_amount, 2),
            $count->varianceLabel(),
            $count->isShort() ? 'a shortage' : 'an overage',
        );
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    protected function branches(Request $request)
    {
        return Branch::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    protected function assertOwned(Request $request, CashCount $count): void
    {
        abort_unless((int) $count->company_id === (int) $request->user()->company_id, 404);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
