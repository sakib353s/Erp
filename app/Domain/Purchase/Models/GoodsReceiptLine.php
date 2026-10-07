<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'purchase_order_line_id', 'product_id', 'qty_received',
        'unit_cost', 'line_total', 'batch_no', 'manufactured_on', 'expires_on', 'remarks', 'sort_order',
    ];

    protected $casts = [
        'qty_received' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'line_total' => 'decimal:4',
        'manufactured_on' => 'date',
        'expires_on' => 'date',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What was received, in words. A receipt line stores no description of its
     * own, so it borrows the product's name, the order line's wording or the
     * receiver's remark — in that order — instead of printing an empty cell.
     */
    public function label(): string
    {
        return $this->product?->name
            ?? $this->orderLine?->description
            ?? ($this->remarks !== null && trim((string) $this->remarks) !== '' ? $this->remarks : 'Received goods');
    }
}
