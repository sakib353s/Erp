<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Expense;
use App\Domain\CashBank\RecurringExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A schedule for an expense that comes round again (§08-19).
 *
 * The form asks for the rhythm explicitly — frequency, the day of the month and
 * the first date — because "the 31st" and "the end of the month" are different
 * contracts, and a desk that decides on the operator's behalf eventually pays
 * rent twice in a 30-day month.
 */
class StoreRecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.recurring');
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
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::FREQUENCIES))],
            // Optional: a monthly schedule with no day stated repeats on the day
            // it starts, which is what most people mean.
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Pick a category — the category is what tells the ledger which account these payments come out of.',
            'amount.gt' => 'A scheduled expense has to be more than nothing, or it is a reminder rather than an expense.',
            'day_of_month.max' => 'A day of the month is between 1 and 31. A month that is too short clamps to its last day.',
            'ends_on.after_or_equal' => 'A schedule cannot end before it starts.',
            'money_account_id.required' => 'Choose the account the money leaves from: cash, a bank account or a wallet.',
        ];
    }

    public function attributes(): array
    {
        return ['starts_on' => 'first date', 'ends_on' => 'last date', 'day_of_month' => 'day of the month'];
    }
}
