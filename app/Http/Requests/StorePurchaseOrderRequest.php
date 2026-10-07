<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Purchase order entry (§03). The header carries no money field a client could
 * forge: totals are computed by PurchaseOrderService from the lines.
 */
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'payment_terms' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.description' => ['required', 'string', 'max:191'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();

        // An order with a product-less line is a real thing (freight, service),
        // so only stock lines carry a product.
        $data['lines'] = array_values(array_filter($data['lines'], fn ($line) => (float) $line['qty_ordered'] > 0));

        return $data;
    }

    public function attributes(): array
    {
        return [
            'lines.*.qty_ordered' => 'quantity',
            'lines.*.unit_price' => 'unit price',
            'lines.*.description' => 'line description',
        ];
    }
}
