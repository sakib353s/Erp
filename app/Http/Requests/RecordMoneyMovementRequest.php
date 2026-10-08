<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One movement of money — a receipt (§08-02) or a payment (§08-03), which differ
 * only in which side is the money account, so they share one shape and one set
 * of refusals.
 *
 * Both sides are named: the account the money moved through, and the account it
 * is against. Neither is inferred from a rule, because the operator watching the
 * bank SMS is the only party who knows which account the money actually landed
 * in — and a mismatch found three months later is found in the trial balance,
 * not in a form.
 */
class RecordMoneyMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $key = $this->isReceipt() ? 'cash.receipts.create' : 'cash.payments.create';

        return (bool) $this->user()?->can($key);
    }

    public function isReceipt(): bool
    {
        return (string) $this->input('direction', 'in') === 'in';
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'direction' => ['nullable', Rule::in(['in', 'out'])],
            'money_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'counter_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'moved_on' => ['nullable', 'date'],
            'party_name' => ['nullable', 'string', 'max:191'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.gt' => 'A movement of nothing is not a movement — enter the amount that actually moved.',
            'money_account_id.exists' => 'Pick the cash, bank or wallet account the money moved through.',
            'counter_account_id.exists' => 'Pick the account this money is against.',
            'money_account_id.required' => 'Say which account the money moved through.',
            'counter_account_id.required' => 'Say what this money is for — the account on the other side.',
        ];
    }

    public function attributes(): array
    {
        return [
            'money_account_id' => 'money account',
            'counter_account_id' => 'account',
            'moved_on' => 'date',
        ];
    }
}
