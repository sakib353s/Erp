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
            'designation_id' => ['nullable', 'integer', 'exists:designations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'joining_date' => ['nullable', 'date'],
            'employment_status' => ['nullable', Rule::in(\App\Domain\People\Employee::EMPLOYMENT_STATUSES)],
            'employment_type' => ['nullable', Rule::in(\App\Domain\People\Employee::EMPLOYMENT_TYPES)],
            'confirmation_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:16'],
            'blood_group' => ['nullable', 'string', 'max:8'],
            'national_id' => ['nullable', 'string', 'max:32'],
            'present_address' => ['nullable', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:128'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'bank_name' => ['nullable', 'string', 'max:96'],
            'bank_account_no' => ['nullable', 'string', 'max:48'],
            'mobile_wallet' => ['nullable', 'string', 'max:32'],
            'weekly_off' => ['nullable', Rule::in(['friday', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday'])],
            'annual_leave_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
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
