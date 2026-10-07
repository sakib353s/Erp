<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product/qty row of a shipment — what physically leaves the warehouse on dispatch. */
class ShipmentLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'shipment_id', 'product_id', 'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
