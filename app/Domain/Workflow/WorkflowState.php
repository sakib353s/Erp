<?php

namespace App\Domain\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowState extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'code', 'label', 'kind', 'is_initial', 'is_terminal', 'position',
    ];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_terminal' => 'boolean',
        'position' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'from_state_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'to_state_id');
    }
}
