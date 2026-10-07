<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction of a batch's expiry date (§04-38).
 *
 * A wrong date is a normal thing to discover, and correcting it is legitimate —
 * but the change is an event, not an edit: what it was, what it became, who did
 * it and why. Without this trail, "the date says next year" and "the date was
 * quietly moved to next year" look identical.
 */
class StockBatchExpiryChange extends Model
{
    protected $fillable = [
        'stock_batch_id', 'expires_on_before', 'expires_on_after', 'reason', 'changed_by',
    ];

    protected $casts = [
        'expires_on_before' => 'date',
        'expires_on_after' => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
