<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Source-document event → account-role assignment (§7.2).
 * Controllers never contain account codes; the posting service resolves
 * which Account a role maps to from these effective-dated rows.
 */
class PostingRule extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'event_type', 'doc_type', 'branch_id', 'role',
        'account_id', 'side', 'position', 'effective_from', 'effective_to',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
        'position' => 'integer',
        'branch_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function isEffectiveOn(Carbon $date): bool
    {
        if ($this->effective_from !== null && $date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $date->gt($this->effective_to)) {
            return false;
        }

        return true;
    }
}
