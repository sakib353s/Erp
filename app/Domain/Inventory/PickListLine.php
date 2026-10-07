<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One walk on a pick list: this product, from this bin, this much of it. */
class PickListLine extends Model
{
    protected $fillable = [
        'pick_list_id', 'product_id', 'warehouse_bin_id', 'stock_batch_id',
        'quantity', 'picked_quantity', 'note', 'line_no',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'picked_quantity' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(PickList::class, 'pick_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function hasBin(): bool
    {
        return $this->warehouse_bin_id !== null;
    }

    public function isPicked(): bool
    {
        return (float) $this->picked_quantity >= (float) $this->quantity - 0.00005;
    }

    public function isUnpicked(): bool
    {
        return (float) $this->picked_quantity <= 0.00005;
    }

    public function shortfall(): float
    {
        return round(max(0, (float) $this->quantity - (float) $this->picked_quantity), 4);
    }
}
