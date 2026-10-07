<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bill line. Like every other document line in this ERP, the money is
 * recomputable from the row itself (qty × unit_cost − discount + tax) and the
 * recompute lives in PurchaseBillService::recalculate() only.
 */
class PurchaseBillLine extends Model
{
    protected $fillable = [
        'purchase_bill_id', 'purchase_order_line_id', 'goods_receipt_line_id', 'product_id',
        'description', 'qty', 'unit_cost', 'discount', 'tax_rate', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Net of discount, before tax — the figure that lands in inventory. */
    public function net(): float
    {
        $gross = round((float) $this->qty * (float) $this->unit_cost, 4);

        return round($gross - min(round((float) $this->discount, 4), $gross), 4);
    }

    public function tax(): float
    {
        return round($this->net() * ((float) $this->tax_rate / 100), 4);
    }
}
