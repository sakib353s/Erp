<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Topping the float back up (§08-21).
 *
 * The source has to be an account this company actually keeps money in, and the
 * service refuses the float itself as a source: a drawer cannot replenish itself,
 * and a top-up that is really a transfer between two tins belongs on the transfer
 * desk where both sides are named.
 */
class ReplenishPettyCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('pettycash.replenish');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'fund_id' => [
                'required', 'integer',
                Rule::exists('petty_cash_funds', 'id')->where('company_id', $companyId),
            ],
            'source_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'replenished_on' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_account_id.required' => 'Choose the account the money comes from — a top-up has to come from somewhere.',
            'amount.gt' => 'An amount has to be more than nothing.',
        ];
    }

    public function attributes(): array
    {
        return ['replenished_on' => 'date', 'source_account_id' => 'source account', 'fund_id' => 'float'];
    }
}
