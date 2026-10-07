<?php

namespace App\Http\Requests;

use App\Domain\Masters\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 05-02 — customer creation payload.
 *
 * Uniqueness here is per company (the DB index is per company too); duplicate
 * phone is the practical failure Bangladesh retail hits most, so it gets a
 * dedicated message in the controller.
 */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces customers.create
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(\App\Domain\Foundation\Services\TenantContext::class)->companyId();

        return [
            'code' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(Customer::TYPES)],
            'phone' => [
                'nullable', 'string', 'max:32',
                Rule::unique('customers', 'phone')->where('company_id', $companyId),
            ],
            'alt_phone' => ['nullable', 'string', 'max:32'],
            'email' => [
                'nullable', 'email', 'max:191',
                Rule::unique('customers', 'email')->where('company_id', $companyId),
            ],
            'bin' => ['nullable', 'string', 'max:32'],
            'tax_vat_no' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:128'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'opening_balance_type' => ['nullable', Rule::in(['due', 'advance'])],
            'customer_group_id' => ['nullable', 'integer', 'exists:customer_groups,id'],
            'segment' => ['nullable', Rule::in(Customer::SEGMENTS)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'credit_limit' => $this->input('credit_limit', 0),
            'credit_days' => $this->input('credit_days', 0),
            'opening_balance' => $this->input('opening_balance', 0),
        ]);
    }
}
