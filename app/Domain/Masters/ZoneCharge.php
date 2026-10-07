<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weight-slab surcharge rows for a delivery zone. The zone's own
 * base_charge + per_kg_charge always apply; the first active slab whose
 * weight range covers the shipping weight adds a flat amount on top.
 */
class ZoneCharge extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'delivery_zone_id', 'code', 'description',
        'weight_from', 'weight_to', 'amount', 'is_active',
    ];

    protected $casts = [
        'weight_from' => 'decimal:3',
        'weight_to' => 'decimal:3',
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function coversWeight(float $weightKg): bool
    {
        if ($weightKg < (float) $this->weight_from) {
            return false;
        }

        return $this->weight_to === null || $weightKg <= (float) $this->weight_to;
    }
}
