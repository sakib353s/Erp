<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Inventory\Product;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $fillable = [
        'purchase_return_id', 'purchase_order_line_id', 'goods_receipt_line_id',
        'purchase_bill_line_id', 'product_id', 'warehouse_id', 'description', 'qty',
        'unit_cost', 'discount', 'tax_rate', 'line_total', 'batch_no', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'line_total' => 'decimal:4',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseBillLine::class, 'purchase_bill_line_id');
    }

    public function net(): float
    {
        return round((float) $this->qty * (float) $this->unit_cost, 4);
    }

    public function amount(): float
    {
        $net = $this->net();

        return round(max(0, $net - (float) $this->discount) * (1 + (float) $this->tax_rate / 100), 4);
    }
}
