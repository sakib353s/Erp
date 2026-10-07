<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The copy of a product needs its own identity: a code and a SKU, unique per
 * company. Everything else is inherited from the source row.
 */
class DuplicateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.\-]+$/',
                Rule::unique('products', 'code')->where('company_id', $companyId)],
            'sku' => ['required', 'string', 'max:64',
                Rule::unique('products', 'sku')->where('company_id', $companyId)],
            'name' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'product code', 'sku' => 'SKU', 'name' => 'product name'];
    }
}
