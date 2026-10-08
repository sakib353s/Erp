<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order's own lines.
 *
 * One row per product on a sales order, with the three quantity columns that
 * make an order a commitment rather than a wish: what was ordered, what has
 * been delivered and what has been invoiced, so a partially shipped order is
 * still the same document.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `SalesOrderLine::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class SalesOrderLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'sales_order_id', 'line_no', 'product_id', 'description',
        'qty', 'delivered_qty', 'invoiced_qty', 'unit_price', 'discount',
        'tax', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'delivered_qty' => 'decimal:4',
        'invoiced_qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
