<?php

namespace App\Http\Controllers;

use App\Domain\Business\Services\UtilityService;
use App\Http\Requests\StoreUtilityBillRequest;
use App\Http\Requests\StoreUtilityProviderRequest;
use App\Domain\Business\UtilityBill;
use App\Domain\Business\UtilityProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * §12-15 — the utility desk.
 *
 * Six shelves, one table. Electricity, water, gas, connectivity and rent are the
 * same document with a different provider sitting behind it, and the menu's six
 * leaves are six ways of asking the same question — so they are one screen
 * filtered by *family* rather than six controllers that would drift apart.
 *
 * Everything that decides anything lives in `UtilityService`; this class only
 * reads the request, calls the engine and hands back a page with the refusal on
 * it when the engine says no.
 */
class UtilityBillController extends Controller
{
    public function __construct(protected UtilityService $utilities) {}

    /** The desk: the month's bills, the family tiles and what is still owed. */
    public function index(Request $request, ?string $family = null): View
    {
        $family = $this->family($request->query('family', $family));

        $bills = $this->utilities->visible()
            ->with(['provider.account', 'moneyAccount', 'journalEntry'])
            ->when($family !== null, fn ($query) => $query->whereHas('provider', fn ($inner) => $inner->where('family', $family)))
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('period'), function ($query) use ($request) {
                $period = (string) $request->query('period');

                if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
                    $query->where('period_month', $period);
                }
            })
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $lenses = $this->utilities->reminders();

        return view('business.utilities.index', [
            'bills' => $bills,
            'canManage' => $request->user()->can('business.utilities.manage'),
            'threshold' => $this->utilities->threshold(),
            'family' => $family,
            'families' => UtilityProvider::FAMILIES,
            'providers' => $this->utilities->providers(),
            'summary' => $this->utilities->summary($request->query('period')),
            'overdue' => $lenses['overdue'],
            'dueSoon' => $lenses['due'],
            'periods' => $this->periods(),
        ]);
    }

    /** File one bill. */
    public function create(Request $request): View
    {
        return view('business.utilities.form', [
            'bill' => null,
            'canManage' => true,
            'families' => UtilityProvider::FAMILIES,
            'providers' => $this->utilities->providers(),
            'accounts' => $this->moneyAccounts(),
            'preselected' => $this->family($request->query('family')),
            'period' => $this->period($request->query('period')),
        ]);
    }

    public function store(StoreUtilityBillRequest $request): RedirectResponse
    {
        try {
            $bill = $this->utilities->record($request->validated(), $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['amount' => $error->getMessage()]);
        }

        return redirect()
            ->route('business.utilities.show', $bill)
            ->with('status', "{$bill->bill_no} filed for {$bill->periodLabel()} — nothing has been paid yet.");
    }

    public function show(Request $request, UtilityBill $bill): View
    {
        $this->authorizeBill($request, $bill);

        return view('business.utilities.show', [
            'bill' => $bill->load(['provider.account', 'moneyAccount', 'journalEntry.lines.account', 'creator', 'decisionMaker']),
            'canManage' => $request->user()->can('business.utilities.manage'),
            'threshold' => $this->utilities->threshold(),
            'accounts' => $this->moneyAccounts(),
            'siblings' => $this->utilities->visible()
                ->where('provider_id', $bill->provider_id)
                ->whereKeyNot($bill->id)
                ->orderByDesc('period_month')
                ->limit(6)
                ->get(),
        ]);
    }

    /** Correct an unpaid bill — the same form, filled in. */
    public function edit(Request $request, UtilityBill $bill): View
    {
        $this->authorizeBill($request, $bill);

        return view('business.utilities.form', [
            'bill' => $bill->load('provider'),
            'canManage' => true,
            'families' => UtilityProvider::FAMILIES,
            'providers' => $this->utilities->providers(false),
            'accounts' => $this->moneyAccounts(),
            'preselected' => $bill->provider?->family,
            'period' => (string) $bill->period_month,
        ]);
    }

    /** Edit an unpaid bill. */
    public function update(StoreUtilityBillRequest $request, UtilityBill $bill): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        try {
            $this->utilities->amend($bill, $request->validated(), $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['amount' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.show', $bill)->with('status', "{$bill->bill_no} updated.");
    }

    public function pay(Request $request, UtilityBill $bill): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        $data = $request->validate([
            'money_account_id' => ['nullable', 'integer'],
            'paid_on' => ['nullable', 'date'],
        ]);

        try {
            $bill = $this->utilities->pay(
                $bill,
                $request->user(),
                isset($data['money_account_id']) ? (int) $data['money_account_id'] : null,
                $data['paid_on'] ?? null,
            );
        } catch (\RuntimeException $error) {
            return back()->withErrors(['money_account_id' => $error->getMessage()]);
        }

        $status = $bill->isPending()
            ? "{$bill->bill_no} is above the approval limit of ".number_format((float) $this->utilities->threshold(), 2).' — it is waiting for a second signature and nothing has left the account.'
            : "{$bill->bill_no} paid — entry {$bill->journalEntry?->entry_no} booked Dr {$bill->provider?->account?->code} / Cr {$bill->moneyAccount?->code}.";

        return redirect()->route('business.utilities.show', $bill)->with('status', $status);
    }

    public function approve(Request $request, UtilityBill $bill): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        $note = (string) $request->input('decision_note', '');

        try {
            $bill = $this->utilities->approve($bill, $request->user(), $note);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['decision_note' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.show', $bill)
            ->with('status', "{$bill->bill_no} approved and paid — entry {$bill->journalEntry?->entry_no}.");
    }

    public function reject(Request $request, UtilityBill $bill): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        $note = (string) $request->input('decision_note', '');

        try {
            $this->utilities->reject($bill, $request->user(), $note);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['decision_note' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.show', $bill)
            ->with('status', "{$bill->bill_no} sent back — it is an unpaid bill again and nothing moved.");
    }

    public function void(Request $request, UtilityBill $bill): RedirectResponse
    {
        $this->authorizeBill($request, $bill);

        $reason = (string) $request->input('void_reason', '');

        try {
            $this->utilities->void($bill, $request->user(), $reason);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['void_reason' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.index')->with('status', "{$bill->bill_no} voided.");
    }

    /** The renewal lens: what has gone past its date and what is coming. */
    public function renewals(Request $request): View
    {
        $lenses = $this->utilities->reminders();

        return view('business.utilities.renewals', [
            'canManage' => $request->user()->can('business.utilities.manage'),
            'threshold' => $this->utilities->threshold(),
            'overdue' => $lenses['overdue'],
            'due' => $lenses['due'],
            'next' => $lenses['next'],
            'families' => UtilityProvider::FAMILIES,
            'summary' => $this->utilities->summary(),
        ]);
    }

    /** The provider registry — the accounts behind each shelf. */
    public function providers(Request $request): View
    {
        return view('business.utilities.providers', [
            'canManage' => $request->user()->can('business.utilities.manage'),
            'providers' => $this->utilities->providers(false)->load('account'),
            'families' => UtilityProvider::FAMILIES,
        ]);
    }

    public function createProvider(Request $request): View
    {
        return view('business.utilities.provider-form', [
            'provider' => null,
            'families' => UtilityProvider::FAMILIES,
            'accounts' => $this->expenseAccounts(),
            'branches' => $this->branches($request),
        ]);
    }

    public function editProvider(Request $request, UtilityProvider $provider): View
    {
        abort_unless((int) $provider->company_id === (int) $request->user()->company_id, 404);

        return view('business.utilities.provider-form', [
            'provider' => $provider->load('account'),
            'families' => UtilityProvider::FAMILIES,
            'accounts' => $this->expenseAccounts(),
            'branches' => $this->branches($request),
        ]);
    }

    public function storeProvider(StoreUtilityProviderRequest $request): RedirectResponse
    {
        try {
            $provider = $this->utilities->saveProvider($request->validated(), null, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['code' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.providers')
            ->with('status', "{$provider->name} added to the provider registry.");
    }

    public function updateProvider(StoreUtilityProviderRequest $request, UtilityProvider $provider): RedirectResponse
    {
        abort_unless((int) $provider->company_id === (int) $request->user()->company_id, 404);

        try {
            $this->utilities->saveProvider($request->validated(), $provider, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['code' => $error->getMessage()]);
        }

        return redirect()->route('business.utilities.providers')
            ->with('status', "{$provider->name} updated.");
    }

    /* --------------------------------------------------------------- internals */

    /** A bill from another company is not a 403 — it does not exist here. */
    protected function authorizeBill(Request $request, UtilityBill $bill): void
    {
        abort_unless((int) $bill->company_id === (int) $request->user()->company_id, 404);

        $ids = $request->user()->accessibleBranchIds();

        abort_unless($ids === null || $bill->branch_id === null || in_array((int) $bill->branch_id, $ids, true), 403);
    }

    protected function family(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return $value !== null && array_key_exists($value, UtilityProvider::FAMILIES) ? $value : null;
    }

    protected function period(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? $value : now()->format('Y-m');
    }

    /** The months the desk has bills for, newest first — the period picker. */
    protected function periods(): array
    {
        return $this->utilities->visible()
            ->orderByDesc('period_month')
            ->limit(200)
            ->pluck('period_month')
            ->unique()
            ->take(18)
            ->values()
            ->all();
    }

    /** The branches a provider or a bill may be pinned to. */
    protected function branches(Request $request)
    {
        return \App\Domain\Foundation\Branch::query()
            ->where('company_id', (int) $request->user()->company_id)
            ->orderBy('name')
            ->get();
    }

    protected function moneyAccounts()
    {
        return app(\App\Domain\CashBank\Services\MoneyAccountService::class)->accounts();
    }

    /** The expense accounts a provider may be pointed at: real leaves, never groups. */
    protected function expenseAccounts()
    {
        return \App\Domain\Accounting\Account::query()
            ->where('company_id', (int) auth()->user()?->company_id)
            ->where('is_group', false)
            ->whereIn('type', ['expense', 'EXPENSE'])
            ->orderBy('code')
            ->get();
    }
}
