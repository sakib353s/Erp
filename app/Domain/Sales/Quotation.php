<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired', 'converted'];

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'sales_person_id', 'quote_no', 'revision',
        'revision_of', 'status', 'sent_at', 'sent_channel', 'sent_to', 'share_token', 'viewed_at', 'quote_date', 'valid_until', 'currency',
        'subtotal', 'discount', 'coupon_code', 'coupon_discount', 'tax', 'shipping', 'grand_total',
        'workflow_state', 'posting_state', 'notes', 'created_by',
    ];

    protected $casts = [
        'quote_date' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'discount' => 'decimal:4',
        'coupon_discount' => 'decimal:4',
        'tax' => 'decimal:4',
        'shipping' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'revision' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function scopeCompany($query)
    {
        return $query->where('company_id', auth()->user()?->company_id ?? $this->company_id);
    }
}
