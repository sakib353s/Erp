<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'provider',
        'requires_reference', 'is_default', 'is_active', 'sort',
    ];

    protected $casts = [
        'requires_reference' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
