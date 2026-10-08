<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\Cheque;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Purchase\Supplier;
use App\Domain\Sales\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The cheque register (§08-13).
 *
 * One rule decides everything this service does: **a cheque is not money until
 * the bank has paid it.** A promise to pay is not a payment, so nothing here
 * posts to the ledger at the moment a slip changes hands — the entry is made
 * when the cheque clears, and reversed if it comes back. Posting on receipt
 * instead would be a lie the books have to live with: the bank balance would
 * disagree with the bank statement every single month, and every reconciliation
 * would open by explaining money that was never there.
 *
 * The register therefore answers a question the general ledger cannot: what is
 * still hanging over this account? Cheques in the drawer, cheques at the bank,
 * cheques written that nobody has presented — and post-dated ones, which are a
 * real instrument and a real trap, because they may not be deposited or
 * presented before the date written on them.
 */
class ChequeService
{
    public function __construct(
        protected JournalPostingService $journals,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    // ------------------------------------------------------------- recording

    /**
     * Write a cheque into the register. Nothing is posted: this is the promise,
     * not the payment.
     *
     * @param  array{direction:string, cheque_no:string, cheque_date:string, bank_name:string,
     *               account_id:int, counter_account_id:int, party_name:string, amount:string|float,
     *               currency?:string, customer_id?:int|null, supplier_id?:int|null,
     *               reference?:string|null, narration?:string|null, branch_id?:int|null}  $data
     */
    public function record(array $data, ?User $actor = null): Cheque
    {
        $companyId = $this->companyId();
        $direction = (string) $data['direction'];

        if (! array_key_exists($direction, Cheque::DIRECTIONS)) {
            throw new RuntimeException('A cheque is either received from somebody or issued to somebody — pick one.');
        }

        $account = $this->moneyAccount((int) $data['account_id'], $companyId);
        $counter = $this->counterAccount((int) $data['counter_account_id'], $account, $companyId);

        $chequeNo = trim((string) $data['cheque_no']);

        if ($chequeNo === '') {
            throw new RuntimeException('The cheque number is how this slip is found again in the drawer — it cannot be blank.');
        }

        $amount = $this->amount($data['amount']);
        $chequeDate = $this->date($data['cheque_date']);
        $party = trim((string) $data['party_name']);

        if ($party === '') {
            throw new RuntimeException('Name the party: who wrote this cheque, or who it is made out to.');
        }

        $customerId = $direction === Cheque::DIRECTION_RECEIVED ? ($data['customer_id'] ?? null) : null;
        $supplierId = $direction === Cheque::DIRECTION_ISSUED ? ($data['supplier_id'] ?? null) : null;

        if ($customerId !== null) {
            $this->assertPartyExists(Customer::class, (int) $customerId, $companyId, 'customer');
        }

        if ($supplierId !== null) {
            $this->assertPartyExists(Supplier::class, (int) $supplierId, $companyId, 'supplier');
        }

        $this->assertNotADoubleEntry($companyId, $account, $direction, $chequeNo, $amount, $chequeDate);

        $cheque = Cheque::query()->create([
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? $this->context->branchId(),
            'direction' => $direction,
            'cheque_no' => $chequeNo,
            'cheque_date' => $chequeDate,
            'bank_name' => trim((string) $data['bank_name']),
            'account_id' => $account->id,
            'counter_account_id' => $counter->id,
            'party_name' => $party,
            'customer_id' => $customerId,
            'supplier_id' => $supplierId,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'BDT',
            'status' => $direction === Cheque::DIRECTION_RECEIVED
                ? Cheque::STATUS_RECEIVED
                : Cheque::STATUS_ISSUED,
            'reference' => $data['reference'] ?? null,
            'narration' => $data['narration'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $this->audit->record([
            'action' => 'cash_bank.cheque_recorded',
            'entity_type' => 'cheque',
            'entity_id' => $cheque->id,
            'actor_id' => $actor?->id,
            'branch_id' => $cheque->branch_id,
            'amount' => $amount,
            'after' => [
                'direction' => $direction,
                'cheque_no' => $chequeNo,
                'cheque_date' => $chequeDate,
                'account' => $account->code,
                'party' => $party,
                'post_dated' => $cheque->fresh()->isPostDated(),
                'posted' => false,
            ],
        ]);

        return $cheque->refresh();
    }

    // ----------------------------------------------------------- the movements

    /** Hand a customer's cheque to the bank. Still not money: the bank has to pay it. */
    public function deposit(Cheque $cheque, string $on, ?User $actor = null): Cheque
    {
        $date = $this->date($on);

        $this->assertDirection($cheque, Cheque::DIRECTION_RECEIVED, 'deposited');
        $this->assertStatus($cheque, [Cheque::STATUS_RECEIVED], 'deposited');
        $this->assertNotBeforeItsDate($cheque, $date, 'A post-dated cheque cannot be deposited before the date written on it — the bank will return it, and the return is a mark on the account for nothing.');

        $cheque->forceFill([
            'status' => Cheque::STATUS_DEPOSITED,
            'deposited_on' => $date,
        ])->save();

        return $this->recorded($cheque, $actor, 'cash_bank.cheque_deposited', $date, 'deposited');
    }

    /** Hand our own cheque over. Nothing is posted: it is a promise until the bank pays it. */
    public function present(Cheque $cheque, string $on, ?User $actor = null): Cheque
    {
        $date = $this->date($on);

        $this->assertDirection($cheque, Cheque::DIRECTION_ISSUED, 'presented');
        $this->assertStatus($cheque, [Cheque::STATUS_ISSUED], 'presented');
        $this->assertNotBeforeItsDate($cheque, $date, 'A post-dated cheque cannot be presented before the date written on it. Handing it over early is what post-dating is for; banking it early is not.');

        $cheque->forceFill([
            'status' => Cheque::STATUS_PRESENTED,
            'presented_on' => $date,
        ])->save();

        return $this->recorded($cheque, $actor, 'cash_bank.cheque_presented', $date, 'presented');
    }

    /**
     * The bank paid it. This is the moment the ledger hears about the cheque, and
     * the entry is the ordinary one: money into the bank account against whatever
     * the cheque was for — a receivable collected, a payable settled, an expense
     * paid. The counter account was chosen when the cheque was written, because
     * the person holding the slip is the only one who knew.
     */
    public function clear(Cheque $cheque, string $on, ?User $actor = null): Cheque
    {
        $date = $this->date($on);

        $this->assertNotSettled($cheque, 'cleared');

        $waiting = $cheque->isReceived()
            ? [Cheque::STATUS_DEPOSITED]
            : [Cheque::STATUS_PRESENTED];

        $this->assertStatus(
            $cheque,
            $waiting,
            'cleared',
            $cheque->isReceived()
                ? 'A cheque that has not been deposited cannot have cleared — record the deposit first.'
                : 'A cheque that has not been presented cannot have cleared — record the presentation first.',
        );

        $this->assertNotBeforeItsDate($cheque, $date, 'A cheque cannot clear before the date written on it.');

        return DB::transaction(function () use ($cheque, $date, $actor) {
            $account = $cheque->account;
            $counter = $cheque->counterAccount;
            $amount = (string) $cheque->amount;
            $party = $this->party($cheque);

            $description = $cheque->isReceived()
                ? "Cheque {$cheque->cheque_no} from {$cheque->party_name} cleared into {$account?->name}"
                : "Cheque {$cheque->cheque_no} to {$cheque->party_name} cleared from {$account?->name}";

            $entry = $this->journals->post([
                'entry_date' => $date,
                'description' => $description,
                'narration' => $cheque->narration,
                'journal_type' => 'cheque_clearance',
                'source_type' => 'cheque',
                'source_id' => $cheque->id,
                'source_event' => 'cheque_cleared',
                'branch_id' => $cheque->branch_id,
                'lines' => $cheque->isReceived()
                    ? [
                        ['account_id' => $account?->id, 'dc' => 'debit', 'amount' => $amount],
                        [
                            'account_id' => $counter?->id,
                            'dc' => 'credit',
                            'amount' => $amount,
                            'party_type' => $party['type'],
                            'party_id' => $party['id'],
                        ],
                    ]
                    : [
                        [
                            'account_id' => $counter?->id,
                            'dc' => 'debit',
                            'amount' => $amount,
                            'party_type' => $party['type'],
                            'party_id' => $party['id'],
                        ],
                        ['account_id' => $account?->id, 'dc' => 'credit', 'amount' => $amount],
                    ],
            ], $actor);

            $cheque->forceFill([
                'status' => Cheque::STATUS_CLEARED,
                'cleared_on' => $date,
                'journal_entry_id' => $entry->id,
            ])->save();

            return $this->recorded($cheque, $actor, 'cash_bank.cheque_cleared', $date, 'cleared', [
                'entry_no' => $entry->entry_no,
                'amount' => $amount,
            ]);
        });
    }

    /**
     * The promise broke: the customer's cheque bounced, or ours was returned.
     *
     * If it had already cleared, the money has to go back where it came from, and
     * that is a reversal — never a quiet edit of the original entry, because the
     * original happened. If it had not cleared, there is nothing to reverse: the
     * ledger never heard about it in the first place, and pretending otherwise
     * would put money in the bank that no bank ever paid.
     */
    public function fail(Cheque $cheque, string $on, string $reason, ?User $actor = null): Cheque
    {
        $date = $this->date($on);
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why it failed — "bounced" without a reason is not something a customer can be told.');
        }

        $this->assertNotFailed($cheque);

        $failedStatus = $cheque->isReceived() ? Cheque::STATUS_BOUNCED : Cheque::STATUS_RETURNED;
        $hadCleared = $cheque->isCleared();

        return DB::transaction(function () use ($cheque, $date, $reason, $failedStatus, $hadCleared, $actor) {
            $reversalId = null;

            if ($hadCleared && $cheque->journal_entry_id !== null) {
                $entry = $cheque->journalEntry;

                if ($entry !== null) {
                    $reversal = $this->journals->reverse(
                        $entry,
                        'Cheque '.$cheque->cheque_no.' failed: '.$reason,
                        $actor,
                    );

                    $reversalId = $reversal->id;
                }
            }

            $cheque->forceFill([
                'status' => $failedStatus,
                'bounced_on' => $date,
                'bounced_reason' => $reason,
                'reversal_entry_id' => $reversalId,
            ])->save();

            return $this->recorded($cheque, $actor, 'cash_bank.cheque_failed', $date, $failedStatus, [
                'reason' => $reason,
                'had_cleared' => $hadCleared,
                'reversed_entry' => $cheque->reversalEntry?->entry_no,
            ]);
        });
    }

    // -------------------------------------------------------------- the desk

    /** Cheques in the register, newest first, with the two parties joined. */
    public function recent(?string $state = null, ?string $direction = null, ?int $accountId = null, ?string $search = null, int $limit = 100): Collection
    {
        return Cheque::query()
            ->where('company_id', $this->companyId())
            ->with(['account:id,code,name', 'counterAccount:id,code,name'])
            ->when($direction !== null, fn ($query) => $query->where('direction', $direction))
            ->when($state !== null, fn ($query) => $this->filterState($query, $state))
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->when($search !== null && trim($search) !== '', function ($query) use ($search) {
                $needle = '%'.trim($search).'%';

                $query->where(function ($inner) use ($needle) {
                    $inner->where('cheque_no', 'like', $needle)
                        ->orWhere('party_name', 'like', $needle)
                        ->orWhere('bank_name', 'like', $needle);
                });
            })
            ->orderByDesc('cheque_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * What the register is for: how much is sitting in promises right now, split
     * the way a cashier would count it.
     *
     * @return array{in_hand:array{count:int,total:string}, deposited:array{count:int,total:string},
     *               issued_pending:array{count:int,total:string}, post_dated:array{count:int,total:string},
     *               cleared_this_month:array{count:int,total:string}, failed_this_month:array{count:int,total:string}}
     */
    public function summary(?int $accountId = null): array
    {
        $base = fn () => Cheque::query()
            ->where('company_id', $this->companyId())
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId));

        $monthStart = now()->startOfMonth()->toDateString();

        return [
            'in_hand' => $this->aggregate(
                $base()->where('direction', Cheque::DIRECTION_RECEIVED)->where('status', Cheque::STATUS_RECEIVED),
            ),
            'deposited' => $this->aggregate(
                $base()->where('direction', Cheque::DIRECTION_RECEIVED)->where('status', Cheque::STATUS_DEPOSITED),
            ),
            'issued_pending' => $this->aggregate(
                $base()->where('direction', Cheque::DIRECTION_ISSUED)->whereIn('status', [Cheque::STATUS_ISSUED, Cheque::STATUS_PRESENTED]),
            ),
            'post_dated' => $this->aggregate(
                $base()->outstanding()->whereDate('cheque_date', '>', now()->toDateString()),
            ),
            'cleared_this_month' => $this->aggregate(
                $base()->where('status', Cheque::STATUS_CLEARED)->whereDate('cleared_on', '>=', $monthStart),
            ),
            'failed_this_month' => $this->aggregate(
                $base()->whereIn('status', [Cheque::STATUS_BOUNCED, Cheque::STATUS_RETURNED])->whereDate('bounced_on', '>=', $monthStart),
            ),
        ];
    }

    /** Every cheque still outstanding on one money account — the bank book's footnote. */
    public function outstanding(Account $account): Collection
    {
        return Cheque::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->outstanding()
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------- internals

    /** @param array<int, string> $statuses */
    protected function assertStatus(Cheque $cheque, array $statuses, string $verb, ?string $message = null): void
    {
        if (! in_array($cheque->status, $statuses, true)) {
            throw new RuntimeException(
                $message ?? 'This cheque is '.strtolower((string) $cheque->statusLabel()).' — it cannot be '.$verb.' from there.',
            );
        }
    }

    protected function assertDirection(Cheque $cheque, string $direction, string $verb): void
    {
        if ($cheque->direction !== $direction) {
            throw new RuntimeException(
                $direction === Cheque::DIRECTION_RECEIVED
                    ? 'That is a cheque the company issued — it is presented for payment, not deposited.'
                    : 'That is a cheque the company received — it is deposited, not presented.',
            );
        }

        unset($verb);
    }

    protected function assertNotSettled(Cheque $cheque, string $verb): void
    {
        if ($cheque->isCleared()) {
            throw new RuntimeException(
                'This cheque cleared on '.$cheque->cleared_on?->toDateString().' — a cleared cheque cannot be '.$verb.' again. If the money came back, record it as a failure and the entry is reversed.',
            );
        }

        $this->assertNotFailed($cheque);
    }

    /**
     * A cheque fails once. Its own guard, not `assertNotSettled`, because a
     * cheque that *cleared* is precisely the one that must be allowed through to
     * the failure path: the bank paid it, the money reached the books, and then
     * it came back — which is a reversal, not a second failure.
     */
    protected function assertNotFailed(Cheque $cheque): void
    {
        if ($cheque->hasFailed()) {
            throw new RuntimeException(
                'This cheque already failed on '.$cheque->bounced_on?->toDateString().' ('.$cheque->bounced_reason.'). Record a new cheque rather than re-opening this one.',
            );
        }
    }

    /** Post-dated protection: the date on the slip is a promise about a day. */
    protected function assertNotBeforeItsDate(Cheque $cheque, string $on, string $message): void
    {
        $date = Carbon::parse($on)->startOfDay();
        $written = $cheque->cheque_date?->copy()->startOfDay();

        if ($written !== null && $date->lessThan($written)) {
            throw new RuntimeException($message.' It is dated '.$written->toDateString().'.');
        }
    }

    protected function moneyAccount(int $id, int $companyId): Account
    {
        $account = Account::query()->where('company_id', $companyId)->find($id);

        if ($account === null) {
            throw new RuntimeException('That money account does not exist in this company.');
        }

        if (trim((string) $account->instrument) === '') {
            throw new RuntimeException($account->name.' is not a cash, bank or wallet account — a cheque clears through one of those.');
        }

        if (! $account->is_active) {
            throw new RuntimeException($account->name.' is closed. Money cannot move through a closed account.');
        }

        return $account;
    }

    protected function counterAccount(int $id, Account $money, int $companyId): Account
    {
        $account = Account::query()->where('company_id', $companyId)->find($id);

        if ($account === null) {
            throw new RuntimeException('The account this cheque is against does not exist in this company.');
        }

        if (! $account->is_active || $account->is_group) {
            throw new RuntimeException($account->name.' cannot take a posting: it is ' . ($account->is_group ? 'a heading, not a postable account' : 'closed') . '.');
        }

        if (trim((string) $account->instrument) !== '') {
            throw new RuntimeException(
                $account->name.' is also a money account. A cheque settling between two of the company\'s own accounts is a transfer, not a cheque — use the transfer desk.',
            );
        }

        if ((int) $account->id === (int) $money->id) {
            throw new RuntimeException('A cheque cannot be drawn on the account it is being paid into.');
        }

        return $account;
    }

    /**
     * The same leaf, twice. For cheques the company issued the number belongs to
     * one book and cannot repeat; for received cheques the number belongs to
     * whoever wrote it, so the guard is the whole slip: same account, same number,
     * same amount, same date is the same cheque, and entering it twice would
     * promise the same money twice.
     */
    protected function assertNotADoubleEntry(int $companyId, Account $account, string $direction, string $chequeNo, string $amount, string $chequeDate): void
    {
        $existing = Cheque::query()
            ->where('company_id', $companyId)
            ->where('account_id', $account->id)
            ->where('direction', $direction)
            ->where('cheque_no', $chequeNo)
            ->when(
                $direction === Cheque::DIRECTION_RECEIVED,
                fn ($query) => $query->where('amount', $amount)->whereDate('cheque_date', $chequeDate),
            )
            ->first();

        if ($existing !== null) {
            throw new RuntimeException(
                'Cheque '.$chequeNo.' is already in the register for '.$account->name.' ('
                .$existing->statusLabel().', '.number_format((float) $existing->amount, 2).'). '
                .($direction === Cheque::DIRECTION_ISSUED
                    ? 'A cheque book has one of each number — open a new cheque instead of writing this leaf twice.'
                    : 'Entering the same slip twice would promise the same money twice.'),
            );
        }
    }

    protected function amount(string|float|int $amount): string
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new RuntimeException('A cheque is for a real amount — zero and negative cheques do not exist.');
        }

        return number_format((float) $amount, 4, '.', '');
    }

    protected function date(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException('"'.$value.'" is not a date this desk reads.');
        }
    }

    /** @return array{type:?string,id:?int} */
    protected function party(Cheque $cheque): array
    {
        if ($cheque->customer_id !== null) {
            return ['type' => 'customer', 'id' => (int) $cheque->customer_id];
        }

        if ($cheque->supplier_id !== null) {
            return ['type' => 'supplier', 'id' => (int) $cheque->supplier_id];
        }

        return ['type' => null, 'id' => null];
    }

    /** @param class-string $model */
    protected function assertPartyExists(string $model, int $id, int $companyId, string $what): void
    {
        $exists = $model::query()->where('company_id', $companyId)->whereKey($id)->exists();

        if (! $exists) {
            throw new RuntimeException('That '.$what.' is not on this company\'s books.');
        }
    }

    /** @param array<string, mixed> $extra */
    protected function recorded(Cheque $cheque, ?User $actor, string $action, string $on, string $state, array $extra = []): Cheque
    {
        $cheque->refresh();

        $this->audit->record([
            'action' => $action,
            'entity_type' => 'cheque',
            'entity_id' => $cheque->id,
            'actor_id' => $actor?->id,
            'branch_id' => $cheque->branch_id,
            'amount' => (string) $cheque->amount,
            'after' => array_merge([
                'cheque_no' => $cheque->cheque_no,
                'direction' => $cheque->direction,
                'state' => $state,
                'on' => $on,
            ], $extra),
        ]);

        return $cheque;
    }

    /** @return array{count:int,total:string} */
    protected function aggregate(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $row = $query->selectRaw('COUNT(*) as entries, COALESCE(SUM(amount), 0) as total')->first();

        return [
            'count' => (int) ($row->entries ?? 0),
            'total' => number_format((float) ($row->total ?? 0), 4, '.', ''),
        ];
    }

    protected function filterState(\Illuminate\Database\Eloquent\Builder $query, string $state): \Illuminate\Database\Eloquent\Builder
    {
        return match ($state) {
            'outstanding' => $query->outstanding(),
            'post_dated' => $query->outstanding()->whereDate('cheque_date', '>', now()->toDateString()),
            'failed' => $query->whereIn('status', [Cheque::STATUS_BOUNCED, Cheque::STATUS_RETURNED]),
            default => array_key_exists($state, Cheque::STATUSES)
                ? $query->where('status', $state)
                : $query->whereRaw('1 = 0'), // an unknown filter shows nothing rather than everything
        };
    }

    protected function companyId(): int
    {
        $companyId = $this->context->companyId();

        if ($companyId === null) {
            throw new RuntimeException('No company context for the cheque register.');
        }

        return (int) $companyId;
    }
}
