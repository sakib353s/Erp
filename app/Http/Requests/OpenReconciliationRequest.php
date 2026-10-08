<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Which reconcile key is needed depends on the account, and only the
        // controller knows the account — so this is the floor (either key), and
        // the instrument's own key is asserted again where the account is known.
        return (bool) ($this->user()?->can('bank.reconcile') || $this->user()?->can('wallets.reconcile'));
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'statement_closing' => ['required', 'numeric'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'period_start' => 'period start',
            'period_end' => 'period end',
            'statement_closing' => 'statement closing balance',
        ];
    }

    public function messages(): array
    {
        return [
            'statement_closing.required' => 'Type the closing balance the statement shows. Without it there is nothing to prove against.',
            'period_end.after_or_equal' => 'The period ends before it starts — a statement month cannot run backwards.',
        ];
    }
}
