<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product line of a write-off, valued at the cost the ledger consumed. */
class StockWriteoffLine extends Model
{
    protected $fillable = [
        'stock_writeoff_id', 'product_id', 'qty', 'unit_cost', 'value',
        'narration', 'line_no',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'value' => 'decimal:4',
    ];

    public function writeoff(): BelongsTo
    {
        return $this->belongsTo(StockWriteoff::class, 'stock_writeoff_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
