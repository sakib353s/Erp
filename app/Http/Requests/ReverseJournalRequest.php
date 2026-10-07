<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason' => 'reversal reason',
        ];
    }
}
