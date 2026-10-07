<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Active stock hold for a sales order / POS hold / layaway.
 * Mirrors stock_balances.reserved; released or consumed on lifecycle events.
 */
class StockReservation extends Model
{
    use Auditable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    /** What kind of document is holding the stock. */
    public const SOURCE_LABELS = [
        'sales_order' => 'Sales order',
        'pos_hold' => 'POS hold',
        'layaway' => 'Layaway',
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id', 'source_type',
        'source_id', 'qty', 'status', 'expires_at',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'expires_at' => 'timestamp',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Holds that are still holding stock — active and not past their deadline. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Holds whose deadline has passed: stock still held for an order that stopped
     * moving. These are the ones the expiry sweep returns to availability.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function sourceKind(): string
    {
        return self::SOURCE_LABELS[$this->source_type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->source_type));
    }
}
