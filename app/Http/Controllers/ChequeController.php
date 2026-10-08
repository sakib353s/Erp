<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\CashBank\Cheque;
use App\Domain\CashBank\Services\ChequeService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\CashBank\Support\AmountInWords;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Purchase\Supplier;
use App\Domain\Sales\Customer;
use App\Http\Requests\ChequeTransitionRequest;
use App\Http\Requests\StoreChequeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * The cheque register (§08-13) and the cheque's printed record (§08-14).
 *
 * The screens are built around one question — what is still hanging over this
 * account? — because that is what a cheque is: money promised, not money moved.
 * Nothing on this desk posts to the ledger until a cheque clears, and the page
 * says so where somebody would otherwise assume the money had arrived.
 */
class ChequeController extends Controller
{
    public function __construct(
        protected ChequeService $cheques,
        protected MoneyAccountService $accounts,
        protected DocumentRenderer $documents,
        protected TenantContext $context,
    ) {}

    /** The register, filtered by the state the menu leaf asked for. */
    public function index(Request $request): View
    {
        $filters = [
            'state' => $this->stringOrNull($request->query('state')),
            'direction' => $this->stringOrNull($request->query('direction')),
            'account' => $request->query('account') !== null ? (int) $request->query('account') : null,
            'q' => $this->stringOrNull($request->query('q')),
        ];

        $accountId = $filters['account'] !== null
            ? $this->moneyAccountOrNull($filters['account'])
            : null;

        $account = $accountId !== null ? Account::query()->find($accountId) : null;

        return view('cash-bank.cheques', [
            'filters' => $filters,
            'filterAccount' => $account,
            'summary' => $this->cheques->summary($accountId),
            'cheques' => $this->cheques->recent(
                state: $filters['state'],
                direction: $filters['direction'],
                accountId: $accountId,
                search: $filters['q'],
            ),
            'states' => Cheque::STATUSES + ['outstanding' => 'Outstanding', 'post_dated' => 'Post-dated', 'failed' => 'Bounced or returned'],
            'directions' => Cheque::DIRECTIONS,
            'moneyAccounts' => $this->accounts->accounts()->where('is_active', true)->values(),
            'counterAccounts' => $this->counterAccounts($request),
            'customers' => Customer::query()
                ->where('company_id', $request->user()->company_id)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'code']),
            'suppliers' => Supplier::query()
                ->where('company_id', $request->user()->company_id)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'code']),
            'outstandingOn' => $account !== null ? $this->cheques->outstanding($account) : collect(),
        ]);
    }

    /** Write a cheque into the register. Nothing is posted — this is a promise. */
    public function store(StoreChequeRequest $request): RedirectResponse
    {
        try {
            $cheque = $this->cheques->record($request->validated(), $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['cheque' => $error->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.cheques.show', ['cheque' => $cheque->id])
            ->with('status', $cheque->label().' is in the register. '
                .($cheque->isPostDated()
                    ? 'It is post-dated to '.$cheque->cheque_date?->toDateString().' — the desk will refuse to deposit or present it early.'
                    : 'Nothing reaches the ledger until the bank pays it.'));
    }

    /** One cheque: where it is in its life, what it will post, and its words. */
    public function show(Request $request, Cheque $cheque): View
    {
        $this->assertOwned($request, $cheque);

        return view('cash-bank.cheque', [
            'cheque' => $cheque->load([
                'account', 'counterAccount', 'customer', 'supplier', 'createdBy', 'reversalEntry',
                'journalEntry.lines.account',
            ]),
            'wordsEn' => AmountInWords::en((string) $cheque->amount),
            'wordsBn' => AmountInWords::bn((string) $cheque->amount),
            'figuresBn' => AmountInWords::bnFigures((string) $cheque->amount),
        ]);
    }

    /**
     * Move a cheque on: deposit or present it, clear it, or record that it failed.
     * The service decides what may follow what, and posts nothing until clearing.
     */
    public function transition(ChequeTransitionRequest $request, Cheque $cheque): RedirectResponse
    {
        $this->assertOwned($request, $cheque);

        $data = $request->validated();
        $on = (string) ($data['on'] ?? now()->toDateString());

        try {
            $cheque = match ($data['action']) {
                'deposit' => $this->cheques->deposit($cheque, $on, $request->user()),
                'present' => $this->cheques->present($cheque, $on, $request->user()),
                'clear' => $this->cheques->clear($cheque, $on, $request->user()),
                'fail' => $this->cheques->fail($cheque, $on, (string) $data['reason'], $request->user()),
            };
        } catch (RuntimeException $error) {
            return back()->withErrors(['cheque' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.cheques.show', ['cheque' => $cheque->id])
            ->with('status', $this->narrate($cheque, $data['action'], $on));
    }

    /**
     * The cheque's printed record: the payee, the figures and — the part a bank
     * reads when the figures are unclear — the amount in words, in both
     * languages this office writes in. Filed as a document and logged like every
     * other printed paper in this application.
     */
    public function print(Request $request, Cheque $cheque): Response
    {
        $this->assertOwned($request, $cheque);

        if (! $cheque->isIssued()) {
            abort(403, 'A cheque the company received is not ours to print. Its record is the deposit line it cleared through.');
        }

        $cheque->load(['account', 'counterAccount', 'createdBy']);

        $html = view('cash-bank.cheque-print', [
            'cheque' => $cheque,
            'wordsEn' => AmountInWords::en((string) $cheque->amount),
            'wordsBn' => AmountInWords::bn((string) $cheque->amount),
            'figuresBn' => AmountInWords::bnFigures((string) $cheque->amount),
            'company' => $request->user()->company,
        ])->render();

        $this->documents->storeGenerated(
            $html,
            (int) $cheque->company_id,
            'cheques',
            'cheque-'.$cheque->cheque_no,
            $cheque,
            'cheque',
            $request->user(),
        );

        $this->documents->recordPrint($cheque, 'cheque', $request);

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ------------------------------------------------------------- internals

    protected function narrate(Cheque $cheque, string $action, string $on): string
    {
        return match ($action) {
            'deposit' => 'Deposited on '.$on.'. The bank has not paid it yet, so the books still have not heard about it.',
            'present' => 'Presented on '.$on.'. Nothing posts until it clears.',
            'clear' => 'Cleared on '.$on.' — entry '.($cheque->journalEntry?->entry_no ?? '').' posted: '
                .number_format((float) $cheque->amount, 2).' into the books, exactly once.',
            'fail' => $cheque->reversal_entry_id !== null
                ? 'Failed on '.$on.'. The clearing entry was reversed — the money has gone back to where it came from.'
                : 'Failed on '.$on.'. Nothing had been posted, so there was nothing to reverse — which is the point.',
        };
    }

    protected function assertOwned(Request $request, Cheque $cheque): void
    {
        abort_unless((int) $cheque->company_id === (int) $request->user()->company_id, 404);
    }

    protected function moneyAccountOrNull(int $id): ?int
    {
        $account = Account::query()
            ->where('company_id', $this->context->companyId())
            ->find($id);

        return $account === null || trim((string) $account->instrument) === '' ? null : (int) $account->id;
    }

    /**
     * What a cheque can settle: anything postable that is not itself an account
     * money sits in. Paying a cheque into another of the company's own accounts
     * is a transfer, and the service says so if somebody tries.
     */
    protected function counterAccounts(Request $request): array
    {
        $moneyIds = $this->accounts->accounts()->pluck('id')->all();

        return Account::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->where('is_group', false)
            ->when($moneyIds !== [], fn ($query) => $query->whereNotIn('id', $moneyIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'label' => $account->code.' — '.$account->name,
                'type' => $account->type,
            ])
            ->all();
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
