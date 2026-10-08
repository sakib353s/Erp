<?php

namespace App\Http\Requests;

use App\Domain\Business\AssetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-14 — registering an asset.
 *
 * The rules that are *not* here are the interesting ones. Whether the category
 * exists, whether a vehicle must carry plates, what a sensible default life is,
 * and whether the cost is worth depreciating at all are decided by
 * {@see AssetRegistry} and {@see \App\Domain\Business\Services\AssetService},
 * because they are facts about assets rather than facts about this form — and a
 * rule that lives in two places is a rule that disagrees with itself.
 *
 * What belongs here is what makes a row storable: the name, the category, the
 * branch, the custodian and the money, each of which has to be a real row of the
 * right kind before a register entry can point at it.
 */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('business.assets.manage');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'category' => ['required', 'string', Rule::in(array_keys(AssetRegistry::CATEGORIES))],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:160'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'custodian_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'acquired_on' => ['nullable', 'date'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:160'],
            'invoice_ref' => ['nullable', 'string', 'max:120'],
            'warranty_expires_on' => ['nullable', 'date'],
            'condition' => ['nullable', 'string', Rule::in(array_keys(AssetRegistry::CONDITIONS))],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Vehicle detail. Validated for every category and stored only for
            // vehicles: refusing a stray chassis number on a laptop would be a
            // lecture about a form, and storing one would be worse.
            'registration_no' => ['nullable', 'string', 'max:40'],
            'engine_no' => ['nullable', 'string', 'max:60'],
            'chassis_no' => ['nullable', 'string', 'max:60'],
            'driver_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'odometer_reading' => ['nullable', 'integer', 'min:0'],

            'depreciation_method' => ['nullable', 'string', Rule::in(array_keys(AssetRegistry::METHODS))],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'gl_account_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    /** What the service needs, without the fields it derives itself. */
    public function assetData(): array
    {
        return $this->validated();
    }

    public function attributes(): array
    {
        return [
            'category' => 'kind of asset',
            'custodian_id' => 'custodian',
            'driver_id' => 'driver',
            'registration_no' => 'registration number',
            'odometer_reading' => 'odometer reading',
            'useful_life_months' => 'useful life',
            'salvage_value' => 'salvage value',
            'gl_account_code' => 'ledger account',
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'Pick a kind of asset from the list. The kind decides what else the register asks for.',
            'condition.in' => 'Condition is new, good, fair or poor.',
            'acquisition_cost.min' => 'An asset cannot cost a negative amount.',
            'custodian_id.exists' => 'The custodian has to be a user of this company.',
            'driver_id.exists' => 'The driver has to be a user of this company.',
        ];
    }
}
