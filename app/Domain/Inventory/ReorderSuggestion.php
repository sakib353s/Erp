<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §04-56 — one proposed purchase, written down before anybody acts on it.
 *
 * The row is the answer to "why does the system think we need twelve of
 * these?": it keeps the window the demand was measured over, the average day,
 * the four balance figures, the trigger it fell through and the ladder that
 * produced the quantity. Accepting it does not buy anything — it drafts a
 * purchase order, which still has to be approved and sent like any other.
 */
class ReorderSuggestion extends Model
{
    use Auditable;

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_SUGGESTED => 'Waiting for a decision',
        self::STATUS_ACCEPTED => 'Drafted into a purchase order',
        self::STATUS_DISMISSED => 'Dismissed by a person',
        self::STATUS_SUPERSEDED => 'Superseded by a later look',
    ];

    /** Statuses that still expect somebody to do something. */
    public const OPEN_STATUSES = [self::STATUS_SUGGESTED];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'product_id', 'policy_id',
        'code', 'status', 'run_date', 'demand_window_days',
        'avg_daily_demand', 'on_hand', 'reserved', 'available', 'in_transit',
        'trigger_qty', 'shortage', 'suggested_qty', 'final_qty',
        'inputs', 'decision_note',
        'purchase_order_id', 'supplier_id', 'decided_by', 'decided_at', 'created_by',
    ];

    protected $casts = [
        'run_date' => 'date',
        'demand_window_days' => 'integer',
        'avg_daily_demand' => 'decimal:4',
        'on_hand' => 'decimal:4',
        'reserved' => 'decimal:4',
        'available' => 'decimal:4',
        'in_transit' => 'decimal:4',
        'trigger_qty' => 'decimal:4',
        'shortage' => 'decimal:4',
        'suggested_qty' => 'decimal:4',
        'final_qty' => 'decimal:4',
        'inputs' => 'array',
        'decided_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ReorderPolicy::class, 'policy_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Newest look first — the desk is read top-down. */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('run_date')->orderByDesc('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /** The quantity that counts: what a person settled on, else what was suggested. */
    public function effectiveQty(): float
    {
        return (float) ($this->final_qty ?? $this->suggested_qty);
    }

    /** An average day's demand, over the window this suggestion was measured in. */
    public function avgDaily(): float
    {
        return round((float) $this->avg_daily_demand, 4);
    }

    /**
     * How many days the stock on the shelf covers at the measured demand.
     * Null when nothing has moved — "cover" is not a number in that case, and
     * printing 0 or ∞ would invent one.
     */
    public function daysCover(): ?float
    {
        $avg = $this->avgDaily();

        if ($avg <= 0) {
            return null;
        }

        return round((float) $this->available / $avg, 1);
    }

    /** How much of the proposal has already been ordered by somebody else. */
    public function orderedQty(): float
    {
        return round((float) $this->in_transit, 4);
    }

    public function stateLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    /**
     * Tones the stylesheet actually has (§04-23 family): a proposal waiting for
     * somebody is a pending thing, a dismissal is a filed decision rather than a
     * failure, and only an accepted one is finished.
     */
    public function stateTone(): string
    {
        return match ($this->status) {
            self::STATUS_ACCEPTED => 'ok',
            self::STATUS_DISMISSED => 'archived',
            self::STATUS_SUPERSEDED => 'inactive',
            default => 'pending',
        };
    }
}
