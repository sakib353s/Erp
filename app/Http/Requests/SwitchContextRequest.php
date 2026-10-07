<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Branch/warehouse switch proposal — only an ID is ever accepted here;
 * the real decision (exists, active, in user scope, belongs to current
 * branch) is made in ContextController + TenantContext, server-side.
 */
class SwitchContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required_without:warehouse_id', 'integer'],
            'warehouse_id' => ['required_without:branch_id', 'integer'],
        ];
    }
}
