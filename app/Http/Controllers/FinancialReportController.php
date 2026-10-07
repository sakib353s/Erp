<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Accounting\Services\TrialBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Financial report screens wired to Phase D services (09-02, 09-32).
 * Full P&L / Balance Sheet / Cash Flow arrive with later report phases;
 * trial balance and GL are the gate for Phase D.
 */
class FinancialReportController extends Controller
{
    public function __construct(
        protected TrialBalanceService $trialBalance,
        protected LedgerService $ledger,
    ) {}

    public function trialBalance(Request $request): View
    {
        $asAt = $request->query('as_at')
            ? Carbon::parse($request->query('as_at'))
            : null;

        return view('accounting.reports.trial-balance', [
            'report' => $this->trialBalance->build($asAt),
            'asAt' => $request->query('as_at'),
        ]);
    }

    public function openingTrialBalance(Request $request): View
    {
        $asAt = $request->query('as_at')
            ? Carbon::parse($request->query('as_at'))
            : null;

        return view('accounting.reports.opening-trial-balance', [
            'report' => $this->trialBalance->opening($asAt),
            'asAt' => $request->query('as_at'),
        ]);
    }

    /** Rebuild derived running balances from journal_lines (§7.4). */
    public function rebuildBalances(Request $request): RedirectResponse
    {
        $companyId = $request->user()->company_id;
        $count = $this->ledger->rebuildRunningBalances((int) $companyId);

        return back()->with('status', "Running balances rebuilt for {$count} account(s).");
    }

    /** Reconciliation report: every posted entry D=C and grand total balances. */
    public function reconcile(Request $request): View
    {
        $result = app(TrialBalanceService::class)->reconcile((int) $request->user()->company_id);

        return view('accounting.reports.reconcile', [
            'result' => $result,
        ]);
    }
}
