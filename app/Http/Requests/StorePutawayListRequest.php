<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * §04-44: putaway comes from a posted goods receipt (the paper that brought the
 * stock in) or from a manual instruction, in which case the warehouse has to be
 * named — goods on a dock belong to a dock.
 */
class StorePutawayListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
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

                if ($this->filled('goods_receipt_id')) {
                    return;
                }

                if (! $this->filled('warehouse_id')) {
                    $validator->errors()->add('warehouse_id', 'Say which warehouse the goods are sitting in.');
                }

                if (! $this->hasManualLine()) {
                    $validator->errors()->add('lines', 'Pick a posted receipt, or fill at least one row with a product and a quantity.');
                }
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
            'goods_receipt_id' => 'goods receipt',
            'warehouse_id' => 'warehouse',
            'lines.*.product_id' => 'product',
            'lines.*.quantity' => 'quantity',
        ];
    }
}
