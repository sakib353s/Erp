<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §16-16/§16-17 — one promise, applied to one delivery.
 *
 * The dates are fixed when the goods reach the customer and never recomputed.
 * That is the whole difference between a warranty and a guess: `ends_on` is a
 * date the company can be held to, so it does not move when the product's
 * policy is later edited, and it does not move when the document rendering it
 * runs again.
 *
 * **State comes from the clock.** `status` records the life cycle the software
 * decided (active, claimed, voided, expired), but the question people actually
 * ask — *is this still covered today* — is answered by comparing `ends_on` with
 * now, so a warranty is expired the moment it is expired and not the morning
 * after the nightly job runs.
 */
class Warranty extends Model
{
    use Auditable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_CLAIMED, self::STATUS_EXPIRED, self::STATUS_VOIDED,
    ];

    /** How near the end of the cover is worth telling somebody about. */
    public const EXPIRING_DAYS = 30;

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'customer_id', 'product_id', 'sales_order_id',
        'invoice_id', 'challan_id', 'source', 'source_line_id', 'source_line_key',
        'serial_no', 'qty', 'months', 'starts_on', 'ends_on', 'status',
        'activated_at', 'activated_by', 'voided_at', 'void_reason', 'notes',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'activated_at' => 'datetime',
        'voided_at' => 'datetime',
        'qty' => 'decimal:4',
        'months' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'challan_id');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    /* ------------------------------------------------------------- the clock */

    /** Whole days left. Negative once the cover has run out. */
    public function daysRemaining(): int
    {
        if ($this->ends_on === null) {
            return 0;
        }

        return (int) Carbon::now()->startOfDay()->diffInDays($this->ends_on->copy()->startOfDay(), false);
    }

    /** The state as of this moment — never the stored flag alone. */
    public function stateNow(): string
    {
        if ($this->status === self::STATUS_VOIDED) {
            return self::STATUS_VOIDED;
        }

        if ($this->daysRemaining() < 0) {
            return self::STATUS_EXPIRED;
        }

        return $this->status === self::STATUS_CLAIMED ? self::STATUS_CLAIMED : self::STATUS_ACTIVE;
    }

    /** Live enough to take a new claim: in date and not voided. */
    public function isLive(): bool
    {
        return in_array($this->stateNow(), [self::STATUS_ACTIVE, self::STATUS_CLAIMED], true);
    }

    public function expiresSoon(): bool
    {
        $days = $this->daysRemaining();

        return $days >= 0 && $days <= self::EXPIRING_DAYS;
    }

    public function stateLabel(): string
    {
        return match ($this->stateNow()) {
            self::STATUS_ACTIVE => 'In cover',
            self::STATUS_CLAIMED => 'Claimed',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_VOIDED => 'Voided',
            default => (string) $this->status,
        };
    }

    /* ------------------------------------------------------------- scopes */

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_VOIDED)
            ->whereDate('ends_on', '>=', Carbon::now()->toDateString());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereDate('ends_on', '<', Carbon::now()->toDateString());
    }

    /** Expiring between now and N days from now — the call list for the desk. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->live()
            ->whereDate('ends_on', '>=', Carbon::now()->toDateString())
            ->whereDate('ends_on', '<=', Carbon::now()->addDays($days)->toDateString());
    }
}
