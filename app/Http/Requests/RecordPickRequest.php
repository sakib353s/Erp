<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §04-44: what came off the shelf. A box left empty means "not finished yet" and
 * keeps whatever was recorded before — it never means zero, which is why the
 * quantities arrive per line id rather than as one form-wide wipe.
 */
class RecordPickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'picked' => ['array'],
            'picked.*' => ['nullable', 'numeric', 'min:0'],
            'line_notes' => ['array'],
            'line_notes.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'picked.*' => 'picked quantity',
        ];
    }
}
