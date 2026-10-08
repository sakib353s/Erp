<?php

namespace Database\Seeders;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\PostingRule;
use App\Domain\Foundation\Company;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * §08 — the money desk's posting rules.
 *
 * Only one rule lives here, and it exists because the expense desk refuses to
 * hardcode an account: an expense that has not been paid yet is a liability, and
 * which account carries that liability is the company's decision, not this
 * module's. The debit side of an expense is deliberately absent — it is the
 * category's own ledger account (§08-17), which is exactly what categories are
 * for.
 *
 * Zero transactions are created here: no expenses, no journals, no balances.
 */
class CashBankCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /** event_type => [role, side, account_code, position] */
    protected const RULES = [
        'expense_posted' => [
            ['ap', 'credit', '2110', 10],
        ],
    ];

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            return; // first-boot re-runs the company-scoped seeders after setup
        }

        foreach (self::RULES as $eventType => $rows) {
            foreach ($rows as [$role, $side, $code, $position]) {
                $account = Account::query()
                    ->where('company_id', $company->id)
                    ->where('code', $code)
                    ->first();

                if ($account === null) {
                    continue;
                }

                PostingRule::updateOrCreate(
                    [
                        'company_id' => $company->id,
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
