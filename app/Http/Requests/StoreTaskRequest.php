<?php

namespace App\Http\Requests;

use App\Domain\Business\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-13 — writing a task.
 *
 * One request serves create and update because they accept the same fields, and
 * the two doors into the same row should not have different rules. `assigned_to`
 * has to be an active member of *this* company — a task assigned to a person in
 * another company is a task nobody will ever see.
 */
class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tasks.manage') ?? false;
    }

    public function rules(): array
    {
        $companyId = (int) $this->user()->company_id;

        return [
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('company_id', $companyId)],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('company_id', $companyId)->where('status', 'active')],
            'due_at' => ['nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Empty selects arrive as "" — that means “nobody” and “no project”, not 0.
        $this->merge([
            'project_id' => $this->input('project_id') === '' ? null : $this->input('project_id'),
            'assigned_to' => $this->input('assigned_to') === '' ? null : $this->input('assigned_to'),
            'due_at' => $this->input('due_at') === '' ? null : $this->input('due_at'),
        ]);
    }

    public function attributes(): array
    {
        return [
            'title' => 'task title',
            'assigned_to' => 'assignee',
            'project_id' => 'project',
            'due_at' => 'due date',
        ];
    }
}
