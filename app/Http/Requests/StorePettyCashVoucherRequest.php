<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A voucher out of the float (§08-21).
 *
 * Whether this becomes a payment or a request depends on the company's limit and
 * is decided by the service, not by the form: the person filling it in is asking
 * for money either way, and the desk tells them which of the two happened.
 */
class StorePettyCashVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('pettycash.spend');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payee')) {
            $this->merge(['payee' => trim((string) $this->input('payee'))]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'fund_id' => [
                'required', 'integer',
                Rule::exists('petty_cash_funds', 'id')->where('company_id', $companyId),
            ],
            'expense_category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')->where('company_id', $companyId),
            ],
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'spent_on' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'expense_category_id.required' => 'Pick a category — it is what tells the ledger which account the money was spent on.',
            'amount.gt' => 'An amount has to be more than nothing.',
            'spent_on.required' => 'Say when the money was handed over — that is the date the ledger carries.',
        ];
    }

    public function attributes(): array
    {
        return ['spent_on' => 'date paid', 'fund_id' => 'float', 'expense_category_id' => 'category'];
    }
}
