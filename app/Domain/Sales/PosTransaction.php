<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sale at the counter, kept beside the session it happened in.
 *
 * Every tender, including the ones that came from a till that was offline: the
 * client UUID is what makes a replayed sync a no-op rather than a second sale,
 * and the sync state says whether this row has been reconciled with the server.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `PosTransaction::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class PosTransaction extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'pos_session_id', 'invoice_id', 'customer_id',
        'client_uuid', 'status', 'sync_state', 'total', 'payment_method',
        'tendered', 'change_due', 'sold_at', 'created_by',
    ];

    protected $casts = [
        'total' => 'decimal:4',
        'tendered' => 'decimal:4',
        'change_due' => 'decimal:4',
        'sold_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_state', 'synced');
    }
}
