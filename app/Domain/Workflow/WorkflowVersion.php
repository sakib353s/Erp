<?php

namespace App\Domain\Workflow;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workflow_definition_id', 'version', 'snapshot', 'snapshot_hash',
        'activated_at', 'created_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
        'activated_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
