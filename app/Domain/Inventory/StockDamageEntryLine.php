<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product line of a damage/loss entry, with its value at record time. */
class StockDamageEntryLine extends Model
{
    protected $fillable = [
        'stock_damage_entry_id', 'product_id', 'qty', 'unit_cost', 'value',
        'narration', 'line_no',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'value' => 'decimal:4',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(StockDamageEntry::class, 'stock_damage_entry_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
