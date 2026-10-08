<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery challan's own lines.
 *
 * What left the warehouse on this challan. The challan follows the truck
 * rather than the ledger, which is why its lines carry quantities and no prices
 * at all — a document that showed a value could be mistaken for an invoice.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `DeliveryChallanLine::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class DeliveryChallanLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'delivery_challan_id', 'line_no', 'product_id',
        'description', 'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
