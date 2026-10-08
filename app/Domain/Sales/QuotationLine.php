<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quotation's own lines.
 *
 * The offer as it was put to the customer. A quotation is not a commitment on
 * either side, so these lines carry prices and nothing else — no reserved stock
 * and no ledger movement until the quotation is converted.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `QuotationLine::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class QuotationLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'quotation_id', 'line_no', 'product_id', 'description',
        'qty', 'unit_price', 'discount', 'tax', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
