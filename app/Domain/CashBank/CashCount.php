<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §08-05 — a drawer counted against the books.
 *
 * The row exists to keep three numbers apart that a single "cash" figure would
 * blur: what the ledger said (`expected_amount`), what a person found
 * (`counted_amount`), and the gap between them (`variance`) — plus the number the
 * gap was judged against (`tolerance`). Nothing about the count is derived after
 * the fact: a count reads the same next year as the day the tin was emptied onto
 * the table.
 */
class CashCount extends Model
{
    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for approval',
        self::STATUS_POSTED => 'Counted and posted',
        self::STATUS_REJECTED => 'Refused',
    ];

    public const KIND_NOTE = 'note';

    public const KIND_COIN = 'coin';

    public const KINDS = [
        self::KIND_NOTE => 'Note',
        self::KIND_COIN => 'Coin',
    ];

    /** The denominations this market actually counts, largest first. */
    public const DENOMINATIONS = [
        [self::KIND_NOTE, '1000'],
        [self::KIND_NOTE, '500'],
        [self::KIND_NOTE, '200'],
        [self::KIND_NOTE, '100'],
        [self::KIND_NOTE, '50'],
        [self::KIND_NOTE, '20'],
        [self::KIND_NOTE, '10'],
        [self::KIND_COIN, '5'],
        [self::KIND_COIN, '2'],
        [self::KIND_COIN, '1'],
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'account_id', 'counted_on',
        'expected_amount', 'counted_amount', 'variance', 'tolerance', 'status',
        'difference_reason', 'notes', 'journal_entry_id',
        'counted_by', 'decided_by', 'decided_at', 'decision_note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'counted_on' => 'date',
            'expected_amount' => 'decimal:4',
            'counted_amount' => 'decimal:4',
            'variance' => 'decimal:4',
            'tolerance' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** @return HasMany<CashCountLine> */
    public function lines(): HasMany
    {
        return $this->hasMany(CashCountLine::class)->orderBy('position');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isShort(): bool
    {
        return (float) $this->variance < -0.0001;
    }

    public function isOver(): bool
    {
        return (float) $this->variance > 0.0001;
    }

    /** Is the drawer exactly what the books said it would be? */
    public function balanced(): bool
    {
        return abs((float) $this->variance) <= 0.0001;
    }

    /** The words a person would use for the gap. */
    public function varianceLabel(): string
    {
        if ($this->balanced()) {
            return 'Counted exactly';
        }

        return number_format(abs((float) $this->variance), 2, '.', '');
    }

    /** The tone the desk prints the gap in: short is a loss, over is not a win. */
    public function varianceTone(): string
    {
        if ($this->balanced()) {
            return 'balanced';
        }

        return $this->isShort() ? 'short' : 'over';
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_POSTED => 'posted',
            self::STATUS_REJECTED => 'rejected',
            default => 'pending_approval',
        };
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }
}
