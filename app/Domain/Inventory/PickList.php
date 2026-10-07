<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pick list (§04-44): the walk a picker makes to fill an order.
 *
 * It is an *instruction*, not a ledger entry. Nothing on this document moves
 * stock: completing it says "the goods are off the shelf and on the dock", and
 * the dispatch that follows is still the moment the ledger changes. That
 * separation is what keeps one truth about quantities — the moment a pick list
 * wrote movements, cancelling a pick after a dispatch would double-count.
 *
 * The list carries a bin per line because a bin is a place (§04-42/43), and a
 * batch per line because for batch-tracked goods the shelf should give up the
 * earliest expiry first (§04-37…41).
 */
class PickList extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_PICKING = 'picking';

    public const STATUS_PICKED = 'picked';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ASSIGNED => 'Assigned',
        self::STATUS_PICKING => 'Being picked',
        self::STATUS_PICKED => 'Picked',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** Statuses in which the walk is still ahead of somebody. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_ASSIGNED, self::STATUS_PICKING];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'sales_order_id', 'code', 'status',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
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
        return $this->hasMany(PickListLine::class)->orderBy('line_no');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPicked(): bool
    {
        return $this->status === self::STATUS_PICKED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** What the picker was asked to take, across the sheet. */
    public function requiredQty(): float
    {
        return round((float) $this->lines->sum('quantity'), 4);
    }

    /** What has actually left the shelf so far. */
    public function pickedQty(): float
    {
        return round((float) $this->lines->sum('picked_quantity'), 4);
    }

    /**
     * Lines where nothing has been picked yet. The register and the sheet both
     * read this, because "how far along am I" is the only question a picker asks
     * of a pick list.
     */
    public function pendingLines(): int
    {
        return $this->lines->filter(fn (PickListLine $line) => ! $line->isPicked())->count();
    }

    /** Lines the system cannot say where to walk for — somebody has to say. */
    public function linesWithoutBin(): int
    {
        return $this->lines->filter(fn (PickListLine $line) => ! $line->hasBin())->count();
    }

    /** How far along the walk is, 0–100 — a number a card can show honestly. */
    public function progressPct(): int
    {
        $required = $this->requiredQty();

        if ($required <= 0) {
            return 0;
        }

        return (int) min(100, round($this->pickedQty() / $required * 100));
    }

    public function stateLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Badge tone — the words come from stateLabel(), the colour from here. */
    public function stateTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'draft',
            self::STATUS_ASSIGNED => 'assigned',
            self::STATUS_PICKING => 'counting',
            self::STATUS_PICKED => 'ok',
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
