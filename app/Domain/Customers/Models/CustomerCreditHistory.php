<?php

namespace App\Domain\Customers\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every credit-limit / credit-days decision (05-16).
 * The current limit lives on `customers`; how it got there lives here.
 */
class CustomerCreditHistory extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'customer_id', 'old_limit', 'new_limit',
        'old_credit_days', 'new_credit_days', 'reason', 'changed_by',
    ];

    protected $casts = [
        'old_limit' => 'decimal:2',
        'new_limit' => 'decimal:2',
        'old_credit_days' => 'integer',
        'new_credit_days' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
