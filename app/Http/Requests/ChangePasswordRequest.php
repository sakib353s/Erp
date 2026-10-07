<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Password change: proves knowledge of the current password (framework
 * `current_password` rule against the authenticated user), rejects
 * re-using it, and then passes the configurable PasswordPolicy inside
 * ProfileController (history, complexity — section C).
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'max:200', 'confirmed', 'different:current_password'],
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'current password',
            'password' => 'new password',
        ];
    }
}
