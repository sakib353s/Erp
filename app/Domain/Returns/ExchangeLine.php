<?php

namespace App\Domain\Returns;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use App\Domain\Sales\InvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeLine extends Model
{
    use Auditable;

    protected $table = 'exchange_lines';

    protected $fillable = [
        'company_id', 'exchange_id', 'direction', 'line_no', 'invoice_line_id',
        'product_id', 'description', 'qty', 'unit_price', 'tax', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
