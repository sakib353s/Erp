<?php

namespace App\Domain\CashBank;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §08-21 — asking for money out of the float before it is spent.
 *
 * Above the company's limit this is the only way a voucher happens, and the
 * answer comes from somebody other than the person who asked. A request is not a
 * payment and is not stored as one: until it is approved there is no voucher, no
 * payment row and nothing in the ledger — a waiting request must never be
 * mistakable for money that has moved.
 */
class PettyCashRequest extends Model
{
    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for approval',
        self::STATUS_APPROVED => 'Approved and paid',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'fund_id', 'expense_category_id', 'requested_by',
        'payee', 'narration', 'needed_on', 'amount', 'status',
        'decided_by', 'decided_at', 'decision_note', 'payment_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'needed_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    /** The tone the desk prints it in; the status itself is the vocabulary. */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'approved',
            self::STATUS_REJECTED => 'rejected',
            default => 'pending_approval',
        };
    }
}
