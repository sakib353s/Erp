<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'valid_from', 'valid_to',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query, ?string $at = null)
    {
        $at = $at ?? now()->toDateString();

        return $query
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at);
            });
    }
}
