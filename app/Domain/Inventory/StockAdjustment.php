<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock adjustment document (line-level +/−, reason). Posting creates
 * ADJUST_IN/ADJUST_OUT movements via StockLedgerService only.
 *
 * §04-26: an adjustment worth more than the configured threshold is created
 * `pending_approval` and touches nothing until a second person approves it — a
 * pending document has no ledger rows behind it, so "waiting" and "done" can
 * never be confused for one another.
 */
class StockAdjustment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for approval',
        self::STATUS_POSTED => 'Posted',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_DRAFT => 'Draft',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'adjustment_no',
        'adjustment_date', 'reason', 'total_value', 'status',
        'created_by', 'approved_by', 'posted_at', 'approved_at', 'approval_note',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'posted_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_value' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('line_no');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** The direction mix, for the badge column: both, increase or decrease. */
    public function movementLabel(): string
    {
        $up = $this->lines->contains(fn (StockAdjustmentLine $line) => (float) $line->qty_delta > 0);
        $down = $this->lines->contains(fn (StockAdjustmentLine $line) => (float) $line->qty_delta < 0);

        return match (true) {
            $up && $down => 'mixed',
            $up => 'increase',
            $down => 'decrease',
            default => 'empty',
        };
    }
}
