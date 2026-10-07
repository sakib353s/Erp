<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_id', 'qty_sent',
        'qty_received', 'unit_cost', 'line_no',
    ];

    protected $casts = [
        'qty_sent' => 'decimal:4',
        'qty_received' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
