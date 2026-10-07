<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
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

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
