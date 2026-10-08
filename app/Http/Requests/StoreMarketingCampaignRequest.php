<?php

namespace App\Http\Requests;

use App\Domain\Marketing\MarketingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §11 — writing a campaign down.
 *
 * The rules are the campaign's own vocabulary: one of the four channels, one of
 * the four audience rules, a message, and a subject only where a subject exists.
 * The service decides the rest — what the body may contain, whether the template
 * belongs to the channel, and whether the campaign has already gone out.
 */
class StoreMarketingCampaignRequest extends FormRequest
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
            'channel' => ['required', Rule::in(array_keys(MarketingCampaign::CHANNELS))],
            'name' => ['required', 'string', 'max:160'],
            'objective' => ['nullable', 'string', 'max:191'],
            'template_id' => [
                'nullable',
                'integer',
                Rule::exists('message_templates', 'id'),   // channel match is the service's call
            ],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'audience' => ['required', Rule::in(array_keys(MarketingCampaign::AUDIENCES))],
            'audience_days' => ['nullable', 'integer', 'between:1,365'],
            'cost_per_message' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'attribution_days' => ['nullable', 'integer', 'between:1,90'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'A campaign without a message is a note to self. Write what the customer will read.',
            'audience.required' => 'Choose who this goes to — everybody, recent buyers, gone quiet, or a hand-picked list.',
            'audience_days.between' => 'The window is a number of days, between 1 and 365.',
            'cost_per_message.max' => 'That is more than ninety-nine thousand per message — check the decimal point.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'template_id' => $this->input('template_id') === '' ? null : $this->input('template_id'),
            'audience_days' => $this->input('audience_days') === '' ? null : $this->input('audience_days'),
            'attribution_days' => $this->input('attribution_days') === '' ? null : $this->input('attribution_days'),
            'cost_per_message' => $this->input('cost_per_message') === '' ? 0 : $this->input('cost_per_message'),
            'scheduled_at' => $this->input('scheduled_at') === '' ? null : $this->input('scheduled_at'),
        ]);
    }
}
