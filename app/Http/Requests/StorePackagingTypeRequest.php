<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §04-59 — declaring a packaging item.
 *
 * The product has to be one of ours and stock-managed: a packaging type is a
 * promise that stock can be taken out under it, and the ledger only keeps that
 * promise for a stocked product. Whether the product is *active* and stocked is
 * checked again in the service, because a picker that offers a product and a
 * service that refuses it must not be able to disagree silently.
 */
class StorePackagingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('packaging_types', 'code')->where('company_id', $this->user()->company_id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'packaging code',
            'name' => 'packaging name',
            'product_id' => 'stock product',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
