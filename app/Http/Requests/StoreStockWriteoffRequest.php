<?php

namespace App\Http\Requests;

use App\Domain\Inventory\StockMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockWriteoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'writeoff_date' => ['required', 'date'],
            'source_state' => ['required', Rule::in([
                StockMovement::STATE_DAMAGED,
                StockMovement::STATE_QUARANTINED,
                StockMovement::STATE_ON_HAND,
            ])],
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
            'source_state' => 'stock compartment',
            'reason' => 'reason',
            'lines' => 'write-off lines',
            'lines.*.qty' => 'quantity',
        ];
    }
}
