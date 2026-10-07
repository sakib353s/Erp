<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * §04-44: a pick list is either for an order (lines come from what the order
 * still owes the customer) or manual (a shop picking for a counter sale it never
 * entered as an order). One of the two must actually say something — an empty
 * form that creates an empty walk wastes somebody's trip to the racks.
 */
class StorePickListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['array'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('sales_order_id')) {
                    return;
                }

                if ($this->hasManualLine()) {
                    return;
                }

                $validator->errors()->add('lines', 'Pick an order, or fill at least one row with a product and a quantity.');
            },
        ];
    }

    protected function hasManualLine(): bool
    {
        foreach ((array) $this->input('lines', []) as $row) {
            if (($row['product_id'] ?? null) === null || ($row['product_id'] ?? '') === '') {
                continue;
            }

            if (($row['quantity'] ?? null) !== null && is_numeric($row['quantity']) && (float) $row['quantity'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'warehouse',
            'sales_order_id' => 'sales order',
            'lines.*.product_id' => 'product',
            'lines.*.quantity' => 'quantity',
        ];
    }
}
