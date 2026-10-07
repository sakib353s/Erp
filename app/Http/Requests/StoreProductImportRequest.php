<?php

namespace App\Http\Requests;

use App\Domain\Inventory\Services\ProductImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A catalogue import is a file plus an intention (§04-12): look at it, or apply
 * it. The extension is checked rather than the sniffed mime type, because Excel
 * saves a CSV as `application/vnd.ms-excel` and refusing that would refuse the
 * file most people actually upload.
 */
class StoreProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.ProductImportService::MAX_KILOBYTES,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $extension = strtolower((string) $value->getClientOriginalExtension());

                    if (! in_array($extension, ['csv', 'txt', 'tsv'], true)) {
                        $fail('Upload a CSV file — the template downloads as one, and a spreadsheet can save as one.');
                    }
                },
            ],
            'mode' => ['required', 'in:preview,import'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => 'CSV file', 'mode' => 'mode'];
    }
}
