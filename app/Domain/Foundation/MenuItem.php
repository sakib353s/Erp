<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the supplied navigation catalog (§47). `status='planned'`
 * entries are catalogued for traceability but never rendered (C1) —
 * `php artisan menu:sync` flips them to 'active' once a real route exists.
 */
class MenuItem extends Model
{
    use Auditable;

    protected $fillable = [
        'module_id', 'parent_id', 'code', 'label', 'label_key', 'route', 'icon',
        'permission_id', 'location', 'status', 'entity_type', 'action',
        'feature_key', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('sort')->orderBy('label');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function portals(): BelongsToMany
    {
        return $this->belongsToMany(Portal::class, 'menu_item_portal')->withTimestamps();
    }

    public function isActiveEntry(): bool
    {
        return $this->is_active && $this->status === 'active';
    }
}
