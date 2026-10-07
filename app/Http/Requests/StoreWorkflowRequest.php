<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Generic workflow definition payload (correction G) — the SAME rules
 * serve sales, purchase, inventory, accounting, HR and any future
 * approval: event reference, approval mode, at least one approver level
 * (role- or user-targeted), and condition rows using ONLY operators the
 * WorkflowConditionEvaluator actually implements.
 */
class StoreWorkflowRequest extends FormRequest
{
    /** Mirrors WorkflowConditionEvaluator's supported operators. */
    public const OPERATORS = ['gte', 'lte', 'between', 'eq', 'neq', 'in', 'not_in'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
            'entity_type' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'action' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/'],
            'approval_mode' => ['required', 'in:sequential,parallel'],
            'priority' => ['nullable', 'integer', 'between:-100,100'],
            'due_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'escalation_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'escalation_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'block_self_approval' => ['sometimes', 'boolean'],

            'approvers' => ['required', 'array', 'min:1'],
            'approvers.*.level' => ['required', 'integer', 'min:1', 'max:20'],
            'approvers.*.approver_type' => ['required', 'in:role,user'],
            'approvers.*.role_id' => ['nullable', 'required_if:approvers.*.approver_type,role', 'integer', 'exists:roles,id'],
            'approvers.*.user_id' => ['nullable', 'required_if:approvers.*.approver_type,user', 'integer', 'exists:users,id'],
            'approvers.*.is_required' => ['sometimes', 'boolean'],

            'conditions' => ['nullable', 'array'],
            'conditions.*.subject' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'conditions.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'conditions.*.value_string' => ['nullable', 'string', 'max:191'],
            'conditions.*.value_min' => ['nullable', 'numeric'],
            'conditions.*.value_max' => ['nullable', 'numeric'],
            'conditions.*.condition_group' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'workflow name',
            'entity_type' => 'entity type',
            'action' => 'action',
            'approval_mode' => 'approval mode',
            'approvers' => 'approver levels',
            'conditions' => 'conditions',
        ];
    }
}
