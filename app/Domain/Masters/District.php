<?php

namespace App\Domain\Masters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global Bangladesh district reference (no company_id — structural data).
 */
class District extends Model
{
    protected $fillable = [
        'code', 'name', 'name_bn', 'division', 'sort', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function upazilas(): HasMany
    {
        return $this->hasMany(Upazila::class);
    }

    public function deliveryZones(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryZone::class, 'delivery_zone_district')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
