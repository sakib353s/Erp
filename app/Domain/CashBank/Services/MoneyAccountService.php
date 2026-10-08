<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The registry of accounts money can physically sit in — cash tills, bank
 * accounts and mobile wallets (§08-01, §08-06, §08-11).
 *
 * Nothing here invents a balance. Every figure this service reports is the sum
 * of the posted journal lines on the account, because the moment a screen owns a
 * balance of its own there are two answers to "how much cash is in the drawer"
 * and the one on the screen is the wrong one. What the ledger cannot say — which
 * bank, which number, which mobile provider, and whether money may still move
 * through the account at all — is what this service owns.
 */
class MoneyAccountService
{
    /** The instruments the desk recognises. A wallet is not a bank: money leaves it differently. */
    public const INSTRUMENTS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'wallet' => 'Mobile wallet',
    ];

    /** The providers the country actually clears through. */
    public const WALLET_PROVIDERS = [
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'upay' => 'Upay',
    ];

    /** Which `payments.method` a movement through each instrument is. */
    public const METHODS = [
        'cash' => 'cash',
        'bank' => 'bank',
        'wallet' => 'mobile',
    ];

    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for cash and bank.'));
    }

    /**
     * Every account money can sit in, in the order money is usually counted:
     * the till first, then the banks, then the wallets.
     */
    public function accounts(): Collection
    {
        return Account::query()
            ->where('company_id', $this->companyId())
            ->where(function ($query) {
                $query->whereIn('instrument', array_keys(self::INSTRUMENTS))
                    ->orWhere('is_cash', true)
                    ->orWhere('is_bank', true);
            })
            ->where('is_group', false)
            ->orderByRaw("CASE COALESCE(instrument, CASE WHEN is_cash THEN 'cash' ELSE 'bank' END)
                WHEN 'cash' THEN 1 WHEN 'bank' THEN 2 WHEN 'wallet' THEN 3 ELSE 4 END")
            ->orderBy('code')
            ->get();
    }

    /** The same registry, narrowed to one instrument. */
    public function accountsOf(string $instrument): Collection
    {
        return $this->accounts()->filter(
            fn (Account $account) => $this->instrumentOf($account) === $instrument,
        )->values();
    }

    /** An account's instrument, falling back to the flags the chart has always carried. */
    public function instrumentOf(Account $account): string
    {
        return $account->instrument
            ?: ($account->is_cash ? 'cash' : ($account->is_bank ? 'bank' : ''));
    }

    public function label(Account $account): string
    {
        $instrument = self::INSTRUMENTS[$this->instrumentOf($account)] ?? 'Account';
        $detail = $account->bank_name ?: ($account->wallet_provider ? self::WALLET_PROVIDERS[$account->wallet_provider] ?? $account->wallet_provider : null);

        return $detail === null ? $instrument : "{$instrument} · {$detail}";
    }

    public function isMoney(Account $account): bool
    {
        return array_key_exists($this->instrumentOf($account), self::INSTRUMENTS);
    }

    /**
     * The GL position of every money account at once — one grouped query, not
     * one query per account, because a desk with nine banks and a wallet each
     * would otherwise hit the database ten times to draw one table.
     *
     * @return array<int, array{
     *     account: Account, instrument: string, label: string,
     *     debit: string, credit: string, balance: string,
     *     movements: int, last_movement_on: ?string, is_negative: bool
     * }>
     */
    public function positions(?int $branchId = null): array
    {
        $accounts = $this->accounts();

        if ($accounts->isEmpty()) {
            return [];
        }

        $aggregates = JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.company_id', $this->companyId())
            ->whereIn('journal_lines.account_id', $accounts->pluck('id')->all())
            ->where('e.posting_state', JournalEntry::STATE_POSTED)
            ->when($branchId !== null, fn ($query) => $query->where('e.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->selectRaw(
                "journal_lines.account_id as account_id,
                 COALESCE(SUM(CASE WHEN journal_lines.dc = 'debit' THEN journal_lines.amount ELSE 0 END), 0) as d,
                 COALESCE(SUM(CASE WHEN journal_lines.dc = 'credit' THEN journal_lines.amount ELSE 0 END), 0) as c,
                 COUNT(*) as lines,
                 MAX(e.entry_date) as last_on",
            )
            ->get()
            ->keyBy('account_id');

        $rows = [];

        foreach ($accounts as $account) {
            $aggregate = $aggregates->get($account->id);

            // Cash, bank and wallet accounts are assets: they are debit-normal, so
            // what is in them is what was debited less what was credited.
            $debit = (float) ($aggregate->d ?? 0);
            $credit = (float) ($aggregate->c ?? 0);
            $balance = $debit - $credit;

            $rows[] = [
                'account' => $account,
                'instrument' => $this->instrumentOf($account),
                'label' => $this->label($account),
                'debit' => number_format($debit, 4, '.', ''),
                'credit' => number_format($credit, 4, '.', ''),
                'balance' => number_format($balance, 4, '.', ''),
                'movements' => (int) ($aggregate->lines ?? 0),
                'last_movement_on' => $aggregate->last_on ?? null,
                'is_negative' => $balance < -0.0001,
            ];
        }

        return $rows;
    }

    /** Where one account stands, with its own query (the book screen needs one). */
    public function positionFor(Account $account, ?int $branchId = null): array
    {
        foreach ($this->positions($branchId) as $row) {
            if ($row['account']->id === $account->id) {
                return $row;
            }
        }

        return [
            'account' => $account,
            'instrument' => $this->instrumentOf($account),
            'label' => $this->label($account),
            'debit' => '0.0000',
            'credit' => '0.0000',
            'balance' => '0.0000',
            'movements' => 0,
            'last_movement_on' => null,
            'is_negative' => false,
        ];
    }

    /**
     * Totals per instrument, and the sum of them — the figure a drawer count or
     * a bank call is checked against.
     *
     * @return array{cash: string, bank: string, wallet: string, total: string, negative: int}
     */
    public function totals(?int $branchId = null): array
    {
        $totals = ['cash' => 0.0, 'bank' => 0.0, 'wallet' => 0.0];
        $negative = 0;

        foreach ($this->positions($branchId) as $row) {
            $totals[$row['instrument']] = ($totals[$row['instrument']] ?? 0) + (float) $row['balance'];

            if ($row['is_negative']) {
                $negative++;
            }
        }

        return [
            'cash' => number_format($totals['cash'] ?? 0, 4, '.', ''),
            'bank' => number_format($totals['bank'] ?? 0, 4, '.', ''),
            'wallet' => number_format($totals['wallet'] ?? 0, 4, '.', ''),
            'total' => number_format(array_sum($totals), 4, '.', ''),
            'negative' => $negative,
        ];
    }

    /**
     * Declare a money account: a real leaf account in the chart, flagged with the
     * instrument that describes it, created under Current Assets unless the
     * operator names a parent. The chart and this registry are the same list, so
     * a bank account cannot exist in one and not the other.
     *
     * @param  array{instrument:string,name:string,code:string,bank_name?:?string,
     *               account_number?:?string,wallet_provider?:?string,parent_id?:?int,
     *               currency?:?string,description?:?string}  $data
     */
    public function create(array $data, ?int $actorId = null): Account
    {
        $companyId = $this->companyId();
        $instrument = (string) $data['instrument'];

        if (! array_key_exists($instrument, self::INSTRUMENTS)) {
            throw new RuntimeException('An account is cash, a bank account or a mobile wallet — nothing else holds money.');
        }

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw new RuntimeException('A money account needs a code and a name: the ledger has to be able to name it.');
        }

        if (Account::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw new RuntimeException("Account code {$code} is already used in this chart of accounts.");
        }

        $bankName = trim((string) ($data['bank_name'] ?? '')) ?: null;
        $provider = trim((string) ($data['wallet_provider'] ?? '')) ?: null;

        if ($instrument === 'bank' && $bankName === null) {
            throw new RuntimeException('A bank account has to say which bank holds it — otherwise it is just a ledger account with a friendly name.');
        }

        if ($instrument === 'wallet') {
            if ($provider === null || ! array_key_exists($provider, self::WALLET_PROVIDERS)) {
                throw new RuntimeException('A wallet has to name its provider: '.implode(', ', array_keys(self::WALLET_PROVIDERS)).'.');
            }
        }

        $parentId = $data['parent_id'] ?? null;

        if ($parentId === null) {
            // 1100 Current Assets is where money sits in the standard chart.
            $parentId = Account::query()
                ->where('company_id', $companyId)
                ->where('code', '1100')
                ->value('id');
        }

        $account = Account::create([
            'company_id' => $companyId,
            'account_group_id' => $parentId !== null ? Account::query()->find($parentId)?->account_group_id : null,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'type' => 'asset',
            'sub_type' => $instrument === 'cash' ? 'cash' : ($instrument === 'bank' ? 'bank' : 'wallet'),
            'is_group' => false,
            'is_system' => false,
            'is_active' => true,
            'is_cash' => $instrument === 'cash',
            'is_bank' => $instrument === 'bank',
            'instrument' => $instrument,
            'bank_name' => $bankName,
            'account_number' => trim((string) ($data['account_number'] ?? '')) ?: null,
            'wallet_provider' => $provider,
            'currency' => $data['currency'] ?? 'BDT',
            'description' => $data['description'] ?? null,
        ]);

        $this->audit->record([
            'action' => 'cash_bank.account_declared',
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'branch_id' => null,
            'actor_id' => $actorId,
            'after' => [
                'code' => $account->code,
                'name' => $account->name,
                'instrument' => $instrument,
                'bank_name' => $bankName,
                'wallet_provider' => $provider,
            ],
        ]);

        return $account;
    }

    /**
     * Rename or re-describe an account.
     *
     * Two things may not change once money has moved through it: the code, and
     * the instrument. A bank account that becomes a cash account overnight does
     * not re-describe yesterday's postings — it makes every report that grouped
     * by instrument quietly wrong, so it is refused and a new account is opened
     * instead.
     *
     * @param  array{name?:string,bank_name?:?string,account_number?:?string,wallet_provider?:?string,description?:?string}  $data
     */
    public function update(Account $account, array $data, ?int $actorId = null): Account
    {
        $this->assertOwned($account);

        $before = $account->only(['name', 'bank_name', 'account_number', 'wallet_provider', 'description']);
        $instrument = $this->instrumentOf($account);
        $fields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);

            if ($name === '') {
                throw new RuntimeException('A money account needs a name the ledger can print.');
            }

            $fields['name'] = $name;
        }

        if (array_key_exists('bank_name', $data)) {
            $bankName = trim((string) $data['bank_name']);

            if ($instrument === 'bank' && $bankName === '') {
                throw new RuntimeException('A bank account has to keep saying which bank holds it.');
            }

            $fields['bank_name'] = $bankName ?: null;
        }

        if (array_key_exists('account_number', $data)) {
            $fields['account_number'] = trim((string) $data['account_number']) ?: null;
        }

        if (array_key_exists('wallet_provider', $data)) {
            $provider = trim((string) $data['wallet_provider']);

            if ($instrument === 'wallet' && ! array_key_exists($provider, self::WALLET_PROVIDERS)) {
                throw new RuntimeException('A wallet has to name its provider: '.implode(', ', array_keys(self::WALLET_PROVIDERS)).'.');
            }

            $fields['wallet_provider'] = $provider ?: null;
        }

        if (array_key_exists('description', $data)) {
            $fields['description'] = $data['description'] ?: null;
        }

        if ($fields === []) {
            throw new RuntimeException('Nothing to change on '.$account->name.'.');
        }

        $account->fill($fields)->save();

        $this->audit->record([
            'action' => 'cash_bank.account_updated',
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'branch_id' => null,
            'actor_id' => $actorId,
            'before' => $before,
            'after' => $account->only(['name', 'bank_name', 'account_number', 'wallet_provider', 'description']),
        ]);

        return $account->refresh();
    }

    /**
     * Close an account to new movement.
     *
     * Only an empty one. Deactivating an account that still holds money takes it
     * out of this desk while leaving the balance in the ledger — the one state
     * from which nobody can tell you where the cash went.
     */
    public function deactivate(Account $account, ?int $actorId = null): Account
    {
        $this->assertOwned($account);

        $balance = (float) $this->positionFor($account)['balance'];

        if (abs($balance) > 0.0001) {
            throw new RuntimeException(sprintf(
                '%s still holds %s — move the money out first, then close the account.',
                $account->name,
                number_format($balance, 2),
            ));
        }

        $account->forceFill(['is_active' => false])->save();

        $this->audit->record([
            'action' => 'cash_bank.account_closed',
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'branch_id' => null,
            'actor_id' => $actorId,
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
        ]);

        return $account->refresh();
    }

    /** The number of posted lines on an account — what "has history" means here. */
    public function movementCount(Account $account): int
    {
        return (int) JournalLine::query()
            ->where('company_id', $this->companyId())
            ->where('account_id', $account->id)
            ->count();
    }

    /** The account names itself in a form; it has to at least belong to this company. */
    public function assertOwned(Account $account): void
    {
        if ((int) $account->company_id !== $this->companyId()) {
            throw new RuntimeException('That account belongs to another company.');
        }
    }
}
