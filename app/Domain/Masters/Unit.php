<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'symbol', 'base_unit',
        'conversion_factor', 'is_active',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Products pointing at this row — the reason a delete may have to wait. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'unit_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
