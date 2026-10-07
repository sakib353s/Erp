<?php

namespace App\Domain\Customers\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Referral trail: who introduced whom, and what came of it (05-20). */
class CustomerReferral extends Model
{
    use Auditable;

    public const STATUSES = ['new', 'contacted', 'converted', 'declined'];

    protected $fillable = [
        'company_id', 'customer_id', 'referred_name', 'referred_phone',
        'referred_customer_id', 'status', 'reward_amount', 'notes',
    ];

    protected $casts = ['reward_amount' => 'decimal:2'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function referredCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_customer_id');
    }
}
