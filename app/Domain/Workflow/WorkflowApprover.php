<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowApprover extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'level', 'approver_type', 'role_id', 'user_id',
        'branch_rule', 'is_required', 'position',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_required' => 'boolean',
        'position' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
