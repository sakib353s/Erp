<?php

namespace App\Domain\Business\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\UtilityBill;
use App\Domain\Business\UtilityProvider;
use App\Domain\Business\UtilityRegistry;
use App\Domain\CashBank\Services\ExpenseService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §12-15 — the utility desk's engine.
 *
 * Two things are being kept honest here, and neither of them is a form.
 *
 * **Recording a bill is not paying it.** A bill arrives, it is filed against the
 * provider and the month it covers, and nothing has left the bank — the drawer
 * shows what the company owes and by when. The ledger hears about it exactly
 * once, on the day the money goes, and the entry that carries it is a real one:
 * Dr the provider's expense account, Cr the account the money left. The register
 * remembers which entry closed which bill, so “was this paid?” is answered from
 * the ledger rather than from a checkbox.
 *
 * **The gate is the expense desk's gate.** A payment at or above the company's
 * own `cash.expense_approval_above` does not leave: the bill goes to
 * `pending_approval`, says so out loud, and waits for a second person — who
 * cannot be the one who asked. Under the threshold the same action pays
 * immediately, which is what makes the threshold worth having rather than a form
 * everybody learns to click through.
 *
 * Nothing else is stored twice: overdue, due-soon and “nothing left to do” are all
 * read from the bill's due date against the clock.
 */
class UtilityService
{
    public const CODE_PREFIX = 'UB-';

    /** The threshold the gate reads — the same number the expense desk uses. */
    public const THRESHOLD_GROUP = ExpenseService::SETTING_GROUP;

    public const THRESHOLD_KEY = ExpenseService::SETTING_KEY;

    public function __construct(
        protected JournalPostingService $journals,
        protected AuditRecorder $audit,
        protected TenantContext $context,
        protected SettingService $settings,
        protected MoneyAccountService $money,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for the utility desk.'));
    }

    /* --------------------------------------------------------------- registry */

    /**
     * The companies's own providers. On a company that has never opened the desk
     * the standard six are materialised first — once, and never overwritten.
     *
     * @return Collection<int, UtilityProvider>
     */
    public function providers(bool $activeOnly = true, ?string $family = null): Collection
    {
        $this->materialiseDefaults();

        return UtilityProvider::query()
            ->where('company_id', $this->companyId())
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->when($family !== null, fn (Builder $query) => $query->where('family', $family))
            ->orderBy('family')
            ->orderBy('name')
            ->get();
    }

    /** Create the default registry rows for a company that has none. */
    public function materialiseDefaults(): int
    {
        $companyId = $this->companyId();

        $existing = UtilityProvider::query()->where('company_id', $companyId)->exists();

        if ($existing) {
            return 0;
        }

        $created = 0;

        foreach (UtilityRegistry::DEFAULTS as $row) {
            UtilityProvider::query()->create([
                'company_id' => $companyId,
                'code' => $row['code'],
                'name' => $row['name'],
                'family' => $row['family'],
                'expense_account_id' => $this->familyAccount($row['family'])?->id,
                'consumer_no' => $row['consumer_no'],
                'premises' => $row['premises'],
                'due_day' => $row['due_day'],
                'is_active' => true,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Add or edit a provider.
     *
     * @param  array{code:string, name:string, family:string, branch_id?:int|null, expense_account_id?:int|null,
     *               consumer_no?:string|null, meter_no?:string|null, premises?:string|null, due_day?:int|null,
     *               is_active?:bool, notes?:string|null}  $data
     */
    public function saveProvider(array $data, ?UtilityProvider $provider, User $actor): UtilityProvider
    {
        $companyId = $this->companyId();
        $code = strtoupper(trim((string) $data['code']));
        $name = trim((string) $data['name']);
        $family = (string) $data['family'];

        if ($code === '' || $name === '') {
            throw new RuntimeException('A provider needs a short code and a name — that is how its bills are found again.');
        }

        if (! array_key_exists($family, UtilityProvider::FAMILIES)) {
            throw new RuntimeException("Unknown utility family [{$family}].");
        }

        $clash = UtilityProvider::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->when($provider !== null, fn (Builder $query) => $query->whereKeyNot($provider->id))
            ->exists();

        if ($clash) {
            throw new RuntimeException("A provider with the code [{$code}] already exists for this company.");
        }

        $account = $this->resolveAccount($data['expense_account_id'] ?? null, $family);

        $attributes = [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? $provider?->branch_id,
            'code' => $code,
            'name' => $name,
            'family' => $family,
            'expense_account_id' => $account?->id,
            'consumer_no' => $this->blankToNull($data['consumer_no'] ?? null),
            'meter_no' => $this->blankToNull($data['meter_no'] ?? null),
            'premises' => $this->blankToNull($data['premises'] ?? null),
            'due_day' => $this->dueDay($data['due_day'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ];

        return DB::transaction(function () use ($provider, $attributes, $actor) {
            if ($provider === null) {
                $provider = UtilityProvider::query()->create($attributes);
                $action = 'business.utility_provider_created';
                $before = null;
            } else {
                $before = $provider->only(['code', 'name', 'family', 'is_active']);
                $provider->fill($attributes)->save();
                $action = 'business.utility_provider_updated';
            }

            $this->audit->record([
                'action' => $action,
                'entity_type' => 'utility_provider',
                'entity_id' => $provider->id,
                'branch_id' => $provider->branch_id,
                'actor_id' => $actor->id,
                'before' => $before,
                'after' => [
                    'code' => $provider->code,
                    'name' => $provider->name,
                    'family' => $provider->family,
                    'account' => $provider->account?->code,
                    'is_active' => $provider->is_active,
                ],
            ]);

            return $provider->load('account');
        });
    }

    /* ------------------------------------------------------------------ bills */

    /**
     * Every bill the reader may see: this company's, and — for somebody scoped to
     * branches — only the ones they stand in. Company first, always: a branch
     * scope does not filter companies on its own.
     */
    public function visible(?User $reader = null): Builder
    {
        $ids = ($reader ?? auth()->user())?->accessibleBranchIds()
            ?? $this->context->accessibleBranchIds();

        return UtilityBill::query()
            ->where('company_id', $this->companyId())
            ->when($ids !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($ids) {
                $inner->whereIn('branch_id', $ids)->orWhereNull('branch_id');
            }));
    }

    /**
     * File a bill.
     *
     * Nothing is posted: the obligation is recorded and the desk shows it. A
     * provider that has already billed for this month is refused with the
     * bill that exists — two rows for one month is how a company pays twice.
     *
     * @param  array{provider_id:int, period_month:string, issue_date:string, due_date:string, amount:string|float,
     *               consumption?:string|float|null, meter_reading?:string|float|null, narration?:string|null,
     *               branch_id?:int|null}  $data
     */
    public function record(array $data, User $actor): UtilityBill
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('A bill has to be more than nothing.');
        }

        $provider = UtilityProvider::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $data['provider_id'])
            ->first();

        if ($provider === null) {
            throw new RuntimeException('That provider is not one of this company\'s — pick one from the registry.');
        }

        if (! $provider->is_active) {
            throw new RuntimeException("[{$provider->name}] is switched off. Recording a bill against it would file the money where nobody is looking any more.");
        }

        $period = $this->period($data['period_month']);
        $issue = Carbon::parse($data['issue_date']);
        $due = Carbon::parse($data['due_date']);

        if ($due->lt($issue)) {
            throw new RuntimeException('The due date cannot be before the bill was issued.');
        }

        $already = $this->visible()
            ->where('provider_id', $provider->id)
            ->where('period_month', $period)
            ->whereNot('status', UtilityBill::STATUS_VOID)
            ->first();

        if ($already !== null) {
            throw new RuntimeException("{$provider->name} already has a bill for {$already->periodLabel()} — {$already->bill_no}, ".number_format((float) $already->amount, 2).'. Open it and edit it instead of filing a second one.');
        }

        return DB::transaction(function () use ($data, $provider, $period, $issue, $due, $amount, $actor) {
            $bill = UtilityBill::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $data['branch_id'] ?? $provider->branch_id ?? $this->context->branchId(),
                'provider_id' => $provider->id,
                'bill_no' => $this->nextCode(),
                'period_month' => $period,
                'issue_date' => $issue->toDateString(),
                'due_date' => $due->toDateString(),
                'amount' => $amount,
                'consumption' => $data['consumption'] ?? null,
                // No reading, no unit: a rent bill is not "0 kWh", it is a bill
                // with nothing measured, and the register says so.
                'consumption_unit' => ($data['consumption'] ?? null) === null ? null : $provider->unit(),
                'meter_reading' => $data['meter_reading'] ?? null,
                'narration' => $this->blankToNull($data['narration'] ?? null),
                'status' => UtilityBill::STATUS_RECORDED,
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'action' => 'business.utility_bill_recorded',
                'entity_type' => 'utility_bill',
                'entity_id' => $bill->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'bill_no' => $bill->bill_no,
                    'provider' => $provider->code,
                    'period' => $period,
                    'amount' => (string) $bill->amount,
                    'due_on' => $bill->due_date?->toDateString(),
                ],
            ]);

            return $bill->load('provider.account');
        });
    }

    /** Edit the figures on a bill that has not been paid. */
    public function amend(UtilityBill $bill, array $data, User $actor): UtilityBill
    {
        if ($bill->isPaid()) {
            throw new RuntimeException("{$bill->bill_no} is paid — the ledger has that figure. Raise the correction as its own document rather than rewriting history.");
        }

        if ($bill->isVoid()) {
            throw new RuntimeException("{$bill->bill_no} was voided, so it cannot be edited back to life. File the corrected bill as a new one.");
        }

        $amount = round((float) ($data['amount'] ?? $bill->amount), 2);

        if ($amount <= 0) {
            throw new RuntimeException('A bill has to be more than nothing.');
        }

        $before = $bill->only(['amount', 'due_date', 'issue_date', 'consumption', 'meter_reading', 'narration']);

        $bill->fill([
            'issue_date' => isset($data['issue_date']) ? Carbon::parse($data['issue_date'])->toDateString() : $bill->issue_date,
            'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : $bill->due_date,
            'amount' => $amount,
            'consumption' => $data['consumption'] ?? $bill->consumption,
            'meter_reading' => $data['meter_reading'] ?? $bill->meter_reading,
            'narration' => array_key_exists('narration', $data) ? $this->blankToNull($data['narration']) : $bill->narration,
        ]);

        if ($bill->due_date !== null && $bill->issue_date !== null && $bill->due_date->lt($bill->issue_date)) {
            throw new RuntimeException('The due date cannot be before the bill was issued.');
        }

        $bill->save();

        $this->audit->record([
            'action' => 'business.utility_bill_amended',
            'entity_type' => 'utility_bill',
            'entity_id' => $bill->id,
            'branch_id' => $bill->branch_id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => $bill->only(['amount', 'due_date', 'issue_date', 'consumption', 'meter_reading']),
        ]);

        return $bill->load('provider.account');
    }

    /**
     * Pay a bill.
     *
     * Under the company's threshold the money moves now: one journal entry,
     * Dr the provider's account and Cr the account the money left. At or above
     * it the bill is *held* instead — the row says `pending_approval`, nothing
     * has left anywhere, and somebody else has to agree.
     */
    public function pay(UtilityBill $bill, User $actor, ?int $moneyAccountId = null, ?string $paidOn = null): UtilityBill
    {
        if ($bill->isPaid()) {
            throw new RuntimeException("{$bill->bill_no} is already paid — {$bill->paid_on?->format('d M Y')}.");
        }

        if ($bill->isVoid()) {
            throw new RuntimeException("{$bill->bill_no} was voided, so there is nothing to pay.");
        }

        if ($bill->isPending()) {
            throw new RuntimeException("{$bill->bill_no} is still waiting for a second signature, so nothing has left the account yet.");
        }

        $money = $this->moneyAccount((int) ($moneyAccountId ?? 0));

        if ($this->mustApprove((float) $bill->amount)) {
            $bill->forceFill([
                'status' => UtilityBill::STATUS_PENDING,
                'approval_gate' => true,
                'approval_threshold' => $this->threshold(),
                'money_account_id' => $money->id,
            ])->save();

            $this->audit->record([
                'action' => 'business.utility_payment_held',
                'entity_type' => 'utility_bill',
                'entity_id' => $bill->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'bill_no' => $bill->bill_no,
                    'amount' => (string) $bill->amount,
                    'threshold' => $this->threshold(),
                    'money_account' => $money->code,
                ],
            ]);

            return $bill->load('provider.account', 'moneyAccount');
        }

        return $this->settle($bill, $actor, $money, $paidOn);
    }

    /** Agree with a held payment: only now does the money move. Maker ≠ checker. */
    public function approve(UtilityBill $bill, User $actor, ?string $note = null): UtilityBill
    {
        if (! $bill->isPending()) {
            throw new RuntimeException("{$bill->bill_no} is not waiting for approval.");
        }

        if ((int) $bill->created_by === (int) $actor->id) {
            throw new RuntimeException('A payment is approved by somebody other than the person who filed the bill.');
        }

        $money = $bill->moneyAccount ?? $this->moneyAccount(0);

        $bill->forceFill([
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $this->blankToNull($note),
        ])->save();

        $settled = $this->settle($bill, $actor, $money, null);

        $this->audit->record([
            'action' => 'business.utility_payment_approved',
            'entity_type' => 'utility_bill',
            'entity_id' => $settled->id,
            'branch_id' => $settled->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'bill_no' => $settled->bill_no,
                'entry_no' => $settled->journalEntry?->entry_no,
                'note' => $settled->decision_note,
            ],
        ]);

        return $settled;
    }

    /** Refuse a held payment: it goes back to being an unpaid bill, nothing moves. */
    public function reject(UtilityBill $bill, User $actor, ?string $note = null): UtilityBill
    {
        if (! $bill->isPending()) {
            throw new RuntimeException("{$bill->bill_no} is not waiting for approval.");
        }

        $bill->forceFill([
            'status' => UtilityBill::STATUS_RECORDED,
            'approval_gate' => false,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $this->blankToNull($note),
            'money_account_id' => null,
        ])->save();

        $this->audit->record([
            'action' => 'business.utility_payment_rejected',
            'entity_type' => 'utility_bill',
            'entity_id' => $bill->id,
            'branch_id' => $bill->branch_id,
            'actor_id' => $actor->id,
            'after' => ['bill_no' => $bill->bill_no, 'note' => $bill->decision_note],
        ]);

        return $bill->load('provider.account');
    }

    /**
     * Void a bill that has not been paid. A *paid* bill is never voided: the
     * ledger has the money, and undoing that is a reversal with a reason, not a
     * flag on a register row.
     */
    public function void(UtilityBill $bill, User $actor, string $reason): UtilityBill
    {
        if ($bill->isPaid()) {
            throw new RuntimeException("{$bill->bill_no} is paid — the money is in the ledger. Reverse the payment through the accounts desk with a reason; a register row is not the place to undo a posting.");
        }

        if ($bill->isVoid()) {
            throw new RuntimeException("{$bill->bill_no} is already void.");
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Voiding a bill needs a reason — next year somebody will ask why there is a gap in the sequence.');
        }

        $bill->forceFill([
            'status' => UtilityBill::STATUS_VOID,
            'void_reason' => trim($reason),
            'approval_gate' => false,
        ])->save();

        $this->audit->record([
            'action' => 'business.utility_bill_voided',
            'entity_type' => 'utility_bill',
            'entity_id' => $bill->id,
            'branch_id' => $bill->branch_id,
            'actor_id' => $actor->id,
            'reason' => trim($reason),
            'after' => ['bill_no' => $bill->bill_no, 'amount' => (string) $bill->amount],
        ]);

        return $bill->load('provider.account');
    }

    /* ----------------------------------------------------------------- lenses */

    /**
     * What the renewals lens shows: what has already gone past its date, what is
     * coming inside the horizon, and — because a standing charge is a date too —
     * the next bill each active provider is expected to send.
     *
     * @return array{overdue: Collection<int, UtilityBill>, due: Collection<int, UtilityBill>, next: Collection<int, UtilityProvider>}
     */
    public function reminders(int $days = UtilityBill::DUE_SOON_DAYS): array
    {
        $horizon = Carbon::today()->addDays(max(1, $days))->toDateString();

        $open = fn () => $this->visible()
            ->open()
            ->with(['provider.account'])
            ->orderBy('due_date');

        return [
            'overdue' => $open()->whereDate('due_date', '<', Carbon::today()->toDateString())->get(),
            'due' => $open()
                ->whereDate('due_date', '>=', Carbon::today()->toDateString())
                ->whereDate('due_date', '<=', $horizon)
                ->get(),
            'next' => $this->providers()
                ->filter(fn (UtilityProvider $provider) => $provider->due_day !== null)
                ->values(),
        ];
    }

    /**
     * The month's figures: what was billed, what has been paid and what is still
     * owed, per family. Billed is by the month the consumption belongs to (that
     * is what a bill *is*); paid is by the day the money went.
     *
     * @return array{period:string, families:array<string, array<string, float|int>>, totals:array<string, float|int>}
     */
    public function summary(?string $period = null): array
    {
        $month = $this->period($period ?? Carbon::today()->format('Y-m'));

        $billed = $this->visible()
            ->where('period_month', $month)
            ->whereNot('status', UtilityBill::STATUS_VOID)
            ->with('provider')
            ->get();

        $paid = $this->visible()
            ->where('status', UtilityBill::STATUS_PAID)
            ->whereDate('paid_on', '>=', Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString())
            ->whereDate('paid_on', '<=', Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString())
            ->get();

        $families = [];

        foreach (array_keys(UtilityProvider::FAMILIES) as $family) {
            $rows = $billed->filter(fn (UtilityBill $bill) => $bill->provider?->family === $family);

            $families[$family] = [
                'bills' => $rows->count(),
                'billed' => round((float) $rows->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
                'outstanding' => round((float) $rows->filter(fn (UtilityBill $bill) => $bill->isOpen())->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
                'paid' => round((float) $paid->filter(fn (UtilityBill $bill) => $bill->provider?->family === $family)->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
            ];
        }

        return [
            'period' => $month,
            'families' => $families,
            'totals' => [
                'bills' => (int) $billed->count(),
                'billed' => round((float) $billed->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
                'paid' => round((float) $paid->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
                'outstanding' => round((float) $billed->filter(fn (UtilityBill $bill) => $bill->isOpen())->sum(fn (UtilityBill $bill) => (float) $bill->amount), 2),
                'overdue' => (int) $this->visible()->overdue()->count(),
                'due_soon' => (int) $this->visible()->open()
                    ->whereDate('due_date', '>=', Carbon::today()->toDateString())
                    ->whereDate('due_date', '<=', Carbon::today()->addDays(UtilityBill::DUE_SOON_DAYS)->toDateString())
                    ->count(),
            ],
        ];
    }

    /** UB-000001, allocated per company and never reused. */
    public function nextCode(): string
    {
        $companyId = $this->companyId();

        $last = UtilityBill::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('bill_no');

        $number = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
            $number = (int) $matches[1] + 1;
        }

        do {
            $code = self::CODE_PREFIX.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (UtilityBill::query()->where('company_id', $companyId)->where('bill_no', $code)->exists());

        return $code;
    }

    /* ---------------------------------------------------------------- the gate */

    /** The amount at or above which a payment waits, read as a decimal string. */
    public function threshold(): string
    {
        $value = $this->settings->get(self::THRESHOLD_GROUP, self::THRESHOLD_KEY, '0');

        return is_numeric($value) ? (string) $value : '0';
    }

    public function mustApprove(float $amount): bool
    {
        $threshold = (float) $this->threshold();

        return $threshold > 0.0 && round($amount, 2) >= round($threshold, 2);
    }

    /* --------------------------------------------------------------- internals */

    /** Post the payment and close the bill. The only place money moves here. */
    protected function settle(UtilityBill $bill, User $actor, Account $money, ?string $paidOn): UtilityBill
    {
        $bill->loadMissing('provider.account');

        $debit = $bill->provider?->account;

        if ($debit === null) {
            throw new RuntimeException("The provider on {$bill->bill_no} has no expense account, so there is nowhere to book the bill. Set it on the provider first.");
        }

        if ((bool) $debit->is_group) {
            throw new RuntimeException("The account behind [{$bill->provider?->name}] is a group account, and a group cannot be posted to. Point the provider at the ledger account underneath it.");
        }

        $amount = round((float) $bill->amount, 2);
        $day = $paidOn !== null ? Carbon::parse($paidOn) : Carbon::today();

        return DB::transaction(function () use ($bill, $actor, $money, $debit, $amount, $day) {
            $entry = $this->journals->post([
                'entry_date' => $day->toDateString(),
                'description' => "Utility bill {$bill->bill_no} — {$bill->provider?->name} ({$bill->periodLabel()})",
                'narration' => $bill->narration,
                'journal_type' => 'expense',
                'source_type' => 'utility_bill',
                'source_id' => $bill->id,
                'source_event' => 'utility_bill_paid',
                'branch_id' => $bill->branch_id,
                'lines' => [
                    [
                        'account_id' => $debit->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                        'narration' => $bill->bill_no.' · '.$bill->provider?->name,
                    ],
                    [
                        'account_id' => $money->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                        'narration' => 'Paid '.$day->toDateString(),
                    ],
                ],
            ], $actor);

            $bill->forceFill([
                'status' => UtilityBill::STATUS_PAID,
                'paid_on' => $day->toDateString(),
                'money_account_id' => $money->id,
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->audit->record([
                'action' => 'business.utility_bill_paid',
                'entity_type' => 'utility_bill',
                'entity_id' => $bill->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'bill_no' => $bill->bill_no,
                    'amount' => $amount,
                    'paid_on' => $day->toDateString(),
                    'entry_no' => $entry->entry_no,
                    'money_account' => $money->code,
                ],
            ]);

            return $bill->load('provider.account', 'moneyAccount', 'journalEntry');
        });
    }

    /** The account a family's bills belong in, from the company's own chart. */
    protected function familyAccount(string $family): ?Account
    {
        $code = UtilityProvider::FAMILY_ACCOUNT[$family] ?? null;

        if ($code === null) {
            return null;
        }

        return Account::query()
            ->where('company_id', $this->companyId())
            ->where('code', $code)
            ->where('is_group', false)
            ->first();
    }

    protected function resolveAccount(?int $accountId, string $family): ?Account
    {
        if ($accountId !== null && $accountId > 0) {
            $account = Account::query()
                ->where('company_id', $this->companyId())
                ->whereKey($accountId)
                ->first();

            if ($account === null) {
                throw new RuntimeException('That ledger account is not one of this company\'s.');
            }

            if ($account->is_group) {
                throw new RuntimeException("Account [{$account->code} {$account->name}] is a group — bills have to post to a ledger account underneath it.");
            }

            return $account;
        }

        return $this->familyAccount($family);
    }

    /**
     * The account the money leaves from: the one named, or the company's first
     * money account, because asking a person to pick one they have already set
     * as their default is how mistakes get made.
     *
     * The set is `MoneyAccountService::accounts()` — cash, bank and wallets, never
     * a group — so a utility payment can leave from exactly the accounts the cash
     * desk recognises and no others.
     */
    protected function moneyAccount(int $accountId): Account
    {
        $accounts = $this->money->accounts();

        $account = $accountId > 0
            ? $accounts->firstWhere('id', $accountId)
            : $accounts->sortBy('code')->first();

        if ($account === null) {
            throw new RuntimeException($accountId > 0
                ? 'That is not one of this company\'s cash, bank or wallet accounts, so money cannot leave from it.'
                : 'No cash or bank account is set up, so there is nothing to pay from. Set one up on the cash & bank desk first.');
        }

        return $account;
    }

    protected function period(?string $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException('A billing period is a month — write it as YYYY-MM.');
        }

        return $value;
    }

    protected function dueDay(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $day = (int) $value;

        if ($day < 1 || $day > 31) {
            throw new RuntimeException('The day a bill lands is a day of the month, between 1 and 31.');
        }

        return $day;
    }

    protected function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
