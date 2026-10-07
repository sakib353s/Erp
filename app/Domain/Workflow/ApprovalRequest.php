<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Concerns\ScopedByBranch;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRequest extends Model
{
    use Auditable;
    use ScopedByBranch;

    protected $fillable = [
        'company_id', 'branch_id', 'workflow_definition_id', 'workflow_version',
        'entity_type', 'entity_id', 'action', 'reference_no', 'subject',
        'amount', 'currency', 'status', 'current_level', 'revision', 'return_count',
        'submitted_by', 'submitted_at', 'decided_at', 'due_at', 'snapshot', 'snapshot_hash',
    ];

    protected $casts = [
        'workflow_version' => 'integer',
        'amount' => 'decimal:4',
        'current_level' => 'integer',
        'revision' => 'integer',
        'return_count' => 'integer',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'due_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('level');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class)->orderBy('created_at')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['approved', 'rejected', 'cancelled'], true);
    }

    /**
     * Approval mode FROZEN into the snapshot at submission (versioning):
     * a definition edited mid-flight never changes how this request
     * advances — old requests keep the routing they were submitted with.
     */
    public function frozenMode(): string
    {
        return (string) ($this->snapshot['_workflow']['approval_mode']
            ?? $this->definition?->approval_mode
            ?? 'sequential');
    }

    public function isParallelMode(): bool
    {
        return $this->frozenMode() === 'parallel';
    }

    public function frozenBlocksSelfApproval(): bool
    {
        $frozen = $this->snapshot['_workflow']['block_self_approval'] ?? null;

        if (is_bool($frozen)) {
            return $frozen;
        }

        return $this->definition?->block_self_approval
            ?? (bool) config('erp.workflow.block_self_approval_default', true);
    }
}
