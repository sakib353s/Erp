<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived stock cache. Rebuildable from stock_movements — never the
 * source of truth. available = on_hand − reserved.
 */
class StockBalance extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'product_id',
        'on_hand', 'reserved', 'in_transit', 'damaged', 'quarantined',
        'computed_at',
    ];

    protected $casts = [
        'on_hand' => 'decimal:4',
        'reserved' => 'decimal:4',
        'in_transit' => 'decimal:4',
        'damaged' => 'decimal:4',
        'quarantined' => 'decimal:4',
        'computed_at' => 'datetime',
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

    public function available(): float
    {
        return (float) $this->on_hand - (float) $this->reserved;
    }
}
