<?php

namespace App\Http\Requests;

use App\Domain\Masters\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Supplier create/update (§06). Duplicate detection is deliberately NOT a
 * validation rule: it lives in SupplierService so the same guard applies to
 * imports and to any future API, not only to this form.
 */
class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && $this->input('code') !== null) {
            $this->merge(['code' => strtoupper(str_replace(' ', '-', trim((string) $this->input('code'))))]);
        }
    }

    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $companyId = $this->user()?->company_id;

        $code = Rule::unique('suppliers', 'code')->where('company_id', $companyId);

        if ($supplier instanceof Supplier) {
            $code->ignore($supplier->getKey());
        }

        return [
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', $code],
            'name' => ['required', 'string', 'max:191'],
            'contact_person' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_alt' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'category' => ['nullable', Rule::in(Supplier::CATEGORIES)],
            'bin' => ['nullable', 'string', 'max:32'],
            'tin' => ['nullable', 'string', 'max:32'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:96'],
            'bank_account_no' => ['nullable', 'string', 'max:48'],
            'mobile_wallet' => ['nullable', 'string', 'max:32'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'supplier code',
            'bin' => 'BIN',
            'tin' => 'TIN',
            'address_line1' => 'address',
        ];
    }
}
