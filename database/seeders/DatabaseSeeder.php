<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * STRUCTURAL seed data ONLY (spec: no fake business data, ever):
 *
 *  portals, navigation catalog (§47), the 25 widget containers (§46),
 *  document types (INVOICE + Mushak rows), the permission matrix,
 *  DB translations (EN+BN), plus company-scoped system role and
 *  entitlement rows when a company already exists.
 *
 * No users, no customers, no products, no transactions, no demo
 * statistics — those never appear in any environment from a seeder.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PortalSeeder::class,
            NavigationSeeder::class,
            WidgetSeeder::class,
            DocumentTypeSeeder::class,
            FoundationPermissionSeeder::class,
            TranslationSeeder::class,
            SystemRoleSeeder::class,
            FeatureEntitlementSeeder::class,
            ReferenceDataSeeder::class,
            AccountingCoreSeeder::class,
            InventoryCoreSeeder::class,
            SalesCoreSeeder::class,
            PurchaseCoreSeeder::class,
            CashBankCoreSeeder::class,
        ]);
    }
}
