<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Warehouse update — code unique per branch ignoring the current row. */
class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    public function rules(): array
    {
        $warehouse = $this->route('warehouse');

        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('warehouses', 'code')
                    ->where('branch_id', $this->input('branch_id'))
                    ->ignore($warehouse?->id),
            ],
            'name' => ['required', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
