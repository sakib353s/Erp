<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a product lives in the warehouse (§04-43). One product can sit in more
 * than one bin; exactly one of them is the primary pick face, and the service
 * keeps that promise rather than the client.
 */
class ProductBinAssignment extends Model
{
    protected $fillable = [
        'company_id', 'product_id', 'warehouse_bin_id', 'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
