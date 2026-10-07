<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use App\Domain\Workflow\ApprovalDelegation;
use App\Domain\Workflow\ApprovalStep;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * ERP user — always belongs to the single company (Rule 4), carries a
 * server-side branch/warehouse scope and database-driven permissions.
 */
class User extends Authenticatable
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'email', 'phone', 'password',
        'is_super_admin', 'status', 'branch_scope', 'warehouse_scope', 'default_branch_id',
        'must_change_password', 'password_changed_at', 'password_history',
        'last_login_at', 'failed_login_count', 'locked_until', 'locale',
        'avatar_path', 'suspended_at', 'suspension_reason', 'notify_email', 'notify_inapp',
    ];

    protected $hidden = [
        'password', 'remember_token', 'password_history',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'password_history' => 'array',
            'last_login_at' => 'datetime',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'suspended_at' => 'datetime',
            'notify_email' => 'boolean',
            'notify_inapp' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Relations */
    /* ------------------------------------------------------------------ */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function branchAssignments(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch')->withTimestamps();
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user')->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission')
            ->withPivot('effect')
            ->withTimestamps();
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'approver_user_id');
    }

    public function delegationsReceived(): HasMany
    {
        return $this->hasMany(ApprovalDelegation::class, 'delegate_user_id');
    }

    /* ------------------------------------------------------------------ */
    /* Scope helpers (all server-side; UI filtering is UX only) */
    /* ------------------------------------------------------------------ */

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->suspended_at === null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked'
            || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    /** Branch ids this user may access, or null for "all branches". */
    public function accessibleBranchIds(): ?array
    {
        if ($this->branch_scope === 'all' || $this->isSuperAdmin()) {
            return null; // unrestricted within the company
        }

        return $this->branchAssignments()->pluck('branches.id')->all();
    }

    public function hasBranchAccess(int $branchId): bool
    {
        $ids = $this->accessibleBranchIds();

        return $ids === null || in_array($branchId, $ids, true);
    }
}
