<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move on a putaway list: this product, into this bin, this much of it. */
class PutawayListLine extends Model
{
    protected $fillable = [
        'putaway_list_id', 'product_id', 'warehouse_bin_id', 'placed_bin_id', 'stock_batch_id',
        'quantity', 'placed_quantity', 'note', 'line_no',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'placed_quantity' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(PutawayList::class, 'putaway_list_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Where the goods were meant to go. */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }

    /** Where they actually went — the plan and the fact are allowed to differ. */
    public function placedBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'placed_bin_id');
    }

    /** The bin to show: the fact once there is one, the plan until then. */
    public function effectiveBin(): ?WarehouseBin
    {
        return $this->placedBin ?? $this->bin;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function hasBin(): bool
    {
        return $this->warehouse_bin_id !== null;
    }

    public function isPlaced(): bool
    {
        return (float) $this->placed_quantity >= (float) $this->quantity - 0.00005;
    }

    public function shortfall(): float
    {
        return round(max(0, (float) $this->quantity - (float) $this->placed_quantity), 4);
    }
}
