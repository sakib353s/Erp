<?php

namespace App\Domain\Security;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency key storage (Rule 12 / spec §9.3): same scope+key returns
 * the stored response instead of re-executing the operation.
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'scope', 'key', 'request_hash', 'user_id', 'status',
        'response_snapshot', 'expires_at',
    ];

    protected $casts = [
        'response_snapshot' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
