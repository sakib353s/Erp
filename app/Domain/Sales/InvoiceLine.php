<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice line.
 *
 * Extracted from Invoice.php: when this class shared a file with Invoice,
 * PSR-4 could not resolve App\Domain\Sales\InvoiceLine, so any request that
 * referenced a line before Invoice had been autoloaded (for example the
 * sales breakdown reports) failed with "Class not found". One class per
 * file keeps resolution deterministic regardless of load order.
 */
class InvoiceLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'invoice_id', 'line_no', 'product_id',
        'sales_order_line_id', 'description', 'qty', 'unit_price',
        'discount', 'tax', 'line_total', 'warranty_flag',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'warranty_flag' => 'boolean',
        'line_no' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
