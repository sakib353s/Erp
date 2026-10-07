<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Employee create/update (traceability 10-01…10-03). Employee type is
 * never a hardcoded role — role assignment happens separately on the
 * linked user. Branch must sit inside the actor's own access (Rule 5).
 */
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper(str_replace(' ', '-', trim((string) $this->input('code')))),
            ]);
        }
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $companyId = $this->user()?->company_id;

        $unique = Rule::unique('employees', 'code')->where('company_id', $companyId);

        if ($employee instanceof Model) {
            $unique->ignore($employee->getKey());
        }

        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', $unique],
            'first_name' => ['required', 'string', 'max:191'],
            'last_name' => ['nullable', 'string', 'max:191'],
            'full_name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'designation' => ['nullable', 'string', 'max:191'],
            'department' => ['nullable', 'string', 'max:191'],
            'joining_date' => ['nullable', 'date'],
            'employment_status' => ['nullable', Rule::in(['active', 'probation', 'inactive', 'exited'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_technician' => ['sometimes', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'manager_id' => ['nullable', 'integer', 'exists:employees,id'],
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

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'employee code',
            'first_name' => 'first name',
            'branch_id' => 'branch',
            'employment_status' => 'employment status',
        ];
    }
}
