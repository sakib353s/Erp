<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],
            'narration' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.dc' => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.party_type' => ['nullable', 'string', 'max:48'],
            'lines.*.party_id' => ['nullable', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines');

            if (! is_array($lines) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $debit = '0.0000';
            $credit = '0.0000';

            foreach ($lines as $line) {
                $amount = number_format((float) ($line['amount'] ?? 0), 4, '.', '');

                if (($line['dc'] ?? null) === 'debit') {
                    $debit = bcadd($debit, $amount, 4);
                } elseif (($line['dc'] ?? null) === 'credit') {
                    $credit = bcadd($credit, $amount, 4);
                }
            }

            if (bccomp($debit, $credit, 4) !== 0) {
                $validator->errors()->add(
                    'lines',
                    sprintf('Unbalanced journal: debits %s ≠ credits %s.', $debit, $credit),
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'entry_date' => 'entry date',
            'description' => 'description',
            'lines' => 'journal lines',
            'lines.*.account_id' => 'account',
            'lines.*.dc' => 'debit/credit',
            'lines.*.amount' => 'amount',
        ];
    }
}
