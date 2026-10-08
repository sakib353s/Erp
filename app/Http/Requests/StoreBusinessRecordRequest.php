<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Recording something new on a register.
 *
 * Three kinds refuse to be recorded half-filled, and each refusal is a fact about
 * the paper rather than a preference:
 *
 *  · a **licence** or an **insurance policy** without an end date is a document
 *    nobody can act on — the register exists to say when it stops being true;
 *  · a **filing** or a **statutory obligation** without a due date is not a
 *    deadline, it is a note;
 *  · a **contract** or an **agreement** without the other party cannot be
 *    checked against anything.
 *
 * A TIN has no expiry, a brand asset has no deadline, and a certificate's term
 * may be open — those are left optional on purpose.
 */
class StoreBusinessRecordRequest extends BusinessRecordRequest
{
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'expires_on' => [
                'nullable', 'date',
                Rule::requiredIf(fn () => in_array($this->input('kind'), ['licence', 'insurance'], true)),
            ],
            'due_on' => [
                'nullable', 'date',
                Rule::requiredIf(fn () => in_array($this->input('kind'), ['filing', 'obligation'], true)),
            ],
            'counterparty' => [
                'nullable', 'string', 'max:160',
                Rule::requiredIf(fn () => in_array($this->input('kind'), ['contract', 'agreement'], true)),
            ],
        ]);
    }

    public function messages(): array
    {
        return [
            'expires_on.required' => 'A licence or a policy needs the date it runs out — that date is the reason it is on the register.',
            'due_on.required' => 'A filing or an obligation needs the date it is due.',
            'counterparty.required' => 'Name the other party: a contract with one side cannot be checked against anything.',
        ];
    }
}
