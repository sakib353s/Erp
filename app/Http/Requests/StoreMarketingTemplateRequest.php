<?php

namespace App\Http\Requests;

use App\Domain\Marketing\Services\CampaignService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §11 — a message body the desk may reuse.
 *
 * The placeholders are a closed set on purpose: `{customer}`, `{company}`,
 * `{month}` and `{date}` are the four values the renderer actually knows. A body
 * full of `{order_total}` would go out with the braces still in it, which is the
 * kind of detail a customer notices and a template list should never allow.
 */
class StoreMarketingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.campaigns.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'channel' => ['required', Rule::in(CampaignService::CHANNELS)],
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'A template with no body cannot be sent by anybody.',
            'subject.max' => 'An email subject is one line — the body is where the rest goes.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
