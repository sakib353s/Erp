<?php

namespace App\Domain\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Condition row evaluated by WorkflowConditionEvaluator when resolving
 * which definition applies (amount thresholds, branch rules, roles…).
 * Groups are OR-ed; conditions inside a group are AND-ed.
 */
class WorkflowCondition extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'subject', 'operator', 'value_string',
        'value_min', 'value_max', 'value_list', 'condition_group', 'position',
    ];

    protected $casts = [
        'value_min' => 'decimal:4',
        'value_max' => 'decimal:4',
        'value_list' => 'array',
        'condition_group' => 'integer',
        'position' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
