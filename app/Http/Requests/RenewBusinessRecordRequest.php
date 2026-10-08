<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §12 — a renewal: a new expiry, optionally the new number and fee, and the day
 * the renewal was actually taken out (which is not always today, because
 * registers are kept after the fact as often as before it).
 *
 * The one rule that is not here is the interesting one — a renewal may not move
 * the expiry *backwards* — and it lives in the service, where it can compare
 * against the record's current date rather than against an input.
 */
class RenewBusinessRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('business.records.manage');
    }

    public function rules(): array
    {
        return [
            'expires_on' => ['required', 'date'],
            'renewed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'value_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'expires_on' => 'new expiry date',
            'renewed_on' => 'date of renewal',
            'reference_no' => 'number',
            'value_amount' => 'fee',
        ];
    }
}
