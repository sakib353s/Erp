<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit note's own lines.
 *
 * What is being credited and against which line of the original invoice. The
 * link back to the invoice line is what lets a credit be matched to the sale it
 * answers rather than floating as an unexplained adjustment.
 *
 * It lives in its own file because a class that cannot be autoloaded by its own
 * name is a class that works only by luck: `CreditNoteLine::create()` used to reach this
 * through whichever request happened to load its parent first.
 */
class CreditNoteLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'credit_note_id', 'line_no', 'product_id',
        'invoice_line_id', 'description', 'qty', 'unit_price', 'tax', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
