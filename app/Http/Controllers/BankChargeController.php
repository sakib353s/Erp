<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\CashBank\BankCharge;
use App\Domain\CashBank\BankChargeRule;
use App\Domain\CashBank\Services\BankChargeService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Branch;
use App\Http\Requests\StoreBankChargeRequest;
use App\Http\Requests\StoreBankChargeRuleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * §08-10 — the charges a bank takes without asking.
 *
 * One screen, because the leaf is one leaf: what the bank has taken (the
 * register, with the reversals answered next to the charges they undo), what it
 * is going to take (the rules, with the tariff spelled out in words) and the one
 * button that runs them now instead of waiting for the morning.
 *
 * The two powers are separate keys on purpose. Recording a charge is bookkeeping
 * — the bank already took the money. Deciding *what will be charged automatically
 * from now on* is a policy act, and it is the one that can quietly move money
 * every quarter for years, so it sits behind `bank.charges.rules`.
 */
class BankChargeController extends Controller
{
    public function __construct(
        protected BankChargeService $charges,
        protected MoneyAccountService $money,
    ) {}

    /** The register, the rules, and what is due right now. */
    public function index(Request $request): View
    {
        $filters = [
            'account' => $request->query('account') !== null ? (int) $request->query('account') : null,
            'status' => $this->stringOrNull($request->query('status')),
            'origin' => $this->stringOrNull($request->query('origin')),
            'from' => $this->stringOrNull($request->query('from')),
            'to' => $this->stringOrNull($request->query('to')),
            'q' => $this->stringOrNull($request->query('q')),
        ];

        $rules = $this->charges->rules();
        $asOf = now()->copy()->startOfDay();

        $quotes = [];

        foreach ($rules as $rule) {
            if ($rule->isActive()) {
                // What the rule would charge today — the number the operator
                // checks against the tariff sheet before trusting the run.
                $quotes[$rule->id] = $this->charges->quote($rule, ($rule->next_due_on !== null && $rule->next_due_on->gt($asOf)) ? $rule->next_due_on : $asOf);
            }
        }

        return view('cash-bank.bank-charges', [
            'filters' => $filters,
            'charges' => $this->charges->charges($filters),
            'summary' => $this->charges->summary($asOf),
            'rules' => $rules,
            'quotes' => $quotes,
            'accounts' => $this->moneyAccounts($request),
            'expenseAccounts' => $this->expenseAccounts($request),
            'defaultExpenseAccount' => $this->charges->defaultExpenseAccount(),
            'branches' => $this->branches($request),
            'due' => $this->charges->dueCount($asOf),
            'mayConfigure' => (bool) $request->user()->can('bank.charges.rules'),
        ]);
    }

    // ----------------------------------------------------------------- rules

    public function storeRule(StoreBankChargeRuleRequest $request): RedirectResponse
    {
        try {
            $rule = $this->charges->saveRule($request->validated(), null, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['bank_charge' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.bank-charges')
            ->with('status', "The rule “{$rule->name}” is in place: {$rule->termsLabel()}, {$rule->rhythm()}. Nothing has been charged yet — the first charge falls on ".($rule->next_due_on?->toDateString() ?? 'a date to be set').'.');
    }

    public function updateRule(StoreBankChargeRuleRequest $request, BankChargeRule $rule): RedirectResponse
    {
        $this->assertOwnedRule($request, $rule);

        try {
            $this->charges->saveRule($request->validated(), $rule, $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['bank_charge' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.bank-charges')
            ->with('status', "The rule “{$rule->name}” has been rewritten.");
    }

    public function toggleRule(Request $request, BankChargeRule $rule): RedirectResponse
    {
        $this->assertOwnedRule($request, $rule);

        try {
            $this->charges->toggleRule($rule, $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['bank_charge' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.bank-charges')
            ->with('status', $rule->refresh()->isActive()
                ? "“{$rule->name}” is running again — its next charge is ".($rule->next_due_on?->toDateString() ?? 'to be set').'.'
                : "“{$rule->name}” is paused. What it already charged stays where it is.");
    }

    // -------------------------------------------------------------- charging

    /** Record a charge — from a rule's own terms, or the figure on the statement. */
    public function storeCharge(StoreBankChargeRequest $request): RedirectResponse
    {
        try {
            $charge = $this->charges->record($request->validated(), $request->user());
        } catch (RuntimeException $error) {
            return back()->withInput()->withErrors(['bank_charge' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.bank-charges')
            ->with('status', "{$charge->charge_no} — {$charge->amount} charged by {$charge->account?->name}, booked to {$charge->expenseAccount?->name}.");
    }

    /** Run the rules now, instead of waiting for the morning's scheduled run. */
    public function runDue(Request $request): RedirectResponse
    {
        $result = $this->charges->generateDue(null, $request->user());

        if ($result['due'] === 0) {
            return redirect()->route('cash-bank.bank-charges')->with('status', 'No rule has come due yet — nothing to charge.');
        }

        $message = $result['generated'].' charge(s) posted';

        if ($result['refused'] > 0) {
            $message .= ', '.$result['refused'].' refused (the desk says why below)';
        }

        if ($result['skipped'] > 0) {
            $message .= ', '.$result['skipped'].' skipped';
        }

        return redirect()->route('cash-bank.bank-charges')->with('status', $message.'.');
    }

    /** Undo a charge with an answering entry rather than editing history. */
    public function reverse(Request $request, BankCharge $charge): RedirectResponse
    {
        abort_unless((int) $charge->company_id === (int) $request->user()->company_id, 404);

        $reason = trim((string) $request->input('reason'));

        try {
            $this->charges->reverse($charge, $request->user(), $reason);
        } catch (RuntimeException $error) {
            return back()->withErrors(['bank_charge' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.bank-charges')
            ->with('status', "{$charge->charge_no} has been reversed: {$reason}");
    }

    // ------------------------------------------------------------ internals

    /** @return \Illuminate\Support\Collection<int, Account> */
    protected function moneyAccounts(Request $request)
    {
        return $this->money->accounts()->where('is_active', true)->values();
    }

    /** Where a charge may be booked: postable expense leaves of this company. */
    protected function expenseAccounts(Request $request)
    {
        return Account::query()
            ->where('company_id', $request->user()->company_id)
            ->where('type', 'expense')
            ->where('is_group', false)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    protected function branches(Request $request)
    {
        return Branch::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    protected function assertOwnedRule(Request $request, BankChargeRule $rule): void
    {
        abort_unless((int) $rule->company_id === (int) $request->user()->company_id, 404);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
