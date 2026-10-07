<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockDamageEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'entry_date' => ['required', 'date'],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.narration' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'warehouse',
            'reason_code' => 'cause',
            'reason' => 'reason',
            'lines' => 'entry lines',
            'lines.*.qty' => 'quantity',
        ];
    }
}
