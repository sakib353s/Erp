<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Goods receipt entry (§03). `purchase_order_id` is optional on purpose:
 * goods do arrive without paperwork, and the system records that honestly
 * instead of forcing a fake order.
 */
class StoreGoodsReceiptRequest extends FormRequest
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
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'challan_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.qty_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['lines'] = array_values(array_filter($data['lines'], fn ($line) => (float) $line['qty_received'] > 0));

        if ($data['lines'] === []) {
            $data['lines'] = [];
        }

        return $data;
    }

    public function attributes(): array
    {
        return [
            'lines.*.qty_received' => 'received quantity',
            'lines.*.unit_cost' => 'unit cost',
        ];
    }
}
