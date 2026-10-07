<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id',
                Rule::notIn([$this->input('to_warehouse_id')])],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'transfer_date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_sent' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'from_warehouse_id' => 'source warehouse',
            'to_warehouse_id' => 'destination warehouse',
            'lines' => 'transfer lines',
            'lines.*.qty_sent' => 'quantity',
        ];
    }
}
