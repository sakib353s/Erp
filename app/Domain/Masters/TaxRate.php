<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'tax_type', 'rate',
        'effective_from', 'effective_to', 'applicability', 'document_applicability',
        'gl_account_id', 'source_note', 'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'applicability' => 'array',
        'document_applicability' => 'array',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query, ?string $at = null)
    {
        $at = $at ?? now();

        return $query
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            });
    }
}
