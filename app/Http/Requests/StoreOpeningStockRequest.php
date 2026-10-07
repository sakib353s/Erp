<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'narration' => ['nullable', 'string', 'max:500'],
            'idempotency_suffix' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'warehouse',
            'lines' => 'opening lines',
            'lines.*.product_id' => 'product',
            'lines.*.qty' => 'quantity',
        ];
    }
}
