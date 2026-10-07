<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'code', 'name', 'default_days', 'is_paid', 'is_active',
    ];

    protected $casts = [
        'default_days' => 'float',
        'is_paid' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(\App\Domain\Hr\Models\LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(\App\Domain\Hr\Models\LeaveBalance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
