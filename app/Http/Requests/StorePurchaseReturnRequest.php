<?php

namespace App\Http\Requests;

use App\Domain\Purchase\Models\PurchaseReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase.returns.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'purchase_bill_id' => ['nullable', 'integer', 'exists:purchase_bills,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'return_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'reason_code' => ['required', Rule::in(array_keys(PurchaseReturn::REASONS))],
            'goods_dispatched' => ['nullable', 'boolean'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer', 'exists:goods_receipt_lines,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.description' => ['required', 'string', 'max:191'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        $data['branch_id'] = $this->user()?->default_branch_id;

        // Header money is never taken from the client: the service recomputes
        // every figure from the lines, so there is nothing to tamper with.
        return $data;
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'return_date' => 'return date',
            'reason_code' => 'reason type',
            'lines.*.qty' => 'returned quantity',
            'lines.*.unit_cost' => 'unit cost',
        ];
    }
}
