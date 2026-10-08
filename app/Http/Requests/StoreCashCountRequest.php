<?php

namespace App\Http\Requests;

use App\Domain\CashBank\CashCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Counting a drawer (§08-05).
 *
 * The counted figure is what somebody actually found in the tin, so zero is a
 * perfectly good answer (an empty drawer at the end of a shift) and only a
 * negative one is nonsense. The denominations are optional: a count may be a
 * single figure, and when the notes are written down the desk checks that they
 * add up to it.
 */
class StoreCashCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cash.counts');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'counted_on' => ['required', 'date'],
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'difference_reason' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:300'],
            'denominations' => ['nullable', 'array'],
            'denominations.*.kind' => ['nullable', Rule::in([CashCount::KIND_NOTE, CashCount::KIND_COIN])],
            'denominations.*.face_value' => ['nullable', 'numeric', 'min:0'],
            'denominations.*.quantity' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'counted_amount.min' => 'A drawer cannot hold less than nothing — count what is in it.',
            'counted_amount.required' => 'Say how much was in the tin; that is the whole point of the count.',
            'account_id.required' => 'Choose the drawer you are counting.',
        ];
    }

    public function attributes(): array
    {
        return [
            'counted_amount' => 'counted amount',
            'counted_on' => 'date counted',
            'difference_reason' => 'reason for the difference',
        ];
    }
}
