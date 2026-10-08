<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configuring what an expense category books to (§08-17).
 *
 * This is the one screen in the module that decides where money lands, which is
 * why it is its own permission: handing somebody the expense form is not the
 * same as handing them the mapping the whole expense report is built from.
 */
class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.categories');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }

        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;
        $category = $this->route('category');

        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('expense_categories', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'A code is capitals, digits and . _ - only — it appears in reports and in file names.',
            'code.unique' => 'Another category already uses that code.',
            'account_id.required' => 'Point the category at a ledger account. A category that books nowhere is a trap for whoever records the next expense.',
        ];
    }
}
