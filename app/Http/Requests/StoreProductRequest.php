<?php

namespace App\Http\Requests;

use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * §04-20: with `inventory.sku_from_code` on, a product saved without a SKU is
     * labelled with its code. Derived here rather than in the controller so the
     * rule below still means what it says — the SKU is required, and this is the
     * one case where the request itself can supply it.
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
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'cost_method' => ['required', Rule::in(['fifo', 'lifo', 'wac', 'standard'])],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],
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
