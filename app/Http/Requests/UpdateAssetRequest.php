<?php

namespace App\Http\Requests;

use App\Domain\Business\AssetRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-14 — correcting an asset on the register.
 *
 * Deliberately wider than creation in one direction and narrower in another. A
 * correction may change where something is, who holds it, what it is called and
 * what condition it is in. It may *not* change the category: recategorising a van
 * as furniture would leave the vehicle fields on a row the vehicle list no longer
 * reads, and the honest way to fix a wrong category is a disposal and a fresh
 * registration, which leaves both facts in the history.
 *
 * The depreciation policy is not here either — it changes the whole schedule, so
 * it has its own route, its own history line and its own confirmation.
 */
class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('business.assets.manage');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
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
            'condition' => ['nullable', 'string', Rule::in(array_keys(AssetRegistry::CONDITIONS))],
            'status' => ['nullable', 'string', Rule::in(array_keys(AssetRegistry::STATUSES))],
            'supplier_name' => ['nullable', 'string', 'max:160'],
            'invoice_ref' => ['nullable', 'string', 'max:120'],
            'warranty_expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'registration_no' => ['nullable', 'string', 'max:40'],
            'engine_no' => ['nullable', 'string', 'max:60'],
            'chassis_no' => ['nullable', 'string', 'max:60'],
            'driver_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'odometer_reading' => ['nullable', 'integer', 'min:0'],

            // The cost may be corrected — invoices get mis-keyed and repairs get
            // capitalised later — but life and salvage move through the policy
            // route, where the schedule history is written.
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'gl_account_code' => ['nullable', 'string', 'max:12'],
        ];
    }

    public function assetData(): array
    {
        return $this->validated();
    }

    public function attributes(): array
    {
        return [
            'custodian_id' => 'custodian',
            'driver_id' => 'driver',
            'registration_no' => 'registration number',
            'odometer_reading' => 'odometer reading',
        ];
    }

    public function messages(): array
    {
        return [
            'condition.in' => 'Condition is new, good, fair or poor.',
            'status.in' => 'Status is in use, in store, under repair or disposed. Writing something off is its own action, with a date and a reason.',
        ];
    }
}
