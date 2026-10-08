<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The movements a cheque can make after it is written (§08-13): deposited or
 * presented, cleared, or failed. One form field says which, because they are
 * one action on one register row and splitting them into four endpoints would
 * only multiply the places a rule has to be repeated.
 */
class ChequeTransitionRequest extends FormRequest
{
    public const ACTIONS = ['deposit', 'present', 'clear', 'fail'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.clear');
    }

    public function rules(): array
    {
        $failing = $this->input('action') === 'fail';

        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'on' => ['nullable', 'date'],
            // A failure has to be explainable to the person whose cheque it was.
            'reason' => [$failing ? 'required' : 'nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.in' => 'The register knows four movements: deposit, present, clear and fail.',
            'reason.required' => 'Say why it failed — "bounced" without a reason is not something a customer can be told.',
        ];
    }

    public function attributes(): array
    {
        return ['on' => 'date'];
    }
}
