<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use Auditable;

    public const TYPES = ['percent_off', 'fixed_off'];

    public const KINDS = ['standard', 'seasonal', 'flash'];

    protected $fillable = [
        'company_id', 'branch_id', 'name', 'code', 'type', 'kind', 'value',
        'min_subtotal', 'priority', 'starts_at', 'ends_at', 'is_active',
        'description', 'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'min_subtotal' => 'decimal:4',
        'priority' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function scopeCompany($query)
    {
        return $query->where('company_id', auth()->user()?->company_id ?? $this->company_id);
    }

    public function scopeActiveAt($query, string $at): mixed
    {
        return $query
            ->where('is_active', true)
            ->where(function ($q) use ($at) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }
}
