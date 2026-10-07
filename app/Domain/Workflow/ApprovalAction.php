<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable per-request action history: submit / approve / reject /
 * return / comment / delegate / escalate — with actor, comment, level,
 * IP and user agent (Rule 16 approval auditability).
 */
class ApprovalAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'approval_request_id', 'approval_step_id', 'user_id', 'action', 'comment',
        'level', 'payload', 'ip', 'user_agent',
    ];

    protected $casts = [
        'level' => 'integer',
        'payload' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'approval_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
