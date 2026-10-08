<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The answer to a petty cash request (§08-21): pay it or refuse it.
 *
 * Two outcomes and one note — unlike an expense there is no reversal here,
 * because a voucher that was refused never posted anything to reverse. Approving
 * is the moment the money moves, and the service refuses the approval if the
 * person deciding is the person who asked.
 */
class PettyCashDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('pettycash.approve');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }
}
