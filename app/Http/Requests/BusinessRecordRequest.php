<?php

namespace App\Http\Requests;

use App\Domain\Business\RecordsRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-03/04/09/10 — the shared envelope for writing a business record.
 *
 * Which fields *exist* for a kind is the registry's call, so the rules here ask
 * the registry rather than hard-coding a list per kind: a contract's counterparty
 * is required because a contract without a second party is not a contract, and
 * the same field is simply absent from a licence's form. The rules that a kind
 * must satisfy are in {@see StoreBusinessRecordRequest}; editing an existing
 * record is looser, because a record that has been on the register for three
 * years should be correctable without re-typing facts nobody disputes.
 */
abstract class BusinessRecordRequest extends FormRequest
{
    /** Everything a record may carry, in the order the forms show it. */
    public const FIELDS = [
        'kind', 'title', 'branch_id', 'reference_no', 'issuer', 'counterparty',
        'value_amount', 'issued_on', 'starts_on', 'expires_on', 'due_on',
        'repeat_months', 'notes',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('business.records.manage');
    }

    /** @return array<string, array<int, mixed>> */
    protected function baseRules(): array
    {
        $registry = app(RecordsRegistry::class);

        return [
            'kind' => ['required', 'string', Rule::in(array_keys($registry->all()))],
            'title' => ['required', 'string', 'max:191'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $this->user()?->company_id),
            ],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'issuer' => ['nullable', 'string', 'max:160'],
            'counterparty' => ['nullable', 'string', 'max:160'],
            'value_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'issued_on' => ['nullable', 'date'],
            'starts_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'repeat_months' => ['nullable', 'integer', Rule::in(array_keys(RecordsRegistry::CADENCES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Only the columns a record actually has, in the shape the service wants. */
    public function recordData(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::FIELDS));
    }

    public function attributes(): array
    {
        return [
            'kind' => 'kind of record',
            'reference_no' => 'number',
            'issuer' => 'issuing authority',
            'counterparty' => 'other party',
            'value_amount' => 'value',
            'issued_on' => 'issue date',
            'starts_on' => 'start date',
            'expires_on' => 'expiry date',
            'due_on' => 'due date',
            'repeat_months' => 'repeat cycle',
        ];
    }
}
