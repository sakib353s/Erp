<?php

namespace App\Http\Requests;

use App\Domain\Business\UtilityProvider;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-15 — filing or editing a utility bill.
 *
 * The shape is checked here; the decisions are not. Whether the provider is this
 * company's, whether it has already billed for the month, whether the due date
 * runs before the issue date, and whether the amount is above the approval limit
 * all belong to `UtilityService`, because they are rules about the company's
 * books rather than rules about a form.
 *
 * One thing is checked here and it is here on purpose: the provider must be one
 * this company owns. A posted `provider_id` from another tenant must be refused
 * before the engine ever sees it.
 */
class StoreUtilityBillRequest extends FormRequest
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
            'provider_id' => [
                'required',
                'integer',
                Rule::exists('utility_providers', 'id')->where('company_id', $companyId),
            ],
            'period_month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'consumption' => ['nullable', 'numeric', 'min:0'],
            'meter_reading' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $branchId = $this->input('branch_id');

        if ($branchId === '' || $branchId === null) {
            $this->merge(['branch_id' => null]);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'provider_id.exists' => 'That provider is not one of this company\'s — pick one from the registry.',
            'period_month.regex' => 'A billing period is a month — write it as YYYY-MM.',
            'due_date.after_or_equal' => 'The due date cannot be before the bill was issued.',
            'amount.gt' => 'A bill has to be more than nothing.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $providerId = (int) $this->input('provider_id');

            if ($providerId === 0) {
                return;
            }

            $tenant = app(TenantContext::class);

            $provider = UtilityProvider::query()
                ->where('company_id', (int) ($this->user()?->company_id ?? $tenant->companyId()))
                ->whereKey($providerId)
                ->first();

            if ($provider !== null && ! $provider->is_active) {
                $validator->errors()->add('provider_id', "[{$provider->name}] is switched off, so a new bill cannot be filed against it.");
            }
        });
    }
}
