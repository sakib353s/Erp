<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'parent_id', 'code', 'name', 'description',
        'is_global', 'is_active',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Products pointing at this row — the reason a delete may have to wait. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
