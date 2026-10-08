<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\BankReconciliation;
use App\Domain\CashBank\Exceptions\StatementRejectedException;
use App\Domain\CashBank\ReconciliationLine;
use App\Domain\CashBank\Services\BankReconciliationService;
use App\Domain\CashBank\Services\BankStatementImportService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Http\Requests\ImportBankStatementRequest;
use App\Http\Requests\OpenReconciliationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Bank and wallet reconciliation (§08-08, §08-09, §08-12).
 *
 * The desk where the books are checked against the institution that holds the
 * money. Nothing on these screens decides anything on its own: a statement is
 * imported whole or refused whole, the matching is an argument written down line
 * by line, and a reconciliation may only be signed off when its arithmetic
 * explains every paisa of the gap between the bank's closing balance and ours.
 *
 * The permission split is by instrument rather than by route, because the
 * instrument lives on the account and not in the URL: the bank desk needs
 * `bank.reconcile`, the wallet desk `wallets.reconcile`. Where the route cannot
 * know the instrument — anything addressed by reconciliation id — the module
 * floor is on the route and the instrument's own key is asserted here, and the
 * denial is written to the audit trail the same way the route middleware writes
 * its own.
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        protected MoneyAccountService $accounts,
        protected BankStatementImportService $statements,
        protected BankReconciliationService $reconciliations,
        protected PermissionCatalog $catalog,
        protected AuditRecorder $audit,
    ) {}

    /**
     * Where the module stands: every account that can be reconciled, with what
     * has been imported for it and whether it adds up. This is the page the
     * sidebar's reconciliation leaves land on — a leaf cannot carry an account
     * id, and a menu entry that led nowhere would be a dead link with a nicer name.
     */
    public function index(): View
    {
        return $this->hub('bank');
    }

    /** The same hub for the mobile wallets the company holds. */
    public function wallets(): View
    {
        return $this->hub('wallet');
    }

    /** The bank account's statement desk: what has been loaded, and what is left over. */
    public function statement(Request $request, Account $account): View
    {
        $this->assertInstrument($request, $account, 'bank');

        return $this->desk($account, 'bank');
    }

    /** The same desk for a mobile wallet — reconciled from a statement the provider exports (§08-12). */
    public function walletStatement(Request $request, Account $account): View
    {
        $this->assertInstrument($request, $account, 'wallet', 'wallets.accounts');

        return $this->desk($account, 'wallet');
    }

    /** The shape of file the desk reads, as a file, so nobody has to guess the columns. */
    public function statementTemplate(Request $request, Account $account): StreamedResponse
    {
        $this->assertInstrument($request, $account, 'bank');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, $this->statements->templateHeadings());

            foreach ($this->statements->templateRows() as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'statement-template.csv', ['Content-Type' => 'text/csv']);
    }

    /** Import a statement: preview first, then the real run. Whole file or nothing. */
    public function importStatement(ImportBankStatementRequest $request, Account $account): RedirectResponse
    {
        $this->assertInstrument($request, $account, 'bank', 'bank.reconcile');

        return $this->import($request, $account);
    }

    /** The wallet's version of the same import — a statement the provider exported by hand. */
    public function importWalletStatement(ImportBankStatementRequest $request, Account $account): RedirectResponse
    {
        $this->assertInstrument($request, $account, 'wallet', 'wallets.reconcile');

        return $this->import($request, $account);
    }

    /** The reconciliation desk: pick the period, read the bank's closing figure, open it. */
    public function reconcile(Request $request, Account $account): View
    {
        $this->assertInstrument($request, $account, 'bank');

        return $this->desk($account, 'bank', open: true);
    }

    public function walletReconcile(Request $request, Account $account): View
    {
        $this->assertInstrument($request, $account, 'wallet', 'wallets.accounts');

        return $this->desk($account, 'wallet', open: true);
    }

    /** Open a reconciliation: both sides are read, matched and frozen. */
    public function open(OpenReconciliationRequest $request, Account $account): RedirectResponse
    {
        $this->assertInstrument($request, $account, 'bank', 'bank.reconcile');

        return $this->storeOpen($request, $account);
    }

    public function openWallet(OpenReconciliationRequest $request, Account $account): RedirectResponse
    {
        $this->assertInstrument($request, $account, 'wallet', 'wallets.reconcile');

        return $this->storeOpen($request, $account);
    }

    /** The reconciliation itself: the figures, what matched, what is left, and the way to fix it. */
    public function show(Request $request, BankReconciliation $reconciliation): View
    {
        $this->assertOwned($request, $reconciliation);

        $account = $reconciliation->account;
        $this->assertAbility($request, $this->accounts->instrumentOf($account));

        $findings = $this->reconciliations->findings($reconciliation);

        return view('cash-bank.reconciliation', [
            'account' => $account,
            'reconciliation' => $reconciliation,
            'label' => $this->accounts->label($account),
            'instrument' => $this->accounts->instrumentOf($account),
            'findings' => $findings,
            'matched' => $reconciliation->lines()
                ->where('state', ReconciliationLine::STATE_MATCHED)
                ->where('side', ReconciliationLine::SIDE_STATEMENT)
                ->orderBy('movement_date')
                ->get(),
            'walletApiConnected' => BankReconciliationService::WALLET_API_CONNECTED,
        ]);
    }

    /**
     * The closing figure was read off the wrong row: correct it, and the proof is
     * restated. The lines that were compared do not move.
     */
    public function restate(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->assertOwned($request, $reconciliation);
        $this->assertAbility($request, $this->accounts->instrumentOf($reconciliation->account));

        $data = $request->validate([
            'statement_closing' => ['required', 'numeric'],
            'statement_opening' => ['nullable', 'numeric'],
        ]);

        try {
            $restated = $this->reconciliations->restate(
                $reconciliation,
                $request->user(),
                $data['statement_closing'],
                $data['statement_opening'] ?? null,
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->refuse($reconciliation, $e);
        }

        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $restated->id])
            ->with(
                'status',
                $restated->isProved()
                    ? 'Restated: the bank\'s figure now agrees with the books, and the leftovers still explain the rest.'
                    : 'Restated. '.number_format(abs((float) $restated->difference), 2).' is still unexplained.',
            );
    }

    /** Pair a statement line with a book line by hand, when the rule could not. */
    public function match(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->assertOwned($request, $reconciliation);
        $this->assertAbility($request, $this->accounts->instrumentOf($reconciliation->account));

        $data = $request->validate([
            'book_line_id' => ['required', 'integer'],
            'statement_line_id' => ['required', 'integer'],
        ]);

        $lines = ReconciliationLine::query()
            ->where('company_id', $reconciliation->company_id)
            ->where('reconciliation_id', $reconciliation->id)
            ->whereIn('id', [$data['book_line_id'], $data['statement_line_id']])
            ->get()
            ->keyBy('id');

        try {
            $this->reconciliations->matchByHand(
                $reconciliation,
                $lines->get((int) $data['book_line_id']) ?? $this->missingLine(),
                $lines->get((int) $data['statement_line_id']) ?? $this->missingLine(),
                $request->user(),
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->refuse($reconciliation, $e);
        }

        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id])
            ->with('status', 'Matched by hand. The proof has been restated.');
    }

    /** Undo a hand-made match while the reconciliation is still open. */
    public function unmatch(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->assertOwned($request, $reconciliation);
        $this->assertAbility($request, $this->accounts->instrumentOf($reconciliation->account));

        $data = $request->validate([
            'line_id' => ['required', 'integer'],
        ]);

        $line = ReconciliationLine::query()
            ->where('company_id', $reconciliation->company_id)
            ->where('reconciliation_id', $reconciliation->id)
            ->find($data['line_id']) ?? $this->missingLine();

        try {
            $this->reconciliations->unmatch($reconciliation, $line, $request->user());
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->refuse($reconciliation, $e);
        }

        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id])
            ->with('status', 'Pair undone — those two lines are back on their own sides.');
    }

    /** Sign it off: only when it adds up, and never by the person who prepared it. */
    public function signOff(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->assertOwned($request, $reconciliation);
        $this->assertAbility($request, $this->accounts->instrumentOf($reconciliation->account));

        try {
            $signed = $this->reconciliations->signOff($reconciliation, $request->user());
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->refuse($reconciliation, $e);
        }

        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $signed->id])
            ->with('status', 'Signed off '.$signed->label().' — the bank and the books agree, and the leftovers explain the rest.');
    }

    /** @return View */
    protected function hub(string $instrument): View
    {
        $rows = [];

        foreach ($this->accounts->accountsOf($instrument) as $account) {
            $window = $this->statements->window($account);
            $position = $this->accounts->positionFor($account);

            $rows[] = [
                'account' => $account,
                'label' => $this->accounts->label($account),
                'balance' => $position['balance'],
                'lines' => $window['lines'],
                'from' => $window['from'],
                'to' => $window['to'],
                'unmatched' => $this->statements->unmatched($account)->count(),
                'last' => $this->reconciliations->recent($account, 1)->first(),
            ];
        }

        return view('cash-bank.reconciliations-index', [
            'instrument' => $instrument,
            'rows' => $rows,
            'walletApiConnected' => BankReconciliationService::WALLET_API_CONNECTED,
        ]);
    }

    /** @return View */
    protected function desk(Account $account, string $instrument, bool $open = false): View
    {
        $window = $this->statements->window($account);
        $closing = $this->statements->statementClosing($account, $window['from'], $window['to']);
        $lastReconciliation = $this->reconciliations->recent($account, 1)->first();

        $defaultFrom = $lastReconciliation?->period_end?->copy()->addDay()->toDateString()
            ?? $window['from']
            ?? now()->startOfMonth()->toDateString();

        return view('cash-bank.reconcile', [
            'account' => $account,
            'label' => $this->accounts->label($account),
            'instrument' => $instrument,
            'position' => $this->accounts->positionFor($account),
            'window' => $window,
            'statementClosing' => $closing,
            // The desk lists what was imported; a year of statements is thousands
            // of rows, so the list is the latest slice and the count is the truth.
            'statementLines' => $this->statements->lines($account)->take(-300)->values(),
            'statementLineTotal' => $window['lines'],
            'unmatched' => $this->statements->unmatched($account),
            'runs' => $this->statements->runs($account),
            'history' => $this->reconciliations->recent($account),
            'defaults' => [
                'period_start' => $defaultFrom,
                'period_end' => $window['to'] ?? now()->toDateString(),
                'statement_closing' => $closing,
            ],
            'showOpenForm' => $open,
            'walletApiConnected' => BankReconciliationService::WALLET_API_CONNECTED,
            'preview' => session('statement_preview'),
            'rejected' => session('statement_errors'),
            'routePrefix' => $instrument === 'wallet' ? 'cash-bank.wallets' : 'cash-bank',
        ]);
    }

    protected function import(Request $request, Account $account): RedirectResponse
    {
        $file = $request->file('file');
        $preview = (bool) $request->boolean('preview');
        $deskRoute = $this->accounts->instrumentOf($account) === 'wallet'
            ? 'cash-bank.wallets.statement'
            : 'cash-bank.statement';

        try {
            $read = $preview ? $this->statements->read($file) : null;

            $run = $this->statements->import($account, $request->user(), $file, $preview);
        } catch (StatementRejectedException $e) {
            $reasons = array_slice($e->reasons, 0, 12);

            return redirect()->route($deskRoute, ['account' => $account->id])
                ->withErrors(['statement' => $e->getMessage()])
                ->with('statement_errors', $reasons);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return redirect()->route($deskRoute, ['account' => $account->id])
                ->withErrors(['statement' => $e->getMessage()]);
        }

        if ($preview) {
            return redirect()->route($deskRoute, ['account' => $account->id])
                ->with('status', 'Read '.number_format((int) $run->row_count).' row(s) — nothing is stored until you import. Check the dates and amounts below.')
                ->with('statement_preview', [
                    'file' => $run->file_name,
                    'rows' => (int) $run->row_count,
                    'skipped' => $read['skipped'] ?? [],
                    'ignored' => $read['ignored'] ?? [],
                    'errors' => $run->errors ?? [],
                ]);
        }

        return redirect()->route($deskRoute, ['account' => $account->id])
            ->with('status', 'Imported '.number_format((int) $run->imported_count).' statement line(s) from '.$run->file_name.' — now reconcile the period.');
    }

    protected function storeOpen(Request $request, Account $account): RedirectResponse
    {
        $deskRoute = $this->accounts->instrumentOf($account) === 'wallet'
            ? 'cash-bank.wallets.reconcile'
            : 'cash-bank.reconcile';

        $data = $request->validated();

        // The bank's own arithmetic beats the desk's: where the statement carries
        // a running balance, its opening figure is read off the statement rather
        // than derived from the closing balance the clerk typed.
        $data['statement_opening'] ??= $this->statements->statementOpening(
            $account,
            (string) $data['period_start'],
            (string) $data['period_end'],
        );

        try {
            $reconciliation = $this->reconciliations->open($account, $request->user(), $data);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return redirect()->route($deskRoute, ['account' => $account->id])
                ->withErrors(['reconciliation' => $e->getMessage()])
                ->withInput();
        }

        $message = $reconciliation->isProved()
            ? 'Everything is explained: the bank and the books agree to the paisa.'
            : 'Opened. '.$reconciliation->unmatched_statement_count.' statement line(s) and '
                .$reconciliation->unmatched_book_count.' book line(s) are left over, and '
                .number_format(abs((float) $reconciliation->difference), 2).' is still unexplained.';

        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id])
            ->with('status', $message);
    }

    protected function refuse(BankReconciliation $reconciliation, RuntimeException $error): RedirectResponse
    {
        return redirect()
            ->route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id])
            ->withErrors(['reconciliation' => $error->getMessage()]);
    }

    /** The money desk only reconciles what a statement exists for: a bank account, or a wallet. */
    protected function assertInstrument(Request $request, Account $account, string $instrument, string $ability = 'bank.view'): void
    {
        abort_unless((int) $account->company_id === (int) $request->user()->company_id, 404);
        abort_unless($this->accounts->isMoney($account), 404, 'That account is not a cash, bank or wallet account.');

        $actual = $this->accounts->instrumentOf($account);

        abort_unless(
            $actual === $instrument,
            404,
            $actual === 'cash'
                ? 'A cash drawer is counted, not reconciled against a statement — that desk is not built yet.'
                : 'That account is not a '.$instrument.' account.',
        );

        $this->assertAbility($request, $actual, $ability);
    }

    /**
     * The instrument's own key, checked here because the instrument lives on the
     * account rather than in the URL. A refusal is written to the audit trail in
     * the same shape the route middleware writes it.
     */
    protected function assertAbility(Request $request, string $instrument, string $ability = 'bank.reconcile'): void
    {
        $key = $instrument === 'wallet' ? 'wallets.reconcile' : $ability;
        $user = $request->user();

        if ($user !== null && $this->catalog->allows($user, $key)) {
            return;
        }

        $this->audit->record([
            'action' => 'permission.denied',
            'entity_type' => 'permission',
            'entity_id' => null,
            'actor_id' => $user?->id,
            'result' => 'denied',
            'reason' => "missing permission: {$key}",
            'after' => [
                'path' => $request->path(),
                'method' => $request->method(),
                'permission' => $key,
            ],
        ]);

        abort(403, 'You do not have permission to reconcile this account.');
    }

    protected function assertOwned(Request $request, BankReconciliation $reconciliation): void
    {
        abort_unless(
            (int) $reconciliation->company_id === (int) $request->user()->company_id,
            404,
        );
    }

    protected function missingLine(): never
    {
        abort(404, 'That line is not part of this reconciliation.');
    }

}
