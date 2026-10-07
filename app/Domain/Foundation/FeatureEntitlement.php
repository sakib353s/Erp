<?php

namespace App\Domain\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureEntitlement extends Model
{
    protected $fillable = [
        'company_id', 'feature_key', 'is_enabled', 'limit_value', 'valid_until', 'source',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'limit_value' => 'integer',
        'valid_until' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currentlyEnabled(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->isFuture();
    }
}
