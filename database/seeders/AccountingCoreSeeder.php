<?php

namespace Database\Seeders;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountGroup;
use App\Domain\Accounting\Services\AccountService;
use App\Domain\Accounting\Services\FiscalPeriodService;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * STRUCTURAL accounting foundation (Phase D / §21 no-fake-data rule):
 *  - default account groups (statement mapping);
 *  - a minimal standard chart of accounts (system control + cash/bank/
 *    AR/AP + equity placeholders) so posting_rules and source documents
 *    have target accounts — these are structure, NOT business data
 *    (no balances, no transactions, no customers);
 *  - OPEN fiscal periods for the current fiscal year (posting gate).
 *
 * Zero journal lines, zero payments, zero balances are seeded.
 */
class AccountingCoreSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * code, name, type, group, flags
     * is_system accounts are protected from delete/reparent.
     */
    protected const ACCOUNTS = [
        // Assets
        ['1000', 'Assets', 'asset', 'ASSET', ['is_group' => true, 'is_system' => true]],
        ['1100', 'Current Assets', 'asset', 'ASSET', ['parent' => '1000', 'is_group' => true, 'is_system' => true]],
        ['1110', 'Cash in Hand', 'asset', 'ASSET', ['parent' => '1100', 'is_cash' => true, 'is_system' => true]],
        ['1120', 'Bank Account', 'asset', 'ASSET', ['parent' => '1100', 'is_bank' => true, 'is_system' => true]],
        ['1130', 'Accounts Receivable', 'asset', 'ASSET', ['parent' => '1100', 'is_control_account' => true, 'is_system' => true]],
        ['1140', 'Inventory', 'asset', 'ASSET', ['parent' => '1100', 'is_system' => true]],
        ['1500', 'Fixed Assets', 'asset', 'ASSET', ['parent' => '1000', 'is_group' => true, 'is_system' => true]],

        // Liabilities
        ['2000', 'Liabilities', 'liability', 'LIABILITY', ['is_group' => true, 'is_system' => true]],
        ['2100', 'Current Liabilities', 'liability', 'LIABILITY', ['parent' => '2000', 'is_group' => true, 'is_system' => true]],
        ['2110', 'Accounts Payable', 'liability', 'LIABILITY', ['parent' => '2100', 'is_control_account' => true, 'is_system' => true]],
        ['2120', 'Tax Payable', 'liability', 'LIABILITY', ['parent' => '2100', 'is_system' => true]],
        ['2130', 'Commission Payable', 'liability', 'LIABILITY', ['parent' => '2100', 'is_system' => true]],
        ['2140', 'Customer Advances', 'liability', 'LIABILITY', ['parent' => '2100', 'is_system' => true]],
        ['2500', 'Long Term Liabilities', 'liability', 'LIABILITY', ['parent' => '2000', 'is_group' => true, 'is_system' => true]],

        // Equity
        ['3000', 'Equity', 'equity', 'EQUITY', ['is_group' => true, 'is_system' => true]],
        ['3100', 'Share Capital', 'equity', 'EQUITY', ['parent' => '3000', 'is_system' => true]],
        ['3200', 'Retained Earnings', 'equity', 'EQUITY', ['parent' => '3000', 'is_system' => true]],

        // Revenue
        ['4000', 'Revenue', 'revenue', 'REVENUE', ['is_group' => true, 'is_system' => true]],
        ['4100', 'Sales Revenue', 'revenue', 'REVENUE', ['parent' => '4000', 'is_system' => true]],
        ['4200', 'Other Income', 'revenue', 'REVENUE', ['parent' => '4000', 'is_system' => true]],

        // Expenses
        ['5000', 'Expenses', 'expense', 'EXPENSE', ['is_group' => true, 'is_system' => true]],
        ['5100', 'Cost of Goods Sold', 'expense', 'EXPENSE', ['parent' => '5000', 'is_system' => true]],
        ['5200', 'Operating Expenses', 'expense', 'EXPENSE', ['parent' => '5000', 'is_group' => true, 'is_system' => true]],
        ['5210', 'Salaries & Wages', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        ['5220', 'Rent Expense', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        ['5230', 'Utilities Expense', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        // Direct/service purchases (§03.6): bills that capitalise no stock land here.
        ['5225', 'Purchases & Services', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        ['5240', 'Sales Commission Expense', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        // COD remittance variance (02-97): office received less/more cash
        // than riders recorded as collected.
        ['5250', 'Cash Over & Short', 'expense', 'EXPENSE', ['parent' => '5200', 'is_system' => true]],
        ['5300', 'Bad Debt Expense', 'expense', 'EXPENSE', ['parent' => '5000', 'is_system' => true]],
    ];

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            return; // first-boot will re-run after company exists
        }

        $this->seedCompany($company);
    }

    private function seedCompany(Company $company): void
    {
        $accountService = app(AccountService::class);
        app(TenantContext::class)->setCompany($company);
        $accountService->ensureDefaultGroups();

        $codeToId = [];

        foreach (self::ACCOUNTS as [$code, $name, $type, $groupCode, $flags]) {
            $group = AccountGroup::query()
                ->where('company_id', $company->id)
                ->where('code', $groupCode)
                ->first();

            $parentId = null;
            if (isset($flags['parent']) && isset($codeToId[$flags['parent']])) {
                $parentId = $codeToId[$flags['parent']];
            }

            $isGroup = (bool) ($flags['is_group'] ?? false);

            $account = Account::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'account_group_id' => $group?->id,
                    'parent_id' => $parentId,
                    'name' => $name,
                    'type' => $type,
                    'is_group' => $isGroup,
                    'is_system' => (bool) ($flags['is_system'] ?? false),
                    'is_active' => true,
                    'is_control_account' => (bool) ($flags['is_control_account'] ?? false),
                    'is_cash' => (bool) ($flags['is_cash'] ?? false),
                    'is_bank' => (bool) ($flags['is_bank'] ?? false),
                    'currency' => 'BDT',
                ],
            );

            $codeToId[$code] = $account->id;
        }

        // Fiscal periods for the current fiscal year (posting gate)
        $fiscalYear = FiscalYear::query()
            ->where('company_id', $company->id)
            ->where('is_current', true)
            ->first();

        if ($fiscalYear !== null) {
            app(FiscalPeriodService::class)->ensurePeriods($fiscalYear);
        }
    }
}
