<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Packaging type (02-98): a company-defined packaging item backed by a
 * stock-managed product — consumption posts PACK_CONSUME movements
 * against that product's layers, so the type never carries its own
 * price (the ledger is the valuation truth).
 */
class PackagingType extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'product_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(PackagingUsage::class);
    }
}
