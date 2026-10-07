<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\WorkflowCondition;
use App\Domain\Workflow\WorkflowDefinition;

/**
 * Evaluates DB-defined conditions for workflow definition resolution
 * (amount thresholds, branch rules, roles — correction G: nothing of
 * this kind may live in controller code).
 *
 * Groups are OR-ed; conditions within a group are AND-ed.
 */
class WorkflowConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $context  e.g. amount, branch_id, currency, role_keys, entity_type
     */
    public function matches(WorkflowDefinition $definition, array $context): bool
    {
        $conditions = $definition->relationLoaded('conditions')
            ? $definition->conditions
            : $definition->conditions()->orderBy('condition_group')->orderBy('position')->get();

        if ($conditions->isEmpty()) {
            return true; // unconditional definition
        }

        $groups = $conditions->groupBy('condition_group');

        foreach ($groups as $group) {
            $all = true;

            /** @var WorkflowCondition $condition */
            foreach ($group as $condition) {
                if (! $this->evaluate($condition, $context)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    protected function evaluate(WorkflowCondition $condition, array $context): bool
    {
        $actual = $context[$condition->subject] ?? null;

        switch ($condition->operator) {
            case 'gte':
                return $actual !== null && (float) $actual >= (float) $condition->value_min;

            case 'lte':
                return $actual !== null && (float) $actual <= (float) $condition->value_min
                    || $actual !== null && $condition->value_max === null && (float) $actual <= (float) $condition->value_min;

            case 'between':
                return $actual !== null
                    && (float) $actual >= (float) $condition->value_min
                    && (float) $actual <= (float) $condition->value_max;

            case 'eq':
                return $this->stringly($actual) === $this->stringly($condition->value_string)
                    || ($condition->value_min !== null && $actual !== null && (float) $actual === (float) $condition->value_min);

            case 'neq':
                return $this->stringly($actual) !== $this->stringly($condition->value_string);

            case 'in':
                return in_array($this->stringly($actual), array_map(fn ($v) => $this->stringly($v), $condition->value_list ?? []), true);

            case 'not_in':
                return ! in_array($this->stringly($actual), array_map(fn ($v) => $this->stringly($v), $condition->value_list ?? []), true);

            default:
                return false;
        }
    }

    protected function stringly(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value === null ? '' : (string) $value;
    }
}
