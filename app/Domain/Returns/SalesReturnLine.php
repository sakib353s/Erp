<?php

namespace App\Domain\Returns;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use App\Domain\Sales\InvoiceLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    use Auditable;

    protected $table = 'sales_return_lines';

    protected $fillable = [
        'company_id', 'sales_return_id', 'line_no', 'invoice_line_id',
        'product_id', 'description', 'qty', 'unit_price', 'tax', 'line_total',
        'disposition',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax' => 'decimal:4',
        'line_total' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
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
