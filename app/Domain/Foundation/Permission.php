<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * System-global permission DEFINITION with a stable key (Rule 6).
 * Labels are translatable; nothing ever checks permissions in PHP code
 * against hard-coded strings — keys always resolve to these rows.
 */
class Permission extends Model
{
    protected $fillable = [
        'key', 'module', 'resource', 'action', 'label', 'description', 'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permission')
            ->withPivot('effect')
            ->withTimestamps();
    }
}
