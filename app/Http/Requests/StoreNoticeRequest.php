<?php

namespace App\Http\Requests;

use App\Domain\Business\Notice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-12 — writing a notice.
 *
 * The audience fields are validated *against this company's own* roles, branches
 * and people: an audience that names somebody else's branch would publish a
 * notice to nobody, or worse, to the wrong nobody. Whatever the chosen mode is,
 * the matching list must be non-empty — “send this to two roles” with two roles
 * unpicked is a notice that reaches no one, and that is a validation error, not
 * a silent empty fan-out.
 */
class StoreNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('business.notices.create') ?? false;
    }

    public function rules(): array
    {
        $companyId = (int) $this->user()->company_id;

        return [
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:20000'],
            'category' => ['required', Rule::in(array_keys(Notice::CATEGORIES))],
            'audience_type' => ['required', Rule::in([
                Notice::AUDIENCE_ALL,
                Notice::AUDIENCE_ROLES,
                Notice::AUDIENCE_BRANCHES,
                Notice::AUDIENCE_USERS,
            ])],
            'audience_roles' => [
                Rule::requiredIf(fn (): bool => $this->input('audience_type') === Notice::AUDIENCE_ROLES),
                'array',
            ],
            'audience_roles.*' => [Rule::exists('roles', 'id')->where('company_id', $companyId)],
            'audience_branches' => [
                Rule::requiredIf(fn (): bool => $this->input('audience_type') === Notice::AUDIENCE_BRANCHES),
                'array',
            ],
            'audience_branches.*' => [Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'audience_users' => [
                Rule::requiredIf(fn (): bool => $this->input('audience_type') === Notice::AUDIENCE_USERS),
                'array',
            ],
            'audience_users.*' => [Rule::exists('users', 'id')->where('company_id', $companyId)],
            'requires_acknowledgement' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'intent' => ['nullable', Rule::in(['draft', 'publish'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'notice title',
            'body' => 'notice text',
            'audience_roles' => 'roles',
            'audience_branches' => 'branches',
            'audience_users' => 'people',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Checkboxes arrive as “1” when ticked and absent when not; the service
        // reads a boolean, so one is made here rather than in three views.
        $this->merge([
            'requires_acknowledgement' => $this->boolean('requires_acknowledgement'),
            'intent' => in_array($this->input('intent'), ['draft', 'publish'], true) ? $this->input('intent') : 'draft',
        ]);
    }

    /** The audience ids for the chosen mode, as the service wants them. */
    public function audienceIds(): array
    {
        return match ($this->validated('audience_type')) {
            Notice::AUDIENCE_ROLES => array_map('intval', (array) $this->validated('audience_roles')),
            Notice::AUDIENCE_BRANCHES => array_map('intval', (array) $this->validated('audience_branches')),
            Notice::AUDIENCE_USERS => array_map('intval', (array) $this->validated('audience_users')),
            default => [],
        };
    }
}
