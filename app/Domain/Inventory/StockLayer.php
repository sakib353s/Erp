<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valuation layer (FIFO/LIFO/WAC). qty_remaining shrinks on consumption;
 * posted layers are never rewritten when product cost method changes.
 */
class StockLayer extends Model
{
    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id',
        'qty_initial', 'qty_remaining', 'unit_cost',
        'received_at', 'source_type', 'source_id',
    ];

    protected $casts = [
        'qty_initial' => 'decimal:4',
        'qty_remaining' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isExhausted(): bool
    {
        return (float) $this->qty_remaining <= 0;
    }
}
