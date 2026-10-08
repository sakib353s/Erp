<?php

namespace App\Domain\Sales;

use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUSES = ['draft', 'pending', 'issued', 'paid', 'partial', 'void'];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'customer_id', 'sales_person_id',
        'sales_order_id', 'document_type_id', 'pos_session_id', 'invoice_no',
        'status', 'invoice_type', 'workflow_state', 'posting_state',
        'invoice_date', 'due_date', 'currency', 'subtotal', 'doc_discount',
        'coupon_code', 'coupon_discount',
        'taxable_base', 'tax', 'shipping', 'rounding', 'grand_total',
        'paid_amount', 'due_amount', 'tax_applicable', 'tax_code',
        'printed_title', 'qr_token_hash', 'journal_entry_id', 'notes',
        'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:4',
        'doc_discount' => 'decimal:4',
        'coupon_discount' => 'decimal:4',
        'taxable_base' => 'decimal:4',
        'tax' => 'decimal:4',
        'shipping' => 'decimal:4',
        'rounding' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'due_amount' => 'decimal:4',
        'tax_applicable' => 'boolean',
        // §16-19: the published link's own life cycle, read as instants rather
        // than as strings on the screens that show when it moved.
        'qr_token_issued_at' => 'datetime',
        'qr_token_revoked_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function scopeIssued($query)
    {
        return $query->whereIn('status', ['issued', 'partial', 'paid']);
    }
}
