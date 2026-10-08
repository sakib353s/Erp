<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording an expense (§08-16).
 *
 * The form checks what a form can check, and the service refuses the same things
 * again — an expense can also arrive from an importer, which has never read this
 * file. `settled_with` decides which of the two fields below it is required: an
 * expense paid from an account names the account, and one owed names who it is
 * owed to.
 *
 * The receipt is optional on purpose. In this market a rickshaw fare, a tea bill
 * and a municipal token tax often come with no paper at all, and a desk that
 * refuses them is a desk that gets bypassed. What is not optional is naming what
 * the money was for.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.create');
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
        $paid = $this->input('settled_with') === Expense::SETTLED_MONEY;

        return [
            'category_id' => [
                'required', 'integer',
                Rule::exists('expense_categories', 'id')->where('company_id', $companyId),
            ],
            'expense_date' => ['required', 'date'],
            'payee' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'settled_with' => ['required', Rule::in(array_keys(Expense::SETTLED_WITH))],
            'money_account_id' => [
                $paid ? 'required' : 'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],
            'narration' => ['nullable', 'string', 'max:500'],
            // A photograph of the paper, if there is paper. Sized and typed like
            // every other upload in this application.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Pick a category — the category is what tells the ledger which account this money came out of.',
            'category_id.exists' => 'That category does not belong to this company.',
            'payee.required' => 'Say who was paid, or who is owed. An expense without a name is an entry nobody can chase.',
            'amount.gt' => 'An expense has to be more than nothing.',
            'money_account_id.required' => 'Choose the account the money left from: cash, a bank account or a wallet.',
        ];
    }

    public function attributes(): array
    {
        return ['category_id' => 'category', 'money_account_id' => 'paid from'];
    }
}
