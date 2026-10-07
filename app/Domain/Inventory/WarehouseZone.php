<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A part of a warehouse with one job: receiving, storage, picking, dispatch… */
class WarehouseZone extends Model
{
    public const TYPES = [
        'receiving' => 'Receiving',
        'storage' => 'Storage',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'dispatch' => 'Dispatch',
        'returns' => 'Returns',
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'code', 'name', 'type',
        'is_active', 'sort_order', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bins(): HasMany
    {
        return $this->hasMany(WarehouseBin::class)->orderBy('code');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
