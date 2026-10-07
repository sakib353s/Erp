<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use Auditable;

    public const TYPES = ['percent_off', 'fixed_off', 'free_shipping', 'buy_x_get_y'];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'type', 'value', 'buy_qty', 'get_qty',
        'min_subtotal', 'max_uses', 'used_count',
        'starts_at', 'ends_at', 'is_active', 'description', 'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'buy_qty' => 'integer',
        'get_qty' => 'integer',
        'min_subtotal' => 'decimal:4',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function scopeCompany($query)
    {
        return $query->where('company_id', auth()->user()?->company_id ?? $this->company_id);
    }
}
