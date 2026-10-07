<?php

namespace Database\Seeders;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\FeatureEntitlement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local feature-entitlement rows (clarification C3). Requires the
 * company (company-scoped table), so on a fresh instance it is a no-op
 * until setup; on provisioned instances it guarantees a row exists for
 * every feature key so the platform can flip them individually.
 */
class FeatureEntitlementSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            if ($this->command) {
                $this->command->info('No company yet (pre-setup) — entitlement rows are created during first-boot setup.');
            }

            return;
        }

        foreach ((array) config('erp.features.keys') as $key) {
            FeatureEntitlement::firstOrCreate(
                ['company_id' => $company->id, 'feature_key' => $key],
                ['is_enabled' => (bool) config('erp.features.default_enabled'), 'source' => 'platform'],
            );
        }
    }
}
