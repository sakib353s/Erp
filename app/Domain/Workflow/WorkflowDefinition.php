<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Generic workflow DEFINITION (Rule 15). Reusable by Sales, Purchase,
 * Inventory, Accounting, HR, Returns, Payments… — business modules only
 * reference entity_type/action; thresholds and routing live in the
 * conditions/approvers tables, never in controller code (correction G).
 */
class WorkflowDefinition extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'entity_type', 'action', 'name', 'description',
        'is_active', 'priority', 'current_version', 'approval_mode',
        'block_self_approval', 'due_hours', 'escalation_hours', 'escalation_role_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'current_version' => 'integer',
        'block_self_approval' => 'boolean',
        'due_hours' => 'integer',
        'escalation_hours' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(WorkflowState::class)->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class)->orderBy('position');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(WorkflowCondition::class)->orderBy('condition_group')->orderBy('position');
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(WorkflowApprover::class)->orderBy('level')->orderBy('position');
    }

    /**
     * Approval requests raised against this definition — the inverse of
     * ApprovalRequest::definition(). The admin list counts these, so the
     * relation must exist for withCount('requests') to resolve.
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'workflow_definition_id');
    }

    public function escalationRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'escalation_role_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parallelMode(): bool
    {
        return $this->approval_mode === 'parallel';
    }
}
