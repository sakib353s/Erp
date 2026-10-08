<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\CashTransfer;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Sales\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money in, money out, and money moved between the company's own accounts
 * (§08-02, §08-03, §08-04).
 *
 * Three rules this service exists to keep, in one place:
 *
 *  1. **Every movement is a journal entry.** Cash in from a walk-in customer and
 *     a bank charge are the same kind of event to the books; nothing here writes
 *     a balance without a balanced pair of lines behind it, and the pair is
 *     posted by the one service allowed to post (§7.1).
 *  2. **Money leaves through the account the operator named.** Not through a
 *     default account resolved from a rule, because "which bank" is exactly the
 *     question the desk exists to answer, and a rule that guesses it is a rule
 *     that mis-files a deposit once a month.
 *  3. **A movement has an owner.** Every entry names its counter-account, and
 *     where a party is named (a customer, a supplier) the party travels on the
 *     line, so the party statement and the cash book tell the same story.
 *
 * What this desk deliberately does not do: settle an invoice or a bill. Those
 * balances belong to the document that carries them — the invoice screen and the
 * bill screen move them — and a receipt recorded in two places is a customer
 * balance nobody can reconcile. The desk says so on the screen.
 */
class MoneyMovementService
{
    /** The direction values `payments.direction` has always held. */
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected JournalPostingService $journal,
        protected NumberingService $numbering,
        protected MoneyAccountService $accounts,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for cash and bank.'));
    }

    /**
     * Money in: something was paid to the company, and the ledger has to see it.
     *
     * The counter-account is the operator's answer to "what is this money for" —
     * sales, other income, a customer's account, a deposit taken. It is not
     * guessed from an event type, because a receipt with no document behind it
     * has no event type that could be anything but a guess.
     *
     * @param  array{money_account_id:int,counter_account_id:int,amount:int|float|string,
     *               received_on?:string,payer?:?string,customer_id?:?int,reference?:?string,
     *               narration?:?string,idempotency_key?:?string,branch_id?:?int}  $payload
     */
    public function receive(array $payload, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payload, $actorId) {
            $companyId = $this->companyId();
            $key = $payload['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = Payment::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $amount = round((float) ($payload['amount'] ?? 0), 4);

            if ($amount <= 0) {
                throw new RuntimeException('A receipt has to be more than nothing — the bank will not post a zero.');
            }

            $money = $this->moneyAccount((int) $payload['money_account_id']);
            $counter = $this->counterAccount((int) $payload['counter_account_id'], $money);

            $date = $this->date($payload['received_on'] ?? null);
            $party = $this->party($payload['customer_id'] ?? null, 'customer');
            $payer = trim((string) ($payload['payer'] ?? '')) ?: ($party['label'] ?? 'Walk-in payer');

            $receiptNo = $this->numbering->allocate(
                $this->documentType('money_receipt'),
                $payload['branch_id'] ?? $this->context->branchId(),
            );

            $entry = $this->journal->post([
                'entry_date' => $date,
                'description' => "Receipt {$receiptNo} from {$payer} into {$money->name}",
                'narration' => $payload['narration'] ?? null,
                'journal_type' => 'cash_receipt',
                'source_type' => 'cash_receipt',
                'source_id' => null,
                'source_event' => 'cash_receipt',
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'lines' => [
                    ['account_id' => $money->id, 'dc' => 'debit', 'amount' => $amount],
                    [
                        'account_id' => $counter->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                        'party_type' => $party['type'] ?? null,
                        'party_id' => $party['id'] ?? null,
                    ],
                ],
            ], $this->actor($actorId));

            $payment = Payment::create([
                'company_id' => $companyId,
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'customer_id' => $payload['customer_id'] ?? null,
                'account_id' => $money->id,
                'receipt_no' => $receiptNo,
                'direction' => self::DIRECTION_IN,
                'method' => MoneyAccountService::METHODS[$this->accounts->instrumentOf($money)] ?? 'cash',
                'amount' => number_format($amount, 4, '.', ''),
                'status' => 'posted',
                'paid_at' => $date,
                'reference' => $payload['reference'] ?? null,
                'narration' => $payload['narration'] ?? null,
                'idempotency_key' => $key,
                'journal_entry_id' => $entry->id,
                'created_by' => $actorId,
            ]);

            $this->audit->record([
                'action' => 'cash_bank.receipt_recorded',
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'branch_id' => $payment->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'receipt_no' => $receiptNo,
                    'amount' => $amount,
                    'into' => $money->name,
                    'against' => $counter->name,
                    'payer' => $payer,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $payment->refresh();
        });
    }

    /**
     * Money out: an expense, an advance, a payment to somebody — again against
     * the account the operator named, from the account it actually left.
     *
     * @param  array{money_account_id:int,counter_account_id:int,amount:int|float|string,
     *               paid_on?:string,payee?:?string,supplier_id?:?int,reference?:?string,
     *               narration?:?string,idempotency_key?:?string,branch_id?:?int}  $payload
     */
    public function pay(array $payload, ?int $actorId = null): Payment
    {
        return DB::transaction(function () use ($payload, $actorId) {
            $companyId = $this->companyId();
            $key = $payload['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = Payment::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $amount = round((float) ($payload['amount'] ?? 0), 4);

            if ($amount <= 0) {
                throw new RuntimeException('A payment has to be more than nothing.');
            }

            $money = $this->moneyAccount((int) $payload['money_account_id']);
            $counter = $this->counterAccount((int) $payload['counter_account_id'], $money);

            $date = $this->date($payload['paid_on'] ?? null);
            $party = $this->party($payload['supplier_id'] ?? null, 'supplier');
            $payee = trim((string) ($payload['payee'] ?? '')) ?: ($party['label'] ?? 'Unnamed payee');

            $voucherNo = $this->numbering->allocate(
                $this->documentType('expense_voucher'),
                $payload['branch_id'] ?? $this->context->branchId(),
            );

            $entry = $this->journal->post([
                'entry_date' => $date,
                'description' => "Payment {$voucherNo} to {$payee} from {$money->name}",
                'narration' => $payload['narration'] ?? null,
                'journal_type' => 'cash_payment',
                'source_type' => 'cash_payment',
                'source_id' => null,
                'source_event' => 'cash_payment',
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'lines' => [
                    [
                        'account_id' => $counter->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                        'party_type' => $party['type'] ?? null,
                        'party_id' => $party['id'] ?? null,
                    ],
                    ['account_id' => $money->id, 'dc' => 'credit', 'amount' => $amount],
                ],
            ], $this->actor($actorId));

            $payment = Payment::create([
                'company_id' => $companyId,
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'supplier_id' => $payload['supplier_id'] ?? null,
                'account_id' => $money->id,
                'receipt_no' => $voucherNo,
                'direction' => self::DIRECTION_OUT,
                'method' => MoneyAccountService::METHODS[$this->accounts->instrumentOf($money)] ?? 'cash',
                'amount' => number_format($amount, 4, '.', ''),
                'status' => 'posted',
                'paid_at' => $date,
                'reference' => $payload['reference'] ?? null,
                'narration' => $payload['narration'] ?? null,
                'idempotency_key' => $key,
                'journal_entry_id' => $entry->id,
                'created_by' => $actorId,
            ]);

            $this->audit->record([
                'action' => 'cash_bank.payment_recorded',
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'branch_id' => $payment->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'voucher_no' => $voucherNo,
                    'amount' => $amount,
                    'from' => $money->name,
                    'against' => $counter->name,
                    'payee' => $payee,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $payment->refresh();
        });
    }

    /**
     * Move money between two of the company's own accounts.
     *
     * Both legs or neither: the journal entry is one document (Dr the account the
     * money landed in, Cr the one it left), and the transfer row is written in
     * the same transaction, so a failure anywhere leaves no half-booked move. A
     * repeated request with the same idempotency key returns the transfer that
     * already exists rather than moving the money again.
     *
     * @param  array{from_account_id:int,to_account_id:int,amount:int|float|string,
     *               transferred_on?:string,reference?:?string,narration?:?string,
     *               idempotency_key?:?string,branch_id?:?int}  $payload
     */
    public function transfer(array $payload, ?int $actorId = null): CashTransfer
    {
        return DB::transaction(function () use ($payload, $actorId) {
            $companyId = $this->companyId();
            $key = $payload['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = CashTransfer::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $amount = round((float) ($payload['amount'] ?? 0), 4);

            if ($amount <= 0) {
                throw new RuntimeException('A transfer has to be more than nothing.');
            }

            $from = $this->moneyAccount((int) $payload['from_account_id']);
            $to = $this->moneyAccount((int) $payload['to_account_id']);

            if ($from->id === $to->id) {
                throw new RuntimeException('Money cannot be transferred from an account to itself — it is already there.');
            }

            $date = $this->date($payload['transferred_on'] ?? null);

            $transferNo = $this->numbering->allocate(
                $this->documentType('cash_transfer'),
                $payload['branch_id'] ?? $this->context->branchId(),
            );

            $entry = $this->journal->post([
                'entry_date' => $date,
                'description' => "Transfer {$transferNo}: {$from->name} → {$to->name}",
                'narration' => $payload['narration'] ?? null,
                'journal_type' => 'cash_transfer',
                'source_type' => 'cash_transfer',
                'source_id' => null,
                'source_event' => 'cash_transfer',
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'lines' => [
                    ['account_id' => $to->id, 'dc' => 'debit', 'amount' => $amount],
                    ['account_id' => $from->id, 'dc' => 'credit', 'amount' => $amount],
                ],
            ], $this->actor($actorId));

            $transfer = CashTransfer::create([
                'company_id' => $companyId,
                'branch_id' => $payload['branch_id'] ?? $this->context->branchId(),
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'transfer_no' => $transferNo,
                'transferred_on' => $date,
                'amount' => number_format($amount, 4, '.', ''),
                'status' => CashTransfer::STATUS_POSTED,
                'reference' => $payload['reference'] ?? null,
                'narration' => $payload['narration'] ?? null,
                'journal_entry_id' => $entry->id,
                'idempotency_key' => $key,
                'created_by' => $actorId,
            ]);

            $this->audit->record([
                'action' => 'cash_bank.transfer_posted',
                'entity_type' => 'cash_transfer',
                'entity_id' => $transfer->id,
                'branch_id' => $transfer->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'transfer_no' => $transferNo,
                    'amount' => $amount,
                    'from' => $from->name,
                    'to' => $to->name,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $transfer->refresh()->load(['fromAccount', 'toAccount']);
        });
    }

    /** Money in, most recent first — the receipt book. */
    public function receipts(int $limit = 50, ?int $accountId = null): Collection
    {
        return Payment::query()
            ->where('company_id', $this->companyId())
            ->where('direction', self::DIRECTION_IN)
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->with(['account', 'customer', 'journalEntry'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Money out, most recent first — the payment book. */
    public function payments(int $limit = 50, ?int $accountId = null): Collection
    {
        return Payment::query()
            ->where('company_id', $this->companyId())
            ->where('direction', self::DIRECTION_OUT)
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->with(['account', 'supplier', 'journalEntry'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Transfers between the company's own accounts. */
    public function transfers(int $limit = 50): Collection
    {
        return CashTransfer::query()
            ->where('company_id', $this->companyId())
            ->with(['fromAccount', 'toAccount', 'journalEntry', 'creator'])
            ->orderByDesc('transferred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Money in less money out per day over a window — what the desk draws as movement. */
    public function dailyNet(int $window = 14): array
    {
        $since = now()->subDays($window - 1)->toDateString();

        $rows = Payment::query()
            ->where('company_id', $this->companyId())
            ->where('status', 'posted')
            ->whereDate('paid_at', '>=', $since)
            ->groupBy('paid_at', 'direction')
            ->selectRaw("paid_at, direction, COALESCE(SUM(amount), 0) as total")
            ->get();

        $days = [];

        for ($i = $window - 1; $i >= 0; $i--) {
            $days[now()->subDays($i)->toDateString()] = ['in' => 0.0, 'out' => 0.0];
        }

        foreach ($rows as $row) {
            // The model casts paid_at to a date, and a grouped raw select still
            // goes through the casts — so the key is read as a date, never as the
            // string a Carbon would stringify to at midnight.
            $date = $row->paid_at instanceof \DateTimeInterface
                ? $row->paid_at->format('Y-m-d')
                : substr((string) $row->paid_at, 0, 10);

            if (isset($days[$date])) {
                $days[$date][$row->direction === self::DIRECTION_IN ? 'in' : 'out'] += (float) $row->total;
            }
        }

        return $days;
    }

    /** The account money moves through, or the reason it cannot. */
    protected function moneyAccount(int $accountId): Account
    {
        $account = Account::query()
            ->where('company_id', $this->companyId())
            ->find($accountId);

        if ($account === null) {
            throw new RuntimeException('That money account does not exist in this company.');
        }

        if (! $this->accounts->isMoney($account)) {
            throw new RuntimeException("{$account->name} is not an account money sits in — pick a cash, bank or wallet account.");
        }

        if (! $account->is_active) {
            throw new RuntimeException("{$account->name} is closed to new movement.");
        }

        return $account;
    }

    /** The other side of the entry: an expense, an income, a party's account. */
    protected function counterAccount(int $accountId, Account $money): Account
    {
        $account = Account::query()
            ->where('company_id', $this->companyId())
            ->find($accountId);

        if ($account === null) {
            throw new RuntimeException('The account this money is against does not exist in this company.');
        }

        if ($account->id === $money->id) {
            throw new RuntimeException('The same account cannot be on both sides of one movement.');
        }

        if ($account->is_group) {
            throw new RuntimeException("{$account->name} is a heading, not an account money can be posted to.");
        }

        if (! $account->is_active) {
            throw new RuntimeException("{$account->name} is inactive.");
        }

        return $account;
    }

    /** @return array{type: ?string, id: ?int, label: ?string}|null */
    protected function party(?int $partyId, string $type): ?array
    {
        if ($partyId === null) {
            return null;
        }

        $model = $type === 'customer'
            ? \App\Domain\Masters\Customer::class
            : \App\Domain\Masters\Supplier::class;

        $party = $model::query()
            ->where('company_id', $this->companyId())
            ->find($partyId);

        if ($party === null) {
            throw new RuntimeException("That {$type} does not exist in this company.");
        }

        return ['type' => $type, 'id' => $party->id, 'label' => $party->name];
    }

    protected function date(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return now()->toDateString();
        }

        return \Illuminate\Support\Carbon::parse($date)->toDateString();
    }

    protected function documentType(string $code): int
    {
        return (int) (DocumentType::query()->where('code', $code)->value('id')
            ?? abort(500, "{$code} document type is not seeded."));
    }

    protected function actor(?int $actorId): ?User
    {
        return $actorId !== null ? User::query()->find($actorId) : null;
    }
}
