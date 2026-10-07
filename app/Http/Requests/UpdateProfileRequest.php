<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Self-service profile fields. E-mail changes are intentionally not part of the foundation flow. */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
