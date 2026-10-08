<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\PettyCashFund;
use App\Domain\CashBank\PettyCashRequest;
use App\Domain\CashBank\PettyCashTransaction;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\CashBank\Services\PettyCashService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Http\Requests\PettyCashDecisionRequest;
use App\Http\Requests\ReplenishPettyCashRequest;
use App\Http\Requests\StorePettyCashFundRequest;
use App\Http\Requests\StorePettyCashVoucherRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * §08-21 — the float in the drawer, the vouchers paid out of it, and the money
 * that puts it back.
 *
 * Four screens, because they are four questions: what is in the tins and whose
 * they are (overview), what has been asked for (requests), what has been paid
 * (expenses), and what has been put back (replenishment). Every one of them reads
 * the float's balance from the ledger — the desk keeps no running total of its
 * own, precisely so the figure on screen and the figure in the books cannot
 * disagree.
 *
 * The limit is the company's, not the screen's: the service decides whether a
 * voucher is paid or asked for, and the desk tells the person which of the two
 * happened rather than hiding the button.
 */
class PettyCashController extends Controller
{
    public function __construct(
        protected PettyCashService $petty,
        protected MoneyAccountService $accounts,
        protected TenantContext $context,
    ) {}

    // --------------------------------------------------------------- overview

    /** The floats, their balances, and everything the desk opens with. */
    public function index(Request $request): View
    {
        $funds = $this->petty->funds();
        $balances = $this->petty->balances($funds);

        $rows = $funds->map(fn (PettyCashFund $fund) => [
            'fund' => $fund,
            'balance' => $balances[$fund->id] ?? '0.0000',
            'shortfall' => $this->petty->shortfallOf($fund, $balances[$fund->id] ?? '0.0000'),
        ]);

        return view('cash-bank.petty-cash', [
            'rows' => $rows,
            'summary' => $this->petty->summary(),
            'threshold' => $this->petty->threshold(),
            'custodians' => $this->custodians($request),
            'branches' => $this->branches($request),
            'recent' => $this->petty->vouchers([], 8),
            'mayDeclare' => (bool) $request->user()->can('pettycash.funds'),
        ]);
    }

    public function storeFund(StorePettyCashFundRequest $request): RedirectResponse
    {
        try {
            $fund = $this->petty->saveFund($request->validated(), null, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash')
            ->with('status', "The float {$fund->code} — {$fund->name} is open, held by {$fund->custodian?->name}.");
    }

    public function updateFund(StorePettyCashFundRequest $request, PettyCashFund $fund): RedirectResponse
    {
        $this->assertOwned($request, $fund);

        try {
            $this->petty->saveFund($request->validated(), $fund, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash')
            ->with('status', "The float {$fund->code} has been re-described.");
    }

    public function closeFund(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $this->assertOwned($request, $fund);

        try {
            $this->petty->closeFund($fund, $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash')
            ->with('status', "The float {$fund->code} is closed.");
    }

    // --------------------------------------------------------------- requests

    /** What custodians have asked for, pending first. */
    public function requests(Request $request): View
    {
        $status = $this->stringOrNull($request->query('status'));

        return view('cash-bank.petty-cash-requests', [
            'filters' => [
                'status' => $status,
                'fund' => $request->query('fund') !== null ? (int) $request->query('fund') : null,
            ],
            'requests' => $this->petty->requests([
                'status' => $status,
                'fund' => $request->query('fund') !== null ? (int) $request->query('fund') : null,
            ]),
            'statuses' => PettyCashRequest::STATUSES,
            'summary' => $this->petty->summary(),
            'funds' => $this->petty->funds(true),
            'categories' => $this->categories($request),
            'threshold' => $this->petty->threshold(),
            'mayDecide' => (bool) $request->user()->can('pettycash.approve'),
            'recent' => $this->petty->requests([], 5),
        ]);
    }

    /**
     * Ask for money — or, below the limit, just pay it. The decision belongs to
     * the service; this action files whichever answer came back.
     */
    public function storeRequest(StorePettyCashVoucherRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $fund = $this->ownedFund($request, (int) $data['fund_id']);

        try {
            if (! $this->petty->mustApprove((float) $data['amount'])) {
                $voucher = $this->petty->payVoucher($data, $request->user());

                return redirect()
                    ->route('cash-bank.petty-cash.expenses', ['fund' => $fund->id])
                    ->with('status', "Paid out of {$fund->name}: {$voucher->documentNo()}. Below the limit of {$this->petty->threshold()}, so no signature was needed.");
            }

            $asked = $this->petty->ask($data, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash.requests', ['fund' => $fund->id])
            ->with('status', "Asked for {$asked->amount} for {$asked->payee}. Nothing is posted until somebody else approves it.");
    }

    public function decideRequest(PettyCashDecisionRequest $request, PettyCashRequest $pettyRequest): RedirectResponse
    {
        $this->assertOwnedRequest($request, $pettyRequest);

        $action = (string) $request->validated('action');
        $note = $request->validated('note');

        try {
            if ($action === 'approve') {
                $voucher = $this->petty->approve($pettyRequest, $request->user(), is_string($note) ? $note : null);

                return redirect()
                    ->route('cash-bank.petty-cash.requests')
                    ->with('status', "Approved and paid: {$voucher->documentNo()} to {$pettyRequest->payee}.");
            }

            $this->petty->reject($pettyRequest, $request->user(), is_string($note) ? $note : null);
        } catch (RuntimeException $error) {
            return back()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash.requests')
            ->with('status', "Refused: {$pettyRequest->payee} is not to be paid from this float.");
    }

    // ---------------------------------------------------------------- vouchers

    /** The vouchers that have been paid out of the floats. */
    public function expenses(Request $request): View
    {
        $filters = [
            'fund' => $request->query('fund') !== null ? (int) $request->query('fund') : null,
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'from' => $this->stringOrNull($request->query('from')),
            'to' => $this->stringOrNull($request->query('to')),
        ];

        return view('cash-bank.petty-cash-expenses', [
            'filters' => $filters,
            'vouchers' => $this->petty->vouchers($filters),
            'summary' => $this->petty->summary(),
            'funds' => $this->petty->funds(true),
            'categories' => $this->categories($request),
            'threshold' => $this->petty->threshold(),
            'mayPay' => (bool) $request->user()->can('pettycash.spend'),
        ]);
    }

    public function storeVoucher(StorePettyCashVoucherRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $fund = $this->ownedFund($request, (int) $data['fund_id']);

        try {
            $voucher = $this->petty->payVoucher($data, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        $payee = $voucher->payee ?? 'the payee';

        return redirect()
            ->route('cash-bank.petty-cash.expenses', ['fund' => $fund->id])
            ->with('status', "{$voucher->documentNo()} — {$voucher->amount} to {$payee}, paid out of {$fund->name}.");
    }

    // ----------------------------------------------------------- replenishment

    /** What has been put back into the floats, newest first. */
    public function replenishments(Request $request): View
    {
        $fund = $request->query('fund') !== null ? $this->ownedFundOrNull($request, (int) $request->query('fund')) : null;

        $funds = $this->petty->funds();
        $balances = $this->petty->balances($funds);

        return view('cash-bank.petty-cash-replenishment', [
            'fund' => $fund,
            'funds' => $funds->map(fn (PettyCashFund $item) => [
                'fund' => $item,
                'balance' => $balances[$item->id] ?? '0.0000',
                'shortfall' => $this->petty->shortfallOf($item, $balances[$item->id] ?? '0.0000'),
            ]),
            'replenishments' => $this->petty->replenishments($fund?->id),
            'summary' => $this->petty->summary(),
            'sources' => $this->sources($request),
        ]);
    }

    public function storeReplenishment(ReplenishPettyCashRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $fund = $this->ownedFund($request, (int) $data['fund_id']);

        try {
            $topped = $this->petty->replenish($data, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['petty_cash' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.petty-cash.replenishments', ['fund' => $fund->id])
            ->with('status', "Put {$topped->amount} back into {$fund->name} as {$topped->documentNo()}.");
    }

    // ------------------------------------------------------------- internals

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function custodians(Request $request)
    {
        return User::query()
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Where a float may be opened: the company's own branches, default first. */
    protected function branches(Request $request)
    {
        return Branch::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    /** The categories an expense can be filed under — the same list the expense desk uses. */
    protected function categories(Request $request)
    {
        return ExpenseCategory::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->with('account')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** Where a top-up may come from: the company's own cash, bank and wallet accounts. */
    protected function sources(Request $request)
    {
        return $this->accounts->accounts()->where('is_active', true)->values();
    }

    protected function ownedFund(Request $request, int $id): PettyCashFund
    {
        $fund = PettyCashFund::query()
            ->where('company_id', $request->user()->company_id)
            ->find($id);

        abort_if($fund === null, 404);

        return $fund;
    }

    protected function ownedFundOrNull(Request $request, int $id): ?PettyCashFund
    {
        return PettyCashFund::query()
            ->where('company_id', $request->user()->company_id)
            ->find($id);
    }

    protected function assertOwned(Request $request, PettyCashFund $fund): void
    {
        abort_unless((int) $fund->company_id === (int) $request->user()->company_id, 404);
    }

    protected function assertOwnedRequest(Request $request, PettyCashRequest $pettyRequest): void
    {
        abort_unless((int) $pettyRequest->company_id === (int) $request->user()->company_id, 404);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
