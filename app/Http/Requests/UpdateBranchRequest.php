<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Branch update — code uniqueness ignoring the current row. */
class UpdateBranchRequest extends StoreBranchRequest
{
    public function rules(): array
    {
        $branch = $this->route('branch');
        $companyId = $this->user()?->company_id;

        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('branches', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($branch?->id),
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
}
