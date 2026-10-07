<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Purchase bill entry (§03.6). The header carries no money at all: totals are
 * derived from the lines by PurchaseBillService, so a client cannot bill itself
 * a different figure than the lines it sent.
 */
class StorePurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'goods_receipt_id' => ['nullable', 'integer', 'exists:goods_receipts,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_bill_no' => ['nullable', 'string', 'max:64'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer', 'exists:goods_receipt_lines,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** Drop lines the user left empty (qty 0) instead of failing the whole bill. */
    public function payload(): array
    {
        $data = $this->validated();
        $data['lines'] = array_values(array_filter(
            $data['lines'],
            fn ($line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        return $data;
    }

    public function attributes(): array
    {
        return [
            'supplier_bill_no' => "the supplier's bill number",
            'lines.*.qty' => 'billed quantity',
            'lines.*.unit_cost' => 'unit cost',
            'lines.*.tax_rate' => 'tax rate',
        ];
    }
}
