<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A standing instruction about a bank's tariff (§08-10).
 *
 * The two bases are validated apart on purpose: a fixed charge needs an amount
 * and a commission needs a rate, and a form that accepted either for both would
 * let somebody write "0.15% — ৳0.15 a quarter" by accident. A minimum is only
 * meaningful for a commission, so it is only read there.
 */
class StoreBankChargeRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bank.charges.rules');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name', ''))]);
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $percent = $this->input('basis') === 'percent';

        return [
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'expense_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'basis' => ['required', Rule::in(['fixed', 'percent'])],
            'amount' => [$percent ? 'nullable' : 'required', 'numeric', 'min:0'],
            'rate_percent' => [$percent ? 'required' : 'nullable', 'numeric', 'gt:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'half_yearly', 'yearly'])],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'narration' => ['nullable', 'string', 'max:300'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'Say which account the bank takes the charge from.',
            'expense_account_id.required' => 'Say which account the charge is booked to — a bank charge is a cost, and it needs somewhere to sit.',
            'amount.required' => 'A fixed charge needs an amount: the figure on the bank\'s tariff sheet.',
            'rate_percent.required' => 'A commission needs a rate: 0.15% of withdrawals, for instance.',
            'rate_percent.gt' => 'A rate of zero would charge nothing, so it is not a commission.',
            'ends_on.after_or_equal' => 'This rule ends before it starts, so it would never charge anything.',
        ];
    }

    public function attributes(): array
    {
        return [
            'account_id' => 'account',
            'expense_account_id' => 'expense account',
            'rate_percent' => 'rate',
            'min_amount' => 'minimum',
            'day_of_month' => 'day',
            'starts_on' => 'first charge date',
            'ends_on' => 'last charge date',
        ];
    }
}
