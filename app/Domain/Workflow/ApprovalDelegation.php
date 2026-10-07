<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalDelegation extends Model
{
    protected $fillable = [
        'company_id', 'delegator_user_id', 'delegate_user_id', 'entity_type',
        'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    public function covers(?string $entityType, ?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && $this->starts_at->lte($at)
            && $this->ends_at->gte($at)
            && ($this->entity_type === null || $this->entity_type === $entityType);
    }
}
