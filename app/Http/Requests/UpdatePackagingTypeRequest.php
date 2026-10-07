<?php

namespace App\Http\Requests;

use App\Domain\Delivery\PackagingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §04-59 — renaming a declared type.
 *
 * Code and product are editable here only while the type has never consumed
 * anything; once it has, the service refuses to move them (a box that was costed
 * under one code cannot silently become another). The rule is enforced in the
 * service — this request only checks that the values are the right shape and
 * that a code is not being taken from another type of the same company.
 */
class UpdatePackagingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->route('packagingType');
        $ignoreId = $type instanceof PackagingType ? $type->id : null;

        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('packaging_types', 'code')
                    ->where('company_id', $this->user()->company_id)
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'packaging code',
            'name' => 'packaging name',
            'product_id' => 'stock product',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
