<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What may happen to an expense after it is recorded (§08-18).
 *
 *   approve  the waiting expense posts, now and only now
 *   reject   it never posts, and the reason is kept with it
 *   reverse  a posted expense is given back: the original entry stays and a
 *            reversal answers it
 *
 * A reversal without a reason is an entry nobody can explain next year, so the
 * reason is required exactly there — and only there, because refusing an
 * expense is somebody's prerogative and does not have to be justified to the
 * desk.
 */
class ExpenseDecisionRequest extends FormRequest
{
    public const ACTIONS = ['approve', 'reject', 'reverse'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('expenses.approve');
    }

    public function rules(): array
    {
        $reversing = $this->input('action') === 'reverse';

        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'note' => [$reversing ? 'required' : 'nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.in' => 'An expense can be approved, rejected or reversed.',
            'note.required' => 'Say why the expense is being reversed — the ledger will show both entries forever, and only the reason can explain the second one.',
        ];
    }

    public function attributes(): array
    {
        return ['note' => 'reason'];
    }
}
