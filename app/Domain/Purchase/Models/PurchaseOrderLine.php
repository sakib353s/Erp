<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line. `line_total` is recomputable from the other four columns —
 * the recompute lives in PurchaseOrderService::recalculate() and nowhere else.
 */
class PurchaseOrderLine extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'description', 'qty_ordered', 'qty_received',
        'unit_price', 'discount', 'tax_rate', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:4',
        'qty_received' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function outstandingQty(): float
    {
        return round(max(0, (float) $this->qty_ordered - (float) $this->qty_received), 4);
    }

    public function isFullyReceived(): bool
    {
        return $this->outstandingQty() <= 0;
    }
}
