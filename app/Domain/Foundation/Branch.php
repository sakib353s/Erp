<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Concerns\ScopedByBranch;
use App\Domain\People\Employee;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use Auditable;
    use ScopedByBranch;

    protected $fillable = [
        'company_id', 'code', 'name', 'phone', 'email',
        'address_line1', 'address_line2', 'area', 'district', 'postal_code',
        'operating_status', 'is_default', 'is_active',
        'manager_id', 'opened_on', 'closed_on', 'notes',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'opened_on' => 'date',
        'closed_on' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch')->withTimestamps();
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
