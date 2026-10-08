<?php

namespace App\Domain\Business;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-15 — one month's bill from one provider.
 *
 * The row is the obligation: what the provider asked for, for which month, by
 * when. Paying it posts exactly one journal entry (Dr the provider's expense
 * account, Cr the account the money left) and the row remembers which entry
 * closed it, so the register and the ledger can be asked the same question.
 *
 * `status` is a life cycle — recorded, held for approval, paid, void — and the
 * thing people actually ask about, *is it overdue*, is computed from `due_date`
 * against the clock. A stored overdue flag is only true after the nightly job has
 * run, and this register is read in the morning before it has.
 */
class UtilityBill extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_RECORDED,
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_VOID,
    ];

    /** How near a due date counts as “deal with this now”. */
    public const DUE_SOON_DAYS = 14;

    protected $fillable = [
        'company_id', 'branch_id', 'provider_id', 'bill_no', 'period_month',
        'issue_date', 'due_date', 'amount', 'consumption', 'consumption_unit',
        'meter_reading', 'narration', 'status', 'approval_gate', 'approval_threshold',
        'decided_by', 'decided_at', 'decision_note', 'paid_on', 'money_account_id',
        'journal_entry_id', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_on' => 'date',
        'decided_at' => 'datetime',
        'amount' => 'decimal:2',
        'consumption' => 'decimal:4',
        'meter_reading' => 'decimal:3',
        'approval_gate' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(UtilityProvider::class, 'provider_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /* ------------------------------------------------------------------ state */

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Money that has actually left, or is on its way out. */
    public function isOpen(): bool
    {
        return ! $this->isPaid() && ! $this->isVoid();
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->isPast();
    }

    public function daysToDue(): ?int
    {
        if ($this->due_date === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->due_date, false);
    }

    public function isDueWithin(int $days = self::DUE_SOON_DAYS): bool
    {
        $in = $this->daysToDue();

        return $this->isOpen() && $in !== null && $in >= 0 && $in <= $days;
    }

    /**
     * The one word the desk shows: what is true about this bill right now.
     * Read from the clock, never stored.
     */
    public function state(): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }

        if ($this->isVoid()) {
            return 'void';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return $this->isDueWithin() ? 'due_soon' : 'scheduled';
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            'paid' => 'Paid',
            'void' => 'Void',
            'overdue' => 'Overdue',
            'due_soon' => 'Due soon',
            default => 'Not due yet',
        };
    }

    public function periodLabel(): string
    {
        return $this->period_month !== null
            ? Carbon::createFromFormat('Y-m', (string) $this->period_month)->format('F Y')
            : '—';
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, self::STATUS_VOID]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', Carbon::today()->toDateString());
    }
}
