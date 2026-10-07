<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §04-58 — saying no to a proposal.
 *
 * The reason is required. A dismissed proposal is the only record of a decision
 * *not* to buy, and "no" without a why is the kind of note that forces the next
 * person to buy it anyway just to be safe.
 */
class DismissReorderSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['note' => 'reason'];
    }
}
