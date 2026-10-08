<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Services\MoneyAccountService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Declaring a money account (§08-06, §08-11).
 *
 * The form decides what the instrument has to carry with it: a bank account
 * without a bank is a ledger account with a friendly name, and a wallet without
 * a provider is a number nobody can call. Both are refused here rather than in
 * the service so the operator sees the reason on the field they left empty —
 * the service still refuses them too, because an importer or an API is not a
 * form and cannot be trusted to have read this file.
 */
class StoreMoneyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user?->can('bank.accounts')) {
            return false;
        }

        // A wallet is a separate decision: money leaves one through a
        // different door than it leaves a bank, and reconciling it is manual.
        if ($this->input('instrument') === 'wallet') {
            return (bool) $user->can('wallets.accounts');
        }

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
        $instrument = (string) $this->input('instrument');
        $isWallet = $instrument === 'wallet';

        return [
            'instrument' => ['required', Rule::in(array_keys(MoneyAccountService::INSTRUMENTS))],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('accounts', 'code')->where('company_id', $this->user()?->company_id),
            ],
            'name' => ['required', 'string', 'max:191'],
            'bank_name' => [Rule::requiredIf($instrument === 'bank'), 'nullable', 'string', 'max:80'],
            'account_number' => ['nullable', 'string', 'max:48'],
            'wallet_provider' => [
                Rule::requiredIf($isWallet), 'nullable',
                Rule::in(array_keys(MoneyAccountService::WALLET_PROVIDERS)),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'instrument.in' => 'Money sits in cash, a bank account or a mobile wallet — pick one.',
            'code.regex' => 'Use letters, digits, dashes and underscores, starting with a letter or digit.',
            'bank_name.required' => 'Name the bank: without it this is a ledger account, not a bank account.',
            'wallet_provider.required' => 'Name the provider — bKash, Nagad, Rocket or Upay.',
            'wallet_provider.in' => 'The providers wired in are bKash, Nagad, Rocket and Upay.',
        ];
    }

    public function attributes(): array
    {
        return [
            'instrument' => 'money account type',
            'wallet_provider' => 'mobile provider',
            'account_number' => 'account number',
        ];
    }
}
