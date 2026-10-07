<?php

namespace App\Domain\Notification;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** In-app notification centre row (Rule J). */
class Notification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'user_id', 'event_type', 'channel', 'title', 'body',
        'priority', 'action_url', 'data', 'persistent', 'dedupe_key', 'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'persistent' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
