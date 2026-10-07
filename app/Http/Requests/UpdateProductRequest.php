<?php

namespace App\Http\Requests;

use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * §04-20: the same rule on edit as on create. The edit form stops marking the
     * SKU required when the shop lets a blank SKU mean "use the code", so the
     * request has to honour that rather than reject the page's own form.
     */
    protected function prepareForValidation(): void
    {
        if (! app(SettingService::class)->getBool('inventory', 'sku_from_code', false)) {
            return;
        }

        if (trim((string) $this->input('sku')) !== '') {
            return;
        }

        $this->merge(['sku' => $this->input('code')]);
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
