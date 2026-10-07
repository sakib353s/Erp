<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice/bill settlement truth (§7.4). payment_id references the
 * payments table (arrives with Cash/Sales phases); the column is an
 * unsigned bigint so accounting can track allocations before that
 * migration lands.
 */
class PaymentAllocation extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'payment_id', 'allocatable_type', 'allocatable_id',
        'amount', 'branch_id', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function allocatable()
    {
        return $this->morphTo();
    }
}
