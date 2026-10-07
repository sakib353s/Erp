<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approval decision payload. A comment is MANDATORY for reject/return/
 * comment (auditable reasoning, Rule 16) and optional for approve/cancel.
 * WHO may act at all is decided later by ApprovalAuthority inside the
 * WorkflowEngine — never here, never in the view.
 */
class ApprovalActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $method = $this->route()?->getActionMethod() ?? '';
        $commentRequired = in_array($method, ['reject', 'returnForCorrection', 'comment'], true);

        return [
            'comment' => [
                $commentRequired ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function attributes(): array
    {
        return ['comment' => 'comment'];
    }
}
