<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\CashBank\Services\MoneyMovementService;
use App\Domain\Foundation\Branch;
use App\Domain\Masters\Customer;
use App\Domain\Masters\Supplier;
use App\Http\Requests\RecordMoneyMovementRequest;
use App\Http\Requests\StoreMoneyAccountRequest;
use App\Http\Requests\TransferMoneyRequest;
use App\Http\Requests\UpdateMoneyAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash & bank (§08-01 … §08-07, §08-11) — the desk where money the ledger has to
 * see is entered.
 *
 * Everything on these screens is derived: balances come from posted journal
 * lines, the books are the general ledger filtered to one account, and the
 * running balance comes from the same service the general-ledger screen uses.
 * So this desk cannot disagree with the books — it can only fail to have been
 * told about a movement, which is why every screen names the account and the
 * date it was told about.
 */
class CashBankController extends Controller
{
    public function __construct(
        protected MoneyAccountService $accounts,
        protected MoneyMovementService $money,
        protected LedgerService $ledger,
    ) {}

    /** Where the money is: cash in hand, banks, wallets, and the movement around them. */
    public function index(Request $request): View
    {
        $branches = $this->branches($request);
        $branchId = $this->branchFilter($request, $branches);

        $positions = $this->accounts->positions($branchId);
        $totals = $this->accounts->totals($branchId);

        return view('cash-bank.index', [
            'positions' => $positions,
            'totals' => $totals,
            'branches' => $branches,
            'filters' => ['branch' => $branchId, 'scope' => $branchId === null ? 'all' : 'branch'],
            'movement' => $this->money->dailyNet(14),
            'recentReceipts' => $this->money->receipts(8),
            'recentPayments' => $this->money->payments(8),
            'recentTransfers' => $this->money->transfers(8),
            'accountsConfigured' => $positions !== [],
        ]);
    }

    /** The registry of accounts money can sit in (§08-06, §08-11). */
    public function accounts(Request $request): View
    {
        $rows = $this->accounts->positions();

        return view('cash-bank.accounts', [
            'positions' => $rows,
            'totals' => $this->accounts->totals(),
            'instruments' => MoneyAccountService::INSTRUMENTS,
            'providers' => MoneyAccountService::WALLET_PROVIDERS,
            'canManageWallets' => (bool) $request->user()->can('wallets.accounts'),
        ]);
    }

    public function storeAccount(StoreMoneyAccountRequest $request): RedirectResponse
    {
        try {
            $account = $this->accounts->create($request->validated(), $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.accounts')
            ->with('status', $account->name.' is open — '.MoneyAccountService::INSTRUMENTS[$this->accounts->instrumentOf($account)].' account '.$account->code.'.');
    }

    public function editAccount(Request $request, Account $account): View
    {
        $this->assertMoneyAccount($request, $account);

        return view('cash-bank.account-edit', [
            'position' => $this->accounts->positionFor($account),
            'providers' => MoneyAccountService::WALLET_PROVIDERS,
            'movements' => $this->accounts->movementCount($account),
            'recent' => $this->ledger->accountLedger($account, null, null, null)['rows'],
        ]);
    }

    public function updateAccount(UpdateMoneyAccountRequest $request, Account $account): RedirectResponse
    {
        $this->assertMoneyAccount($request, $account);

        try {
            $this->accounts->update($account, $request->validated(), $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.accounts')
            ->with('status', $account->refresh()->name.' updated. Its code and its instrument are unchanged — history stays where it was written.');
    }

    public function closeAccount(Request $request, Account $account): RedirectResponse
    {
        $this->assertMoneyAccount($request, $account);

        try {
            $closed = $this->accounts->deactivate($account, $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.accounts')
            ->with('status', $closed->name.' is closed to new movement. Its history stays in the books.');
    }

    /** Money in (§08-02). */
    public function receipts(Request $request): View
    {
        return view('cash-bank.receipts', [
            'moneyAccounts' => $this->accounts->accounts()->where('is_active', true)->values(),
            'counterAccounts' => $this->counterAccounts($request, 'in'),
            'customers' => Customer::query()
                ->where('company_id', $request->user()->company_id)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'code']),
            'receipts' => $this->money->receipts(50),
            'window' => $this->money->dailyNet(14),
        ]);
    }

    public function storeReceipt(RecordMoneyMovementRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $payment = $this->money->receive([
                'money_account_id' => (int) $data['money_account_id'],
                'counter_account_id' => (int) $data['counter_account_id'],
                'amount' => $data['amount'],
                'received_on' => $data['moved_on'] ?? null,
                'payer' => $data['party_name'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ], $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.receipts')
            ->with('status', 'Receipt '.$payment->receipt_no.' recorded — '.number_format((float) $payment->amount, 2).' into '.$payment->account?->name.'.');
    }

    /** Money out (§08-03). */
    public function payments(Request $request): View
    {
        return view('cash-bank.payments', [
            'moneyAccounts' => $this->accounts->accounts()->where('is_active', true)->values(),
            'counterAccounts' => $this->counterAccounts($request, 'out'),
            'suppliers' => Supplier::query()
                ->where('company_id', $request->user()->company_id)
                ->orderBy('name')->limit(300)->get(['id', 'name', 'code']),
            'payments' => $this->money->payments(50),
            'window' => $this->money->dailyNet(14),
        ]);
    }

    public function storePayment(RecordMoneyMovementRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $payment = $this->money->pay([
                'money_account_id' => (int) $data['money_account_id'],
                'counter_account_id' => (int) $data['counter_account_id'],
                'amount' => $data['amount'],
                'paid_on' => $data['moved_on'] ?? null,
                'payee' => $data['party_name'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ], $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.payments')
            ->with('status', 'Voucher '.$payment->receipt_no.' recorded — '.number_format((float) $payment->amount, 2).' out of '.$payment->account?->name.'.');
    }

    /** Money between the company's own accounts (§08-04). */
    public function transfer(Request $request): View
    {
        return view('cash-bank.transfer', [
            'moneyAccounts' => $this->accounts->accounts()->where('is_active', true)->values(),
            'transfers' => $this->money->transfers(50),
        ]);
    }

    public function storeTransfer(TransferMoneyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $transfer = $this->money->transfer([
                'from_account_id' => (int) $data['from_account_id'],
                'to_account_id' => (int) $data['to_account_id'],
                'amount' => $data['amount'],
                'transferred_on' => $data['transferred_on'] ?? null,
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ], $request->user()->id);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['cash_bank' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.transfer')
            ->with('status', 'Transfer '.$transfer->transfer_no.' posted — '.number_format((float) $transfer->amount, 2).' from '.$transfer->fromAccount?->name.' to '.$transfer->toAccount?->name.'.');
    }

    /**
     * One account's book (§08-07): the general ledger, filtered to the account a
     * bank statement is about, with the statement's own question answered at the
     * bottom — what the ledger says is there, and what moved.
     */
    public function book(Request $request, Account $account): View|StreamedResponse
    {
        $this->assertMoneyAccount($request, $account);

        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $ledger = $this->ledger->accountLedger($account, $from, $to, null);
        $position = $this->accounts->positionFor($account);

        if ($this->wantsCsv($request)) {
            return $this->csv(
                $account->code.'-book',
                $ledger['rows'],
                ['Date', 'Entry', 'Description', 'Debit', 'Credit', 'Running balance'],
                fn (array $row) => [
                    $row['entry_date'] ?? '',
                    $row['entry_no'] ?? 'Opening',
                    $row['description'] ?? '',
                    $row['debit'] ?? '',
                    $row['credit'] ?? '',
                    $row['running_balance'] ?? '',
                ],
                [
                    'account' => $account->code.' '.$account->name,
                    'opening' => $ledger['rows'][0]['running_balance'] ?? '0.0000',
                    'total_debit' => $ledger['debit_total'],
                    'total_credit' => $ledger['credit_total'],
                    'closing' => $ledger['balance'],
                ],
            );
        }

        return view('cash-bank.book', [
            'account' => $account,
            'position' => $position,
            'instrument' => $this->accounts->instrumentOf($account),
            'label' => $this->accounts->label($account),
            'rows' => $ledger['rows'],
            'debitTotal' => $ledger['debit_total'],
            'creditTotal' => $ledger['credit_total'],
            'closing' => $ledger['balance'],
            'opening' => $ledger['rows'][0]['running_balance'] ?? '0.0000',
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    /** The accounts a receipt or a payment may be posted against. */
    protected function counterAccounts(Request $request, string $direction): array
    {
        $types = $direction === 'in'
            ? ['revenue', 'liability', 'asset']     // income, a customer's account, an advance, a loan
            : ['expense', 'liability', 'asset'];    // a cost, a supplier's account, an asset bought

        $moneyIds = $this->accounts->accounts()->pluck('id')->all();

        return Account::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->where('is_group', false)
            ->whereIn('type', $types)
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

    /** Which branch's money this screen is about; null means the whole company. */
    protected function branchFilter(Request $request, $branches): ?int
    {
        if ($request->query('branch') === 'all' || $request->query('branch') === null) {
            return null;
        }

        $branchId = (int) $request->query('branch');

        return $branches->contains('id', $branchId) ? $branchId : null;
    }

    protected function branches(Request $request)
    {
        return Branch::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    protected function assertMoneyAccount(Request $request, Account $account): void
    {
        abort_unless(
            (int) $account->company_id === (int) $request->user()->company_id,
            404,
        );

        abort_unless($this->accounts->isMoney($account), 404, 'That account is not a cash, bank or wallet account.');
    }

    protected function date(mixed $value): ?Carbon
    {
        return $value === null || trim((string) $value) === '' ? null : Carbon::parse((string) $value);
    }

    protected function wantsCsv(Request $request): bool
    {
        return strtolower((string) $request->query('format')) === 'csv';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $headings
     * @param  callable(array<string, mixed>): array<int, mixed>  $map
     * @param  array<string, string>  $totals
     */
    protected function csv(string $name, array $rows, array $headings, callable $map, array $totals = []): StreamedResponse
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
                    fputcsv($out, [str_replace('_', ' ', ucfirst($label)), $value]);
                }
            }

            fclose($out);
        }, $name.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
