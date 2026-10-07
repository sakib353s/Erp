<?php

namespace App\Domain\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'from_state_id', 'to_state_id', 'action_code', 'label', 'position',
    ];

    protected $casts = ['position' => 'integer'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function fromState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'from_state_id');
    }

    public function toState(): BelongsTo
    {
        return $this->belongsTo(WorkflowState::class, 'to_state_id');
    }
}
