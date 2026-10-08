<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cheque the company gave, or one it is holding (§08-13).
 *
 * The states are the promise's life, not decoration:
 *
 *   received  → deposited → cleared | bounced
 *   issued    → presented → cleared | returned
 *
 * `received`/`issued` mean the slip exists and nothing has happened to it;
 * `deposited`/`presented` mean it has been handed to a bank, which is not the
 * same as the money moving; `cleared` means the bank paid it, and that is the
 * moment the ledger hears about it. `bounced`/`returned` mean it did not, and if
 * it had already cleared, that is a reversal — the money went back out.
 *
 * Nothing here computes a balance. The register answers "what is hanging over
 * this account", and the position of the money stays the ledger's business.
 */
class Cheque extends Model
{
    use Auditable;

    public const DIRECTION_RECEIVED = 'received';

    public const DIRECTION_ISSUED = 'issued';

    public const STATUS_RECEIVED = 'received';

    /** Written by us and not yet presented: the mirror image of `received`. */
    public const STATUS_ISSUED = 'issued';

    public const STATUS_DEPOSITED = 'deposited';

    public const STATUS_PRESENTED = 'presented';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_RETURNED = 'returned';

    public const DIRECTIONS = [
        self::DIRECTION_RECEIVED => 'Received from a customer',
        self::DIRECTION_ISSUED => 'Issued to a supplier or payee',
    ];

    /**
     * The states a cheque can be in, in the order the desk reads them.
     *
     * `received` and `issued` are the same fact from two sides — the slip is in
     * a drawer and the money has not moved — so both appear here: leaving one
     * out would have made a cheque we wrote unfilterable on the register it
     * lives in.
     */
    public const STATUSES = [
        self::STATUS_RECEIVED => 'Received — in hand',
        self::STATUS_ISSUED => 'Issued — not yet presented',
        self::STATUS_DEPOSITED => 'Deposited, awaiting clearing',
        self::STATUS_PRESENTED => 'Presented, awaiting clearing',
        self::STATUS_CLEARED => 'Cleared',
        self::STATUS_BOUNCED => 'Bounced',
        self::STATUS_RETURNED => 'Returned unpaid',
    ];

    /** The states that mean the promise is still open. The register's default. */
    public const OPEN_STATES = [
        self::STATUS_RECEIVED,
        self::STATUS_ISSUED,
        self::STATUS_DEPOSITED,
        self::STATUS_PRESENTED,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'direction', 'cheque_no', 'cheque_date', 'bank_name',
        'account_id', 'counter_account_id', 'party_name', 'customer_id', 'supplier_id',
        'amount', 'currency', 'status', 'deposited_on', 'presented_on', 'cleared_on',
        'bounced_on', 'bounced_reason', 'journal_entry_id', 'reversal_entry_id',
        'reference', 'narration', 'created_by',
    ];

    protected $casts = [
        'cheque_date' => 'date',
        'deposited_on' => 'date',
        'presented_on' => 'date',
        'cleared_on' => 'date',
        'bounced_on' => 'date',
        'amount' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The money account this cheque will clear through. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** What it is for: the receivable, payable, expense or income it settles. */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The entry posted when it cleared. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** The entry that put the money back when it bounced. */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReceived(): bool
    {
        return $this->direction === self::DIRECTION_RECEIVED;
    }

    public function isIssued(): bool
    {
        return $this->direction === self::DIRECTION_ISSUED;
    }

    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED;
    }

    /** Failed: bounced (ours came back) or returned (ours was not honoured). */
    public function hasFailed(): bool
    {
        return in_array($this->status, [self::STATUS_BOUNCED, self::STATUS_RETURNED], true);
    }

    /** Waiting on a bank: deposited or presented, not yet cleared. */
    public function isWithBank(): bool
    {
        return in_array($this->status, [self::STATUS_DEPOSITED, self::STATUS_PRESENTED], true);
    }

    /** Still in somebody's drawer — nothing has been handed to a bank yet. */
    public function isInHand(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_ISSUED], true);
    }

    /**
     * Written for a date that has not arrived. A post-dated cheque is a real
     * instrument and a real trap: it cannot be deposited early, and it must not
     * be spent before its date. The desk refuses both.
     */
    public function isPostDated(): bool
    {
        return $this->cheque_date !== null
            && $this->cheque_date->greaterThan(now()->startOfDay())
            && ! $this->isCleared()
            && ! $this->hasFailed();
    }

    /** Nothing further can happen to it: a cleared cheque or a failed one. */
    public function isSettled(): bool
    {
        return $this->isCleared() || $this->hasFailed();
    }

    public function statusLabel(): string
    {
        if ($this->isPostDated() && $this->isInHand()) {
            return 'Post-dated · '.self::STATUSES[$this->status];
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Which tone the status badge wears. `received` is a positive word
     * everywhere else in this application (a purchase arrived, a transfer landed)
     * but a cheque in the drawer is money that has *not* arrived, so the register
     * badges it as outstanding instead of borrowing a green word for it.
     */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_RECEIVED => 'outstanding',
            self::STATUS_BOUNCED, self::STATUS_RETURNED => 'failed',
            default => $this->status,
        };
    }

    /** "Received · 004512 · Islami Bank · 26,000.00" — how the desk refers to it. */
    public function label(): string
    {
        return ($this->isReceived() ? 'Received' : 'Issued')
            .' · '.$this->cheque_no
            .' · '.$this->bank_name
            .' · '.number_format((float) $this->amount, 2);
    }

    /** The state a signed-off reconciliation is to a bank book: history. */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CLEARED, self::STATUS_BOUNCED, self::STATUS_RETURNED]);
    }

    /** Everything still hanging over the account — what the register is for. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_CLEARED, self::STATUS_BOUNCED, self::STATUS_RETURNED]);
    }
}
