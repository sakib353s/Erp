<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A charge the bank has already taken (§08-10).
 *
 * The form can go two ways, and the difference is worth keeping straight: naming
 * a rule means the desk computes the amount from the rule's terms (a percentage
 * of what left the account is not a figure anybody should retype), while filling
 * in an amount records what the statement says. When a rule is named the account
 * and the expense account come from the rule, so the request only insists on the
 * amount in the manual case.
 */
class StoreBankChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bank.charges');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $fromRule = $this->filled('rule_id');

        return [
            'rule_id' => [
                'nullable', 'integer',
                Rule::exists('bank_charge_rules', 'id')->where('company_id', $companyId),
            ],
            'account_id' => [
                $fromRule ? 'nullable' : 'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'expense_account_id' => [
                $fromRule ? 'nullable' : 'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'amount' => [$fromRule ? 'nullable' : 'required', 'numeric', 'gt:0'],
            'charged_on' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'Say which account the bank took the charge from.',
            'expense_account_id.required' => 'Say which account the charge is booked to.',
            'amount.required' => 'Enter the amount the bank took — or pick a rule and let the desk compute it.',
            'amount.gt' => 'A charge has to be more than nothing.',
            'charged_on.required' => 'Say the date the bank took it — that is the date the ledger carries.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rule_id' => 'rule',
            'account_id' => 'account',
            'expense_account_id' => 'expense account',
            'charged_on' => 'date charged',
        ];
    }
}
