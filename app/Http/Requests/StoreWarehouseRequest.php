<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Warehouse creation: code unique per BRANCH (schema), branch must be
 * inside the actor's own access (Rule 5 — enforced here, not in the
 * view).
 */
class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(str_replace(' ', '-', trim((string) $this->input('code'))))]);
        }
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                \Illuminate\Validation\Rule::unique('warehouses', 'code')
                    ->where('branch_id', $this->input('branch_id')),
            ],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $branchId = (int) $this->input('branch_id');

            if ($branchId > 0 && ! $this->user()?->hasBranchAccess($branchId)) {
                $validator->errors()->add('branch_id', 'That branch is outside your own access.');
            }
        });
    }

    public function attributes(): array
    {
        return ['code' => 'warehouse code', 'name' => 'warehouse name', 'branch_id' => 'branch'];
    }
}
