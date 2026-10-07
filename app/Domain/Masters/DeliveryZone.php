<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryZone extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'description',
        'base_charge', 'per_kg_charge', 'is_active',
    ];

    protected $casts = [
        'base_charge' => 'decimal:2',
        'per_kg_charge' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function districts(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'delivery_zone_district')
            ->withTimestamps();
    }

    /** @return HasMany<ZoneCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(ZoneCharge::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
