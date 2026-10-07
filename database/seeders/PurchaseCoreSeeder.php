<?php

namespace Database\Seeders;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\PostingRule;
use App\Domain\Foundation\Company;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Purchase posting rules (§03.6) — structure, not business data.
 *
 * A purchase bill is the moment a delivery becomes money owed, so approving one
 * posts a real journal entry. Which account each role lands on is data here,
 * never a constant in the service:
 *
 *   goods-held bill  → Dr Inventory            (stock enters the books)
 *   direct bill      → Dr Purchases & Services (no goods to capitalise)
 *   either           → Dr Tax Payable          (input VAT nets against output)
 *                      Cr Accounts Payable     (the liability itself)
 *
 * Zero transactions are created here: no bills, no journals, no balances.
 */
class PurchaseCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /** event_type => [role, side, account_code, position] */
    protected const RULES = [
        'purchase_bill_posted' => [
            ['inventory', 'debit', '1140', 10],
            ['tax_payable', 'debit', '2120', 20],
            ['ap', 'credit', '2110', 30],
        ],
        'purchase_bill_expense_posted' => [
            ['expense', 'debit', '5225', 10],
            ['tax_payable', 'debit', '2120', 20],
            ['ap', 'credit', '2110', 30],
        ],
        // Supplier payments (§03.7): Dr Accounts Payable, Cr Cash/Bank. The
        // `counter` role is the seeded role name for the bank account (1120),
        // matching the sales rules so one account is never described two ways.
        'supplier_payment' => [
            ['ap', 'debit', '2110', 10],
            ['cash', 'credit', '1110', 20],
        ],
        'supplier_payment_bank' => [
            ['ap', 'debit', '2110', 10],
            ['counter', 'credit', '1120', 20],
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
