<?php

namespace App\Domain\Returns;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use App\Domain\Masters\ReturnReason;
use App\Domain\Sales\CreditNote;
use App\Domain\Sales\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use Auditable;

    public const STATUSES = [
        'requested', 'approved', 'denied', 'received', 'inspected',
        'credited', 'refunded', 'cancelled',
    ];

    protected $table = 'sales_returns';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'customer_id', 'invoice_id',
        'return_reason_id', 'return_no', 'status', 'source', 'return_date',
        'subtotal', 'tax', 'grand_total', 'credit_note_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'return_date' => 'date',
        'subtotal' => 'decimal:4',
        'tax' => 'decimal:4',
        'grand_total' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReturnReason::class, 'return_reason_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id');
    }
}
