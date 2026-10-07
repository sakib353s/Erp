<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'array'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty_received' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'lines' => 'receipt lines',
            'lines.*.qty_received' => 'received quantity',
        ];
    }
}
