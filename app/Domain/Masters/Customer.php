<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'phone', 'email', 'bin',
        'district_id', 'address_line1', 'credit_limit', 'is_active',
        'customer_group_id',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
