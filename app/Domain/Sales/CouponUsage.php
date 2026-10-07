<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponUsage extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'coupon_id', 'source_type', 'source_id',
        'customer_id', 'discount_amount', 'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:4',
        'used_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
