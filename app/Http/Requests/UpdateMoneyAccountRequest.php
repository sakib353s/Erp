<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Services\MoneyAccountService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming or re-describing a money account (§08-06).
 *
 * The code and the instrument are not fields here on purpose. A code is how the
 * ledger, the reports and the auditor's working papers refer to the account, and
 * an instrument is what groups it in every "how much is in the bank" answer the
 * company has ever printed; a screen that could change either would quietly
 * rewrite history instead of asking for a new account.
 */
class UpdateMoneyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bank.accounts');
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:191'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_number' => ['nullable', 'string', 'max:48'],
            'wallet_provider' => ['nullable', Rule::in(array_keys(MoneyAccountService::WALLET_PROVIDERS))],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'wallet_provider' => 'mobile provider',
            'account_number' => 'account number',
        ];
    }
}
