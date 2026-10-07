<?php

namespace App\Http\Requests;

use App\Domain\Masters\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 05-02/05-05 — the same payload shape, with the row itself excluded. */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces customers.edit
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(\App\Domain\Foundation\Services\TenantContext::class)->companyId();
        $id = $this->route('customer')?->id;

        return [
            'code' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(Customer::TYPES)],
            'phone' => [
                'nullable', 'string', 'max:32',
                Rule::unique('customers', 'phone')->where('company_id', $companyId)->ignore($id),
            ],
            'alt_phone' => ['nullable', 'string', 'max:32'],
            'email' => [
                'nullable', 'email', 'max:191',
                Rule::unique('customers', 'email')->where('company_id', $companyId)->ignore($id),
            ],
            'bin' => ['nullable', 'string', 'max:32'],
            'tax_vat_no' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:128'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'customer_group_id' => ['nullable', 'integer', 'exists:customer_groups,id'],
            'segment' => ['nullable', Rule::in(Customer::SEGMENTS)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
