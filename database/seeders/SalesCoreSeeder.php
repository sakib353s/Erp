<?php

namespace Database\Seeders;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\PostingRule;
use App\Domain\Foundation\Company;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * STRUCTURAL sales posting rules (Phase G / §7.2). Maps event types to
 * account roles from the standard COA — not business data (no invoices,
 * no balances). Controllers resolve accounts only via PostingRuleResolver.
 */
class SalesCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /** event_type => [role, side, account_code, position] */
    protected const RULES = [
        'sales_invoice_issued' => [
            ['ar', 'debit', '1130', 10],
            ['sales', 'credit', '4100', 20],
            ['tax_payable', 'credit', '2120', 30],
        ],
        'receipt' => [
            ['cash', 'debit', '1110', 10],
            ['ar', 'credit', '1130', 20],
        ],
        'sales_cost' => [
            ['cogs', 'debit', '5100', 10],
            ['inventory', 'credit', '1140', 20],
        ],
        // Inverse of sales_invoice_issued: Dr sales + Dr tax, Cr ar
        'sales_credit_note_issued' => [
            ['sales', 'debit', '4100', 10],
            ['tax_payable', 'debit', '2120', 20],
            ['ar', 'credit', '1130', 30],
        ],
        // Inverse COGS on return restock: Dr inventory, Cr cogs
        'sales_return' => [
            ['inventory', 'debit', '1140', 10],
            ['cogs', 'credit', '5100', 20],
        ],
        // Inverse of receipt: Dr ar, Cr cash (customer refund payout)
        'refund' => [
            ['ar', 'debit', '1130', 10],
            ['cash', 'credit', '1110', 20],
        ],
        // Sales commission accrual: Dr expense, Cr commission payable
        'commission_accrued' => [
            ['commission_expense', 'debit', '5240', 10],
            ['commission_payable', 'credit', '2130', 20],
        ],
        // Commission payment settle: Dr commission payable, Cr cash
        'commission_payment' => [
            ['commission_payable', 'debit', '2130', 10],
            ['cash', 'credit', '1110', 20],
        ],
        // Direct commission cash-out (no prior accrual): Dr expense, Cr cash
        'commission_direct' => [
            ['commission_expense', 'debit', '5240', 10],
            ['cash', 'credit', '1110', 20],
        ],
        // COD remittance reconciliation (02-97): Dr cash (remitted) +
        // Dr cash over/short when short, Cr AR (cash total) +
        // Cr other income when over — cash vs remittance match.
        'cod_remittance' => [
            ['cash', 'debit', '1110', 10],
            ['ar', 'credit', '1130', 20],
            ['shortage', 'debit', '5250', 30],
            ['overage', 'credit', '4200', 40],
        ],
        // POS drawer movements (02-44/02-45): cash comes into the drawer
        // from the bank/safe and returns to it — a Cash in Hand ↔ Bank
        // transfer, never fabricated income or expense.
        'pos_cash_in' => [
            ['cash', 'debit', '1110', 10],
            ['counter', 'credit', '1120', 20],
        ],
        'pos_cash_out' => [
            ['counter', 'debit', '1120', 10],
            ['cash', 'credit', '1110', 20],
        ],
        // POS layaway deposit (02-41): money received against a layaway
        // is a customer advance (liability), never revenue — Dr cash or
        // bank, Cr Customer Advances until the balance is settled.
        'layaway_deposit' => [
            ['cash', 'debit', '1110', 10],
            ['advance', 'credit', '2140', 20],
        ],
        'layaway_deposit_bank' => [
            ['counter', 'debit', '1120', 10],
            ['advance', 'credit', '2140', 20],
        ],
    ];

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            return;
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
