<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's pinned navigation destination (§18.3 "favorites").
 *
 * Persisted server-side rather than in localStorage so a user's pinned set
 * follows them across devices and survives a browser wipe. Pins are always
 * re-checked against the live permission/entitlement filter when rendered, so
 * a revoked permission can never leave a stale link behind.
 */
class MenuItemFavorite extends Model
{
    protected $fillable = ['user_id', 'menu_item_id', 'sort'];

    protected $casts = ['sort' => 'integer'];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
