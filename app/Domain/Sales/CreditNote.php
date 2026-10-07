<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Returns\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'issued', 'void'];

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'invoice_id', 'sales_return_id',
        'document_type_id', 'credit_note_no', 'status', 'posting_state',
        'note_date', 'subtotal', 'tax', 'grand_total', 'printed_title',
        'journal_entry_id', 'reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'note_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax' => 'decimal:4',
        'grand_total' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}

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
