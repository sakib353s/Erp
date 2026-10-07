<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Role creation: company-scoped slug, permission ids must exist. */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $slug = strtolower(trim((string) $this->input('slug')));
            $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
            $this->merge(['slug' => trim((string) $slug, '-')]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'name' => ['required', 'string', 'max:64'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('roles', 'slug')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:191'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'role name', 'slug' => 'role code'];
    }
}
