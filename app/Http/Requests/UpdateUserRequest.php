<?php

namespace App\Http\Requests;

use App\Domain\Security\Services\PasswordPolicy;
use Illuminate\Validation\Rule;

/**
 * User update: same guards as StoreUserRequest with e-mail uniqueness
 * ignoring the current row; the password is OPTIONAL (leave blank to
 * keep) and still runs the real policy when provided.
 */
class UpdateUserRequest extends StoreUserRequest
{
    public function rules(): array
    {
        $user = $this->route('user');

        return $this->baseRules() + [
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => ['nullable', 'string', 'max:200', $this->policyRule()],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->input('password') === null || $this->input('password') === '') {
            // blank = keep the current password (the controller treats a
            // null value as "unchanged" — there is no password rehash).
            $this->merge(['password' => null]);
        }
    }
}
