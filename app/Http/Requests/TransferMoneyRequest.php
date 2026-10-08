<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A transfer between two of the company's own accounts (§08-04).
 *
 * `different:from_account_id` is the one refusal that belongs on the form: money
 * cannot be moved from an account to itself, and saying so next to the field is
 * better than an exception the operator reads as a bug. Everything else — both
 * accounts really being money accounts, the money actually being there — is the
 * service's business, because a rule the form owns is a rule the next caller
 * (a job, an API, a test) does not get.
 */
class TransferMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cash.transfers');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'from_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'to_account_id' => [
                'required', 'integer', 'different:from_account_id',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'transferred_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_account_id.different' => 'The money is already there — pick a different account to move it to.',
            'amount.gt' => 'Transferring nothing moves nothing; enter the amount that left the account.',
        ];
    }

    public function attributes(): array
    {
        return [
            'from_account_id' => 'from account',
            'to_account_id' => 'to account',
            'transferred_on' => 'date',
        ];
    }
}
