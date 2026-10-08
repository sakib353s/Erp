<?php

namespace App\Http\Requests;

use App\Domain\Business\Visitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-16 — somebody added to the visitor register without a visit.
 *
 * A name and a phone is a person the gate can find again; a name on its own is
 * somebody who will be typed in twice and matched to nothing. So the name is
 * required and the rest is honest about being optional — an ID number is
 * personal data the gate holds because it may have to answer for it later, not
 * because it wants it.
 */
class StoreVisitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('business.visitors.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'organisation' => ['nullable', 'string', 'max:160'],
            'id_type' => ['nullable', Rule::in(array_keys(Visitor::ID_TYPES))],
            'id_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'A visitor needs a name — a row nobody can be announced by is not a register entry.',
            'email.email' => 'That is not an email address the gate could write to.',
        ];
    }
}
