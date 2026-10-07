<?php

namespace Database\Seeders;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * STRUCTURAL inventory foundation (Phase E / §21 no-fake-data rule):
 *  - one default warehouse per default branch (so stock posts have a home);
 *  - nothing else — zero products, zero movements, zero balances.
 *
 * Products are operator-created business data and are never seeded.
 */
class InventoryCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            return;
        }

        $branch = Branch::query()
            ->where('company_id', $company->id)
            ->where('is_default', true)
            ->first();

        if ($branch === null) {
            return;
        }

        Warehouse::updateOrCreate(
            [
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'code' => 'MAIN',
            ],
            [
                'name' => 'Main Warehouse',
                'is_default' => true,
                'is_active' => true,
            ],
        );
    }
}
