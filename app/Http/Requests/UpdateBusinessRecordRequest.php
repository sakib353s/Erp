<?php

namespace App\Http\Requests;

/**
 * Correcting a record that is already on the register.
 *
 * The kind is deliberately not editable: turning a contract into a licence would
 * move it to another shelf, change which columns are meaningful and quietly
 * rewrite its history. If a record was filed on the wrong shelf, retire it and
 * record the right one — that is a fact with a date, and the audit trail can see
 * it.
 *
 * The strict "must carry an end date / a due date / a counterparty" rules are
 * recording-time rules: an old licence whose paper has been lost is still a
 * licence, and editing its title should not be blocked by the missing date.
 */
class UpdateBusinessRecordRequest extends BusinessRecordRequest
{
    public function rules(): array
    {
        $rules = $this->baseRules();
        unset($rules['kind']);

        return $rules;
    }
}
