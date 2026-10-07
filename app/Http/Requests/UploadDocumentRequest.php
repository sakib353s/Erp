<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload envelope only — the REAL security check (extension allow-list,
 * finfo MIME sniffing, per-type byte limits) happens inside
 * FileUploadService::validate() so CLI/queue/tests get the same
 * protection as the HTTP layer.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.(int) (config('erp.upload.max_bytes') / 1024),
            ],
            'purpose' => ['nullable', 'in:attachment,logo,seal,signature,generated,export'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'file', 'purpose' => 'purpose'];
    }
}
