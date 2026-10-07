<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** CSV export window — bounded, validated, and separately permission-gated (audit.export). */
class ExportAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'action' => ['nullable', 'string', 'max:64'],
            'entity_type' => ['nullable', 'string', 'max:64'],
        ];
    }
}
