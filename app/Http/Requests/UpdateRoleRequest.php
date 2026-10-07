<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Role update — slug uniqueness ignoring the current row. */
class UpdateRoleRequest extends StoreRoleRequest
{
    public function rules(): array
    {
        $role = $this->route('role');
        $companyId = $this->user()?->company_id;

        return [
            'name' => ['required', 'string', 'max:64'],
            'slug' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('roles', 'slug')
                    ->where('company_id', $companyId)
                    ->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:191'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
