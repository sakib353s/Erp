<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dashboard widget container registry — exactly 25 containers for the
 * dashboard (Rule: dashboard contains exactly 25 widget containers;
 * Branch Comparison + Recent Activity are ONE composite container).
 */
class Widget extends Model
{
    protected $fillable = [
        'code', 'module_id', 'label', 'label_key', 'container',
        'permission_id', 'feature_key', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
