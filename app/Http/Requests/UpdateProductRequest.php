<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.\-]+$/',
                Rule::unique('products', 'code')->where('company_id', $this->user()->company_id)->ignore($productId)],
            'sku' => ['required', 'string', 'max:64',
                Rule::unique('products', 'sku')->where('company_id', $this->user()->company_id)->ignore($productId)],
            'name' => ['required', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'cost_method' => ['required', Rule::in(['fifo', 'lifo', 'wac', 'standard'])],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
            // §04-10: only read when the edit moves the cost. The record is
            // append-only, so the reason is captured with the change itself.
            'cost_change_reason' => ['nullable', 'string', 'max:500'],
            'is_stocked' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_serial' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'product code',
            'sku' => 'SKU',
            'name' => 'product name',
            'cost_method' => 'cost method',
        ];
    }
}
