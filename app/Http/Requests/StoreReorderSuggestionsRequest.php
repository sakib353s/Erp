<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §04-55 — the look that writes the proposals down.
 *
 * Either the whole short list is recorded ("everything that is short, in this
 * warehouse") or a few rows are picked by hand. What is validated is only the
 * shape of the request: which shelves are *actually* still short is decided
 * against the ledger when the service reads it, so a page left open all
 * afternoon cannot propose a purchase that was already made.
 */
class StoreReorderSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'scope' => ['required', Rule::in(['short', 'selected'])],
            'rows' => ['required_if:scope,selected', 'array', 'max:500'],
            // `product:warehouse` — a shelf, so ticking one product in one
            // warehouse can never write down the same product somewhere else.
            'rows.*' => ['string', 'regex:/^[0-9]+:[0-9]+$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'warehouse',
            'rows' => 'rows to record',
        ];
    }
}
