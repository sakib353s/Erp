<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bin — the place in a zone a picker is sent to.
 *
 * A bin records *where*, not *how much*: quantities stay in the ledger per
 * product per warehouse, and a second place for numbers would be a second truth.
 */
class WarehouseBin extends Model
{
    protected $fillable = [
        'company_id', 'warehouse_id', 'warehouse_zone_id', 'code', 'name',
        'is_pickable', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_pickable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductBinAssignment::class);
    }

    /** "A-01 · Rack A, shelf 1" — the label a picker reads. */
    public function label(): string
    {
        return $this->name ? $this->code.' · '.$this->name : $this->code;
    }

    public function scopePickable($query)
    {
        return $query->where('is_pickable', true)->where('is_active', true);
    }
}
