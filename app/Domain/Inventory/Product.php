<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Brand;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Stock-managed product. SKU is unique per company. Cost method drives
 * valuation layers (FIFO/LIFO/WAC/Standard); changing method never
 * rewrites posted layers.
 */
class Product extends Model
{
    use Auditable;

    public const COST_METHODS = ['fifo', 'lifo', 'wac', 'standard'];

    protected $fillable = [
        'company_id', 'product_category_id', 'brand_id', 'unit_id',
        'code', 'sku', 'name', 'barcode', 'description',
        'cost_method', 'standard_cost', 'is_stocked',
        'track_batch', 'track_serial', 'is_active',
    ];

    protected $casts = [
        'standard_cost' => 'decimal:4',
        'is_stocked' => 'boolean',
        'track_batch' => 'boolean',
        'track_serial' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(StockLayer::class);
    }

    public function reorderPolicy(): HasOne
    {
        return $this->hasOne(ReorderPolicy::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('is_stocked', true);
    }
}
