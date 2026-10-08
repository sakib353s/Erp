<?php

namespace App\Http\Requests;

use App\Domain\Marketing\MarketingOptout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §11 — somebody asked not to be written to again.
 *
 * The contact is required and is not looked up: the number that sent STOP is the
 * number to stop writing to, whether or not it matches a customer row.
 */
class StoreMarketingOptoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.campaigns.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) ($this->user()?->company_id ?? 0);

        return [
            'channel' => ['required', Rule::in(array_keys(MarketingOptout::CHANNELS))],
            'contact' => ['required', 'string', 'max:191'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'source' => ['required', Rule::in(array_keys(MarketingOptout::SOURCES))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'contact.required' => 'Which number or address asked us to stop? The opt-out is keyed on the contact itself.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_id' => $this->input('customer_id') === '' ? null : $this->input('customer_id'),
        ]);
    }
}
