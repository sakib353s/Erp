<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    protected $fillable = [
        'approval_request_id', 'level', 'status', 'approver_user_id', 'approver_role_id',
        'is_required', 'acted_by', 'acted_at', 'due_at', 'escalated_at', 'escalated_to_user_id',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_required' => 'boolean',
        'acted_at' => 'datetime',
        'due_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    public function actedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function escalatedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
