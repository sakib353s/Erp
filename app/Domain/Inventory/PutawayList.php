<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Purchase\Models\GoodsReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A putaway list (§04-44): where the goods on a receipt are going to live.
 *
 * Goods arrive on a dock and end up somewhere; a putaway list is that decision,
 * written down. It hangs off the receipt because the receipt is what brought the
 * stock in — there is no other honest source for a putaway task.
 *
 * Like a pick list it holds no balance. What it *does* change is the map: when
 * somebody says "this pallet went into B-04", the system learns that the product
 * has a home there (the first home it learns becomes the pick face). Directions
 * are exactly what the bin tables are for.
 */
class PutawayList extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PUTTING_AWAY = 'putting_away';

    public const STATUS_PUT_AWAY = 'put_away';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_PUTTING_AWAY => 'Being put away',
        self::STATUS_PUT_AWAY => 'Put away',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_ASSIGNED, self::STATUS_PUTTING_AWAY];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'goods_receipt_id', 'code', 'status',
        'assigned_to', 'created_by', 'notes', 'started_at', 'completed_at',
        'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PutawayListLine::class)->orderBy('line_no');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPutAway(): bool
    {
        return $this->status === self::STATUS_PUT_AWAY;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function requiredQty(): float
    {
        return round((float) $this->lines->sum('quantity'), 4);
    }

    public function placedQty(): float
    {
        return round((float) $this->lines->sum('placed_quantity'), 4);
    }

    public function pendingLines(): int
    {
        return $this->lines->filter(fn (PutawayListLine $line) => ! $line->isPlaced())->count();
    }

    /** Lines nobody has said where to put yet — the system has no home for them. */
    public function linesWithoutBin(): int
    {
        return $this->lines->filter(fn (PutawayListLine $line) => ! $line->hasBin())->count();
    }

    /** How much of the dock has been cleared, 0–100. */
    public function progressPct(): int
    {
        $required = $this->requiredQty();

        if ($required <= 0) {
            return 0;
        }

        return (int) min(100, round($this->placedQty() / $required * 100));
    }

    public function stateLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function stateTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'draft',
            self::STATUS_ASSIGNED => 'assigned',
            self::STATUS_PUTTING_AWAY => 'counting',
            self::STATUS_PUT_AWAY => 'ok',
            default => 'cancelled',
        };
    }

    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}
