<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §04-56 — drafting a purchase order from a proposal.
 *
 * A supplier is required because the proposal is not allowed to guess one: an
 * order that names the wrong supplier is worse than no order. The quantity is
 * optional — leave it empty to take the suggested figure — and the comment is
 * for the person reading the order later.
 */
class AcceptReorderSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'quantity' => 'quantity',
        ];
    }
}
