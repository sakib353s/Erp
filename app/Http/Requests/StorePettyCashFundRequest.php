<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Declaring (or re-describing) a float (§08-21).
 *
 * The account code is only read when the float is first declared, because the
 * ledger leaf behind it is what every voucher already posted points at — moving
 * the drawer to another account would re-point history. The terms that can
 * change are the ones that describe the arrangement: who holds it, how much it
 * is meant to hold, and what it is for.
 */
class StorePettyCashFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('pettycash.funds');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name', '')),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $fund = $this->route('fund');

        return [
            'code' => [
                'required', 'string', 'max:32',
                Rule::unique('petty_cash_funds', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($fund?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            // A ledger code of its own, if the accountant wants to choose it.
            'account_code' => ['nullable', 'string', 'max:32'],
            'custodian_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'imprest_amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'opened_on' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another float already uses that code in this company.',
            'imprest_amount.gt' => 'A float without a level has nothing to replenish back to. Say how much it is meant to hold.',
            'custodian_id.required' => 'Name the custodian — the person answerable for the cash in the tin.',
        ];
    }

    public function attributes(): array
    {
        return [
            'custodian_id' => 'custodian',
            'imprest_amount' => 'float level',
            'account_code' => 'ledger account code',
        ];
    }
}
