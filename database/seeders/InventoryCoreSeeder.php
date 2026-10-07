<?php

namespace Database\Seeders;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\PostingRule;
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
 *
 * It also carries the inventory POSTING RULES (§04-46…04-49): when stock is
 * lost or written off, the value leaves the books through these accounts —
 * Dr Inventory Loss & Damage, Cr Inventory. Which account each role lands on is
 * data here, never a constant inside the service.
 */
class InventoryCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /** event_type => [role, side, account_code, position] */
    protected const RULES = [
        'stock_loss_posted' => [
            ['loss', 'debit', '5260', 10],
            ['inventory', 'credit', '1140', 20],
        ],
        'stock_writeoff_posted' => [
            ['loss', 'debit', '5260', 10],
            ['inventory', 'credit', '1140', 20],
        ],
    ];

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            return;
        }

        $this->seedPostingRules($company->id);

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

    protected function seedPostingRules(int $companyId): void
    {
        foreach (self::RULES as $eventType => $rows) {
            foreach ($rows as [$role, $side, $code, $position]) {
                $account = Account::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->first();

                if ($account === null) {
                    continue;
                }

                PostingRule::updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'event_type' => $eventType,
                        'doc_type' => null,
                        'branch_id' => null,
                        'role' => $role,
                        'position' => $position,
                    ],
                    [
                        'account_id' => $account->id,
                        'side' => $side,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
