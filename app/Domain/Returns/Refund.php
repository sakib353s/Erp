<?php

namespace App\Domain\Returns;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'invoice_id', 'sales_return_id',
        'refund_no', 'status', 'method', 'amount', 'refund_date',
        'reference', 'idempotency_key', 'journal_entry_id', 'narration', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'refund_date' => 'date',
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

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }
}
