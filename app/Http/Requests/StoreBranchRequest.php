<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Branch creation — code unique WITHIN THE COMPANY (Rule 1: many
 * branches, one company). is_default uniqueness is enforced in the
 * controller inside the same write.
 */
class StoreBranchRequest extends FormRequest
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
        $companyId = $this->user()?->company_id;

        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('branches', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'area' => ['nullable', 'string', 'max:128'],
            'district' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'operating_status' => ['nullable', 'string', 'max:32'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'branch code', 'name' => 'branch name'];
    }
}
