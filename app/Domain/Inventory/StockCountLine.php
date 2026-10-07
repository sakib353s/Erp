<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product on a count sheet.
 *
 * `system_qty` is the snapshot, `counted_qty` is what the counter found
 * (null = they have not reached this line yet), and `posted_delta` is what the
 * ledger actually applied when the sheet was posted — the difference between
 * the two deltas is warehouse movement during the count, kept rather than
 * smoothed away.
 */
class StockCountLine extends Model
{
    protected $fillable = [
        'stock_count_id', 'product_id', 'line_no', 'system_qty', 'counted_qty',
        'variance', 'posted_delta', 'unit_cost', 'value', 'narration',
    ];

    protected $casts = [
        'system_qty' => 'decimal:4',
        'counted_qty' => 'decimal:4',
        'variance' => 'decimal:4',
        'posted_delta' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'value' => 'decimal:4',
    ];

    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_qty !== null;
    }

    public function hasVariance(): bool
    {
        return abs((float) $this->variance) > 1e-9;
    }
}
