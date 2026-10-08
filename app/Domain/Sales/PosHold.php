<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parked counter basket.
 *
 * A customer goes back for something they forgot and the till cannot keep the
 * basket in the air; holding it writes the lines down exactly as they were, so
 * resuming brings back the same basket rather than a reconstruction of it.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `PosHold::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class PosHold extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'pos_session_id', 'customer_id', 'hold_no', 'lines',
        'total', 'status', 'held_at', 'resumed_at', 'created_by',
    ];

    protected $casts = [
        'lines' => 'array',
        'total' => 'decimal:4',
        'held_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }
}
