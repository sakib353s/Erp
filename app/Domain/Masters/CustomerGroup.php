<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer pricing group (02-111): customers belong to at most one
 * group per company; customer_group pricing rules target a group.
 */
class CustomerGroup extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_group_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PricingRule::class, 'customer_group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
