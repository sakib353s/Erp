<?php

namespace App\Http\Requests;

use App\Domain\Business\UtilityProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-15 — adding or editing a utility provider in the registry.
 *
 * The only thing worth saying here is the account: a provider is pointed at a
 * real ledger account of this company, never at a group, because a bill that
 * posts to a group is a bill the trial balance cannot show.
 */
class StoreUtilityProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('business.utilities.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) ($this->user()?->company_id ?? 0);

        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'family' => ['required', Rule::in(array_keys(UtilityProvider::FAMILIES))],
            'expense_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('is_group', false),
            ],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'consumer_no' => ['nullable', 'string', 'max:64'],
            'meter_no' => ['nullable', 'string', 'max:64'],
            'premises' => ['nullable', 'string', 'max:191'],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expense_account_id.exists' => 'Bills post to a ledger account underneath a group — that account is either another company\'s or a group.',
            'due_day.between' => 'The day a bill lands is a day of the month, between 1 and 31.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
            'branch_id' => $this->input('branch_id') === '' ? null : $this->input('branch_id'),
            'expense_account_id' => $this->input('expense_account_id') === '' ? null : $this->input('expense_account_id'),
            'due_day' => $this->input('due_day') === '' ? null : $this->input('due_day'),
        ]);
    }
}
