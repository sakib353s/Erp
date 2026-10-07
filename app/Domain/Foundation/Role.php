<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'name', 'slug', 'description', 'is_system',
        'branch_scope', 'warehouse_scope', 'portal_scope',
        'can_approve', 'approval_level', 'data_visibility',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'can_approve' => 'boolean',
        'approval_level' => 'integer',
        'data_visibility' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')->withTimestamps();
    }

    public function scopeApprovalRoles($query)
    {
        return $query->where('can_approve', true);
    }
}
