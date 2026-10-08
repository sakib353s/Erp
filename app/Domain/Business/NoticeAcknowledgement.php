<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-12 — one person having read one notice.
 *
 * A separate ledger rather than a flag on somebody's notification, because the
 * two questions are different: “has this person's inbox been seen” is delivery,
 * “has this person acknowledged the policy” is a record the office may have to
 * produce. One row per person per notice, written once.
 */
class NoticeAcknowledgement extends Model
{
    protected $fillable = ['company_id', 'notice_id', 'user_id', 'acknowledged_at', 'note'];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
