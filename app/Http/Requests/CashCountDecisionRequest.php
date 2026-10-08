<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The answer to a counted difference (§08-05): accept it, or refuse it.
 *
 * Approving is the moment the gap reaches the ledger; refusing leaves the books
 * exactly as they are and demands a reason, because "we did not accept this
 * count" is an answer the next person to open that drawer has to be able to read.
 */
class CashCountDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cash.counts.approve');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
