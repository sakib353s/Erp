<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'account_group_id' => ['nullable', 'integer', 'exists:account_groups,id'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'sub_type' => ['nullable', 'string', 'max:48'],
            'is_group' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'is_control_account' => ['sometimes', 'boolean'],
            'is_cash' => ['sometimes', 'boolean'],
            'is_bank' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'account code',
            'name' => 'account name',
            'type' => 'account type',
            'account_group_id' => 'account group',
            'parent_id' => 'parent account',
        ];
    }
}
