<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\PettyCashFund;
use App\Domain\CashBank\PettyCashRequest;
use App\Domain\CashBank\PettyCashTransaction;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §08-21 — the float, the vouchers and the topping-up.
 *
 * Everything this service does to money it does through `MoneyMovementService`,
 * on purpose: a voucher out of the float is a payment (Dr the expense category,
 * Cr the float) and a replenishment is a transfer (Dr the float, Cr the bank), so
 * there is still exactly one money path in the module and the petty cash desk is
 * a *lens* on it rather than a second ledger.
 *
 * Three rules are the whole control story:
 *
 *  · **A float cannot pay out money it does not hold.** The balance is the
 *    ledger's own figure for the fund's account, read at the moment of the
 *    voucher, so a custodian who has spent the tin is told to replenish rather
 *    than quietly owing the company money.
 *  · **Above the limit the money is asked for, not taken.** A request waits with
 *    nothing posted, and the answer comes from somebody other than the person who
 *    asked — the same maker/checker rule the expense desk keeps.
 *  · **A fund holding money cannot be closed.** That is the one state from which
 *    nobody can say where the cash went.
 */
class PettyCashService
{
    /** 0 (or less) means the custodian may record vouchers directly. */
    public const SETTING_GROUP = 'cash';

    public const SETTING_KEY = 'petty_cash_approval_above';

    public function __construct(
        protected MoneyMovementService $movements,
        protected MoneyAccountService $accounts,
        protected SettingService $settings,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    // --------------------------------------------------------- configuration

    /** The amount at or above which a voucher has to be asked for first. */
    public function threshold(): string
    {
        $value = $this->settings->get(self::SETTING_GROUP, self::SETTING_KEY, '0');

        return is_numeric($value) ? (string) $value : '0';
    }

    public function mustApprove(float $amount): bool
    {
        $threshold = (float) $this->threshold();

        return $threshold > 0.0 && round($amount, 4) >= round($threshold, 4);
    }

    // ------------------------------------------------------------------ funds

    /** @return Collection<int, PettyCashFund> */
    public function funds(bool $activeOnly = false): Collection
    {
        return PettyCashFund::query()
            ->where('company_id', $this->companyId())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with(['account', 'custodian', 'branch'])
            ->withCount([
                'requests as pending_requests_count' => fn ($query) => $query->where('status', PettyCashRequest::STATUS_PENDING),
            ])
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Every fund's balance in one pass: the float positions are the ledger's
     * figures for the funds' own accounts, not a column this desk maintains.
     *
     * @param  Collection<int, PettyCashFund>  $funds
     * @return array<int, string> fund id → balance
     */
    public function balances(Collection $funds): array
    {
        $byAccount = [];

        foreach ($this->accounts->positions() as $position) {
            $byAccount[$position['account']->id] = $position['balance'];
        }

        $balances = [];

        foreach ($funds as $fund) {
            $balances[$fund->id] = $byAccount[$fund->account_id] ?? '0.0000';
        }

        return $balances;
    }

    public function balanceOf(PettyCashFund $fund): string
    {
        $account = $fund->account;

        if ($account === null) {
            return '0.0000';
        }

        return $this->accounts->balanceOf($account);
    }

    /**
     * How much has to be put back to restore the level the fund is meant to hold.
     * Negative figures are impossible by definition: an over-full float owes
     * nothing, so the desk shows zero rather than a number to subtract.
     */
    public function shortfallOf(PettyCashFund $fund, ?string $balance = null): string
    {
        $balance = (float) ($balance ?? $this->balanceOf($fund));
        $shortfall = (float) $fund->imprest_amount - $balance;

        return number_format(max(0.0, $shortfall), 2, '.', '');
    }

    /**
     * Declare a float. The money side is a real ledger leaf: the fund gets its
     * own cash account under Current Assets, named after it, so the balance on
     * the desk is the balance in the books.
     *
     * @param  array{code:string, name:string, custodian_id:int, imprest_amount:string|float,
     *               account_code?:string|null, branch_id?:int|null, description?:string|null,
     *               opened_on?:string|null, currency?:string|null, is_active?:bool}  $data
     */
    public function saveFund(array $data, ?PettyCashFund $fund = null, ?User $actor = null): PettyCashFund
    {
        $companyId = $this->companyId();

        $code = strtoupper(trim((string) $data['code']));
        $name = trim((string) $data['name']);

        if ($code === '' || $name === '') {
            throw new RuntimeException('A float needs a code and a name: the ledger has to be able to name the drawer.');
        }

        $custodian = User::query()
            ->where('company_id', $companyId)
            ->find((int) $data['custodian_id']);

        if ($custodian === null) {
            throw new RuntimeException('Name the custodian — the person answerable for the cash in the tin.');
        }

        $imprest = round((float) $data['imprest_amount'], 4);

        if ($imprest <= 0) {
            throw new RuntimeException('A float without a level has nothing to replenish back to. Say how much it is meant to hold.');
        }

        if ($fund === null) {
            if (PettyCashFund::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
                throw new RuntimeException("A float with the code {$code} already exists in this company.");
            }

            return DB::transaction(function () use ($data, $companyId, $code, $name, $imprest, $custodian, $actor) {
                // The drawer is a money account: whoever runs the cash desk can
                // see it, and every voucher out of it goes through the same
                // payment path as any other cash payment.
                $account = $this->accounts->create([
                    'instrument' => 'cash',
                    'code' => trim((string) ($data['account_code'] ?? '')) ?: $this->nextAccountCode($code),
                    'name' => $name.' (petty cash)',
                    'description' => 'Petty cash float'.($data['description'] ?? null ? ' — '.$data['description'] : ''),
                    'currency' => strtoupper((string) ($data['currency'] ?? 'BDT')),
                ], $actor?->id);

                $fund = PettyCashFund::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $data['branch_id'] ?? $this->context->branchId(),
                    'code' => $code,
                    'name' => $name,
                    'custodian_id' => $custodian->id,
                    'account_id' => $account->id,
                    'imprest_amount' => $imprest,
                    'currency' => strtoupper((string) ($data['currency'] ?? 'BDT')),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'opened_on' => $data['opened_on'] ?? now()->toDateString(),
                    'description' => $this->nullIfBlank($data['description'] ?? null),
                    'created_by' => $actor?->id,
                ]);

                $this->audit->record([
                    'action' => 'petty_cash.fund_declared',
                    'entity_type' => 'petty_cash_fund',
                    'entity_id' => $fund->id,
                    'branch_id' => $fund->branch_id,
                    'actor_id' => $actor?->id,
                    'after' => [
                        'code' => $fund->code,
                        'name' => $fund->name,
                        'custodian' => $custodian->name,
                        'account' => $account->code.' — '.$account->name,
                        'imprest' => (string) $imprest,
                    ],
                ]);

                return $fund->load(['account', 'custodian']);
            });
        }

        // The account and the code are fixed once there is a ledger leaf behind
        // them: renaming the drawer is fine, moving it to another account would
        // silently re-point every voucher already posted.
        $fund->fill([
            'name' => $name,
            'custodian_id' => $custodian->id,
            'imprest_amount' => $imprest,
            'description' => $this->nullIfBlank($data['description'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->save();

        $this->audit->record([
            'action' => 'petty_cash.fund_updated',
            'entity_type' => 'petty_cash_fund',
            'entity_id' => $fund->id,
            'branch_id' => $fund->branch_id,
            'actor_id' => $actor?->id,
            'after' => ['code' => $fund->code, 'imprest' => (string) $fund->imprest_amount, 'custodian' => $custodian->name],
        ]);

        return $fund->load(['account', 'custodian']);
    }

    /**
     * Close a float. Refused while it holds money or while anybody is waiting for
     * an answer on it: both are states in which closing the drawer would lose
     * the question "where did the cash go?".
     */
    public function closeFund(PettyCashFund $fund, ?User $actor = null): PettyCashFund
    {
        $this->accounts->assertOwned($fund->account);

        $balance = (float) $this->balanceOf($fund);

        if (abs($balance) > 0.0001) {
            throw new RuntimeException('This float still holds '.number_format($balance, 2).'. Spend it, put it back where it came from, or replenish it to zero — a drawer with cash in it cannot be closed.');
        }

        $pending = PettyCashRequest::query()
            ->where('fund_id', $fund->id)
            ->where('status', PettyCashRequest::STATUS_PENDING)
            ->count();

        if ($pending > 0) {
            throw new RuntimeException("Somebody is still waiting for an answer on {$pending} request(s) against this float. Decide them, then close it.");
        }

        $fund->forceFill(['is_active' => false, 'closed_on' => now()->toDateString()])->save();

        $this->audit->record([
            'action' => 'petty_cash.fund_closed',
            'entity_type' => 'petty_cash_fund',
            'entity_id' => $fund->id,
            'branch_id' => $fund->branch_id,
            'actor_id' => $actor?->id,
            'after' => ['code' => $fund->code, 'balance' => $this->balanceOf($fund), 'closed_on' => $fund->closed_on?->toDateString()],
        ]);

        return $fund;
    }

    // --------------------------------------------------------------- vouchers

    /**
     * Ask for money out of the float. Nothing is posted, nothing is paid, and the
     * request waits for somebody else's answer.
     *
     * @param  array{fund_id:int, expense_category_id:int, payee:string, amount:string|float,
     *               needed_on:string, narration?:string|null}  $data
     */
    public function ask(array $data, User $actor): PettyCashRequest
    {
        $fund = $this->fundOrFail((int) $data['fund_id']);
        $category = $this->categoryOrFail((int) $data['expense_category_id']);
        $amount = $this->amount($data['amount'] ?? 0);
        $payee = trim((string) $data['payee']);

        if ($payee === '') {
            throw new RuntimeException('Name who is to be paid: a request without a payee cannot be checked by anybody.');
        }

        $request = PettyCashRequest::query()->create([
            'company_id' => $this->companyId(),
            'branch_id' => $fund->branch_id,
            'fund_id' => $fund->id,
            'expense_category_id' => $category->id,
            'requested_by' => $actor->id,
            'payee' => $payee,
            'narration' => $this->nullIfBlank($data['narration'] ?? null),
            'needed_on' => $data['needed_on'] ?? now()->toDateString(),
            'amount' => $amount,
            'status' => PettyCashRequest::STATUS_PENDING,
            'created_by' => $actor->id,
        ]);

        $this->audit->record([
            'action' => 'petty_cash.requested',
            'entity_type' => 'petty_cash_request',
            'entity_id' => $request->id,
            'branch_id' => $request->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'fund' => $fund->name,
                'payee' => $payee,
                'category' => $category->name,
                'amount' => $amount,
                'threshold' => $this->threshold(),
            ],
        ]);

        return $request->load(['fund', 'category', 'requester']);
    }

    /**
     * Approve a request: now, and only now, the voucher exists and the money
     * moves. Maker ≠ checker — the person who asked cannot be the answer.
     */
    public function approve(PettyCashRequest $request, User $actor, ?string $note = null): PettyCashTransaction
    {
        $this->assertPending($request);
        $this->assertNotSelf($request, $actor);

        return DB::transaction(function () use ($request, $actor, $note) {
            $transaction = $this->disburse(
                $request->fund,
                $request->category,
                $request->payee,
                (float) $request->amount,
                $request->needed_on->toDateString(),
                $request->narration,
                $actor,
                $request,
            );

            $request->forceFill([
                'status' => PettyCashRequest::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $this->nullIfBlank($note),
                'payment_id' => $transaction->payment_id,
            ])->save();

            $this->audit->record([
                'action' => 'petty_cash.request_approved',
                'entity_type' => 'petty_cash_request',
                'entity_id' => $request->id,
                'branch_id' => $request->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'payee' => $request->payee,
                    'amount' => (string) $request->amount,
                    'voucher' => $transaction->documentNo(),
                    'requested_by' => $request->requester?->name,
                ],
            ]);

            return $transaction;
        });
    }

    /** Refuse a request. Nothing is posted, and the refusal is written down. */
    public function reject(PettyCashRequest $request, User $actor, ?string $note = null): PettyCashRequest
    {
        $this->assertPending($request);
        $this->assertNotSelf($request, $actor);

        $request->forceFill([
            'status' => PettyCashRequest::STATUS_REJECTED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $this->nullIfBlank($note),
        ])->save();

        $this->audit->record([
            'action' => 'petty_cash.request_rejected',
            'entity_type' => 'petty_cash_request',
            'entity_id' => $request->id,
            'branch_id' => $request->branch_id,
            'actor_id' => $actor->id,
            'reason' => $this->nullIfBlank($note),
            'after' => ['payee' => $request->payee, 'amount' => (string) $request->amount],
        ]);

        return $request;
    }

    /**
     * Pay a voucher out of the float: Dr the category's account, Cr the float,
     * through the same payment path as any other cash payment — and only for
     * money the float actually holds.
     *
     * @param  array{fund_id:int, expense_category_id:int, payee:string, amount:string|float,
     *               spent_on:string, narration?:string|null, idempotency_key?:string|null}  $data
     */
    public function payVoucher(array $data, User $actor): PettyCashTransaction
    {
        $fund = $this->fundOrFail((int) $data['fund_id']);
        $category = $this->categoryOrFail((int) $data['expense_category_id']);

        return $this->disburse(
            $fund,
            $category,
            trim((string) $data['payee']),
            (float) ($data['amount'] ?? 0),
            (string) ($data['spent_on'] ?? now()->toDateString()),
            $data['narration'] ?? null,
            $actor,
            null,
            $data['idempotency_key'] ?? null,
        );
    }

    /**
     * Put the float back up to its level, from an account the company actually
     * holds money in. A transfer, not an expense: the spending was recorded when
     * each voucher was paid, and doing it twice here would double every figure.
     *
     * @param  array{fund_id:int, source_account_id:int, amount:string|float,
     *               replenished_on:string, narration?:string|null}  $data
     */
    public function replenish(array $data, User $actor): PettyCashTransaction
    {
        $fund = $this->fundOrFail((int) $data['fund_id']);

        $source = Account::query()
            ->where('company_id', $this->companyId())
            ->find((int) $data['source_account_id']);

        if ($source === null) {
            throw new RuntimeException('Choose the account the money comes from — a top-up has to come from somewhere.');
        }

        $this->accounts->assertOwned($source);

        if (! $this->accounts->isMoney($source)) {
            throw new RuntimeException("Money cannot come out of [{$source->name}] — that is not a cash, bank or wallet account.");
        }

        if ((int) $source->id === (int) $fund->account_id) {
            throw new RuntimeException('That is the float itself — a drawer cannot replenish itself.');
        }

        $amount = $this->amount($data['amount'] ?? 0);
        $date = (string) ($data['replenished_on'] ?? now()->toDateString());

        return DB::transaction(function () use ($fund, $source, $amount, $date, $data, $actor) {
            $transfer = $this->movements->transfer([
                'from_account_id' => $source->id,
                'to_account_id' => $fund->account_id,
                'amount' => $amount,
                'transferred_on' => $date,
                'narration' => $this->nullIfBlank($data['narration'] ?? null) ?? "Petty cash replenishment — {$fund->name}",
                'branch_id' => $fund->branch_id,
            ], $actor->id);

            $transaction = PettyCashTransaction::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $fund->branch_id,
                'fund_id' => $fund->id,
                'kind' => PettyCashTransaction::KIND_REPLENISHMENT,
                'occurred_on' => $date,
                'amount' => $amount,
                'transfer_id' => $transfer->id,
                'narration' => $this->nullIfBlank($data['narration'] ?? null),
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'action' => 'petty_cash.replenished',
                'entity_type' => 'petty_cash_transaction',
                'entity_id' => $transaction->id,
                'branch_id' => $fund->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'fund' => $fund->name,
                    'from' => $source->name,
                    'transfer' => $transfer->transfer_no,
                    'amount' => $amount,
                    'balance_after' => $this->balanceOf($fund),
                ],
            ]);

            return $transaction->load(['fund', 'transfer']);
        });
    }

    // ------------------------------------------------------------------ lists

    /**
     * The vouchers, newest first, with the filters the leaves ask for.
     *
     * @param  array{fund?:int|null, category?:int|null, from?:string|null, to?:string|null}  $filters
     * @return Collection<int, PettyCashTransaction>
     */
    public function vouchers(array $filters = [], int $limit = 100): Collection
    {
        return PettyCashTransaction::query()
            ->where('company_id', $this->companyId())
            ->where('kind', PettyCashTransaction::KIND_DISBURSEMENT)
            ->when(($filters['fund'] ?? null) !== null, fn ($query) => $query->where('fund_id', $filters['fund']))
            ->when(($filters['category'] ?? null) !== null, fn ($query) => $query->where('expense_category_id', $filters['category']))
            ->when(($filters['from'] ?? null) !== null, fn ($query) => $query->whereDate('occurred_on', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($query) => $query->whereDate('occurred_on', '<=', $filters['to']))
            ->with(['fund', 'category.account', 'payment', 'request.requester', 'creator'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, PettyCashTransaction> */
    public function replenishments(?int $fundId = null, int $limit = 50): Collection
    {
        return PettyCashTransaction::query()
            ->where('company_id', $this->companyId())
            ->where('kind', PettyCashTransaction::KIND_REPLENISHMENT)
            ->when($fundId !== null, fn ($query) => $query->where('fund_id', $fundId))
            ->with(['fund', 'transfer'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{status?:string|null, fund?:int|null}  $filters
     * @return Collection<int, PettyCashRequest>
     */
    public function requests(array $filters = [], int $limit = 100): Collection
    {
        return PettyCashRequest::query()
            ->where('company_id', $this->companyId())
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['fund'] ?? null) !== null, fn ($query) => $query->where('fund_id', $filters['fund']))
            ->with(['fund', 'category.account', 'requester', 'decider', 'payment'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PettyCashRequest::STATUS_PENDING])
            ->orderByDesc('needed_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // ---------------------------------------------------------------- figures

    /**
     * The figures the desk opens with. Every number is either the ledger's own or
     * a count over these tables — nothing here is estimated.
     *
     * @return array{funds:int, active:int, float:string, imprest:string, shortfall:string,
     *               pending:int, pending_value:string, paid_this_month:string, paid_count:int,
     *               replenished_this_month:string}
     */
    public function summary(): array
    {
        $funds = $this->funds();
        $balances = $this->balances($funds);

        $float = 0.0;
        $imprest = 0.0;
        $shortfall = 0.0;

        foreach ($funds as $fund) {
            if (! $fund->isActive()) {
                continue;
            }

            $balance = (float) ($balances[$fund->id] ?? 0);
            $float += $balance;
            $imprest += (float) $fund->imprest_amount;
            $shortfall += (float) $this->shortfallOf($fund, $balances[$fund->id] ?? '0');
        }

        $pending = PettyCashRequest::query()
            ->where('company_id', $this->companyId())
            ->where('status', PettyCashRequest::STATUS_PENDING);

        $thisMonth = PettyCashTransaction::query()
            ->where('company_id', $this->companyId())
            ->whereDate('occurred_on', '>=', now()->startOfMonth()->toDateString())->whereDate('occurred_on', '<=', now()->endOfMonth()->toDateString());

        $paid = (clone $thisMonth)->where('kind', PettyCashTransaction::KIND_DISBURSEMENT);
        $topped = (clone $thisMonth)->where('kind', PettyCashTransaction::KIND_REPLENISHMENT);

        return [
            'funds' => $funds->count(),
            'active' => $funds->where('is_active', true)->count(),
            'float' => number_format($float, 2, '.', ''),
            'imprest' => number_format($imprest, 2, '.', ''),
            'shortfall' => number_format($shortfall, 2, '.', ''),
            'pending' => (clone $pending)->count(),
            'pending_value' => number_format((float) (clone $pending)->sum('amount'), 2, '.', ''),
            'paid_this_month' => number_format((float) (clone $paid)->sum('amount'), 2, '.', ''),
            'paid_count' => (clone $paid)->count(),
            'replenished_this_month' => number_format((float) (clone $topped)->sum('amount'), 2, '.', ''),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * The one place a voucher is paid. Private because every caller has already
     * decided *why* it is allowed to happen — an approval that was signed, or a
     * custodian under the limit — and that decision is the part worth reading.
     */
    protected function disburse(
        PettyCashFund $fund,
        ExpenseCategory $category,
        string $payee,
        float $amount,
        string $date,
        ?string $narration,
        User $actor,
        ?PettyCashRequest $request = null,
        ?string $idempotencyKey = null,
    ): PettyCashTransaction {
        if (! $fund->isActive()) {
            throw new RuntimeException("The float [{$fund->name}] is closed, so nothing can be paid out of it.");
        }

        $amount = $this->amount($amount);

        if ($payee === '') {
            throw new RuntimeException('Name who is being paid: a voucher without a payee is not a voucher.');
        }

        $balance = (float) $this->balanceOf($fund);

        if ($amount > $balance + 0.0001) {
            throw new RuntimeException(sprintf(
                'The float holds %s, so it cannot pay %s. Replenish it first — a custodian cannot hand over cash the drawer does not have.',
                number_format($balance, 2),
                number_format($amount, 2),
            ));
        }

        return DB::transaction(function () use ($fund, $category, $payee, $amount, $date, $narration, $actor, $request, $idempotencyKey, $balance) {
            $payment = $this->movements->pay([
                'money_account_id' => $fund->account_id,
                'counter_account_id' => $category->account_id,
                'amount' => $amount,
                'paid_on' => $date,
                'payee' => $payee,
                'narration' => $this->nullIfBlank($narration),
                'branch_id' => $fund->branch_id,
                'idempotency_key' => $idempotencyKey,
            ], $actor->id);

            $transaction = PettyCashTransaction::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $fund->branch_id,
                'fund_id' => $fund->id,
                'kind' => PettyCashTransaction::KIND_DISBURSEMENT,
                'occurred_on' => $date,
                'amount' => $amount,
                'expense_category_id' => $category->id,
                'payment_id' => $payment->id,
                'request_id' => $request?->id,
                'payee' => $payee,
                'narration' => $this->nullIfBlank($narration),
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'action' => 'petty_cash.voucher_recorded',
                'entity_type' => 'petty_cash_transaction',
                'entity_id' => $transaction->id,
                'branch_id' => $fund->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'fund' => $fund->name,
                    'voucher' => $payment->receipt_no,
                    'payee' => $payee,
                    'category' => $category->name,
                    'amount' => $amount,
                    'float_before' => number_format($balance, 2, '.', ''),
                    'approved_by_request' => $request?->id,
                ],
            ]);

            return $transaction->load(['fund', 'category.account', 'payment', 'request']);
        });
    }

    protected function fundOrFail(int $id): PettyCashFund
    {
        $fund = PettyCashFund::query()
            ->where('company_id', $this->companyId())
            ->with(['account', 'custodian'])
            ->find($id);

        if ($fund === null) {
            throw new RuntimeException('That float does not exist in this company.');
        }

        return $fund;
    }

    protected function categoryOrFail(int $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()
            ->where('company_id', $this->companyId())
            ->with('account')
            ->find($id);

        if ($category === null) {
            throw new RuntimeException('Pick an expense category — it is what tells the ledger which account the money was spent on.');
        }

        if (! $category->isActive()) {
            throw new RuntimeException("The category [{$category->name}] is switched off. Recording against it would file the money where nobody is looking any more.");
        }

        return $category;
    }

    protected function assertPending(PettyCashRequest $request): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException("This request was already {$request->label()}. A decided request is history — ask again if the money is still needed.");
        }
    }

    protected function assertNotSelf(PettyCashRequest $request, User $actor): void
    {
        if ((int) $request->requested_by === (int) $actor->id) {
            throw new RuntimeException('The person who asked for the money cannot approve it. Ask somebody else to decide this request.');
        }
    }

    protected function amount(mixed $value): float
    {
        $amount = round((float) $value, 4);

        if ($amount <= 0) {
            throw new RuntimeException('An amount has to be more than nothing.');
        }

        return $amount;
    }

    /** A float with no account code of its own gets one beside the cash drawer. */
    protected function nextAccountCode(string $fundCode): string
    {
        $suffix = preg_replace('/[^A-Z0-9]/', '', strtoupper($fundCode)) ?: 'FUND';

        return '1115-'.$suffix;
    }

    protected function companyId(): int
    {
        return $this->context->companyId();
    }

    protected function nullIfBlank(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : $value;
    }
}
