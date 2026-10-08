<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Cheque;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Writing a cheque into the register (§08-13).
 *
 * What the form can check it checks here, so the operator sees the reason on the
 * field they filled in wrong; the service refuses the same things again, because
 * an importer is not a form and cannot be trusted to have read this file.
 *
 * The date field is deliberately not "today": a cheque written for a future day
 * is a post-dated cheque, a normal instrument in this market, and the desk lets
 * it through and then refuses to deposit or present it early.
 */
class StoreChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cheque_no')) {
            $this->merge(['cheque_no' => trim((string) $this->input('cheque_no'))]);
        }

        if ($this->has('bank_name')) {
            $this->merge(['bank_name' => trim((string) $this->input('bank_name'))]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'direction' => ['required', Rule::in(array_keys(Cheque::DIRECTIONS))],
            'cheque_no' => ['required', 'string', 'max:32'],
            'cheque_date' => ['required', 'date'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'counter_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'party_name' => ['required', 'string', 'max:160'],
            // Optional either way: a cheque is often from somebody who is not yet
            // on the books, and the party's name is what the register shows. When
            // the party *is* on the books the link carries the entry's party line.
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'direction.in' => 'A cheque is either received from somebody or issued to somebody — pick one.',
            'cheque_no.required' => 'The cheque number is how this slip is found again in the drawer — it cannot be blank.',
            'bank_name.required' => 'Name the bank the cheque is drawn on: "a cheque" is not a record.',
            'cheque_date.required' => 'A cheque carries a date. A future one is a post-dated cheque, and the desk will not deposit it early.',
            'party_name.required' => 'Name the party: who wrote this cheque, or who it is made out to.',
            'amount.gt' => 'A cheque is for a real amount — zero and negative cheques do not exist.',
        ];
    }

    public function attributes(): array
    {
        return [
            'account_id' => 'money account',
            'counter_account_id' => 'account it settles',
            'cheque_no' => 'cheque number',
            'party_name' => 'party',
        ];
    }
}
