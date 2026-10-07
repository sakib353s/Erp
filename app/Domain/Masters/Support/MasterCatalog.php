<?php

namespace App\Domain\Masters\Support;

use App\Domain\Masters\Bank;
use App\Domain\Masters\Brand;
use App\Domain\Masters\CancelReason;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\District;
use App\Domain\Masters\ExpenseCategory;
use App\Domain\Masters\Holiday;
use App\Domain\Masters\LeaveType;
use App\Domain\Masters\PaymentMethod;
use App\Domain\Masters\ProductCategory;
use App\Domain\Masters\ReturnReason;
use App\Domain\Masters\SmsProvider;
use App\Domain\Masters\Supplier;
use App\Domain\Masters\TaxRate;
use App\Domain\Masters\Unit;

/**
 * Registry of master-data resources (traceability §14). Drives routes,
 * validation, generic CRUD views, and permission selection so no
 * per-resource controller ever hard-codes a permission string outside
 * this single source of truth.
 */
class MasterCatalog
{
    public const PERMISSION_VIEW_GEO = 'masters.view';

    public const PERMISSION_MANAGE = 'masters.manage';

    public const PERMISSION_TAX = 'tax.manage';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'units' => [
                'model' => Unit::class,
                'label' => 'Units of Measure',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'symbol', 'base_unit', 'conversion_factor', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'symbol' => ['label' => 'Symbol', 'type' => 'text', 'required' => false, 'max' => 32],
                    'base_unit' => ['label' => 'Base unit code', 'type' => 'text', 'required' => false, 'max' => 32],
                    'conversion_factor' => ['label' => 'Conversion factor', 'type' => 'number', 'required' => false, 'step' => '0.0001'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'product-categories' => [
                'model' => ProductCategory::class,
                'label' => 'Product Categories',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'parent_id', 'is_global', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'is_global' => ['label' => 'Global (company-wide)', 'type' => 'boolean'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'brands' => [
                'model' => Brand::class,
                'label' => 'Brands',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'description', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'expense-categories' => [
                'model' => ExpenseCategory::class,
                'label' => 'Expense Categories',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'gl_account_id', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'gl_account_id' => ['label' => 'GL account id', 'type' => 'integer', 'required' => false],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'payment-methods' => [
                'model' => PaymentMethod::class,
                'label' => 'Payment Methods',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'provider', 'requires_reference', 'is_default', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'provider' => ['label' => 'Provider', 'type' => 'text', 'required' => false, 'max' => 64],
                    'requires_reference' => ['label' => 'Requires reference no.', 'type' => 'boolean'],
                    'is_default' => ['label' => 'Default method', 'type' => 'boolean'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                    'sort' => ['label' => 'Sort', 'type' => 'integer', 'required' => false],
                ],
            ],
            'delivery-zones' => [
                'model' => DeliveryZone::class,
                'label' => 'Delivery Zones',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'base_charge', 'per_kg_charge', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'base_charge' => ['label' => 'Base charge', 'type' => 'number', 'required' => false, 'step' => '0.01'],
                    'per_kg_charge' => ['label' => 'Per kg charge', 'type' => 'number', 'required' => false, 'step' => '0.01'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'districts' => [
                'model' => District::class,
                'label' => 'Districts & Upazilas',
                'permission' => self::PERMISSION_VIEW_GEO,
                'mutation_permission' => self::PERMISSION_MANAGE,
                'company_scoped' => false,
                'columns' => ['code', 'name', 'name_bn', 'division', 'sort', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 16, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'name_bn' => ['label' => 'Name (Bangla)', 'type' => 'text', 'required' => false, 'max' => 191],
                    'division' => ['label' => 'Division', 'type' => 'text', 'required' => false, 'max' => 64],
                    'sort' => ['label' => 'Sort', 'type' => 'integer', 'required' => false],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'holidays' => [
                'model' => Holiday::class,
                'label' => 'Holidays',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['name', 'date', 'type', 'is_recurring', 'is_active'],
                'fields' => [
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'name_bn' => ['label' => 'Name (Bangla)', 'type' => 'text', 'required' => false, 'max' => 191],
                    'date' => ['label' => 'Date', 'type' => 'date', 'required' => true],
                    'type' => ['label' => 'Type', 'type' => 'select', 'required' => false, 'options' => ['national', 'public', 'bank', 'optional']],
                    'is_recurring' => ['label' => 'Recurring (same date yearly)', 'type' => 'boolean'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'banks' => [
                'model' => Bank::class,
                'label' => 'Banks',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'swift_code', 'routing_number', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'swift_code' => ['label' => 'SWIFT', 'type' => 'text', 'required' => false, 'max' => 32],
                    'routing_number' => ['label' => 'BEFTN routing', 'type' => 'text', 'required' => false, 'max' => 32],
                    'website' => ['label' => 'Website', 'type' => 'text', 'required' => false, 'max' => 191],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'couriers' => [
                'model' => Courier::class,
                'label' => 'Couriers',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'configuration_status', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'tracking_url_pattern' => ['label' => 'Tracking URL (use :ref)', 'type' => 'text', 'required' => false, 'max' => 191],
                    'service_status' => ['label' => 'Service status', 'type' => 'select', 'required' => false, 'options' => ['operational', 'degraded', 'suspended']],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                    'sort' => ['label' => 'Sort', 'type' => 'integer', 'required' => false],
                ],
            ],
            'sms-providers' => [
                'model' => SmsProvider::class,
                'label' => 'SMS Providers',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'sender_id', 'config_status', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'api_endpoint' => ['label' => 'API endpoint', 'type' => 'text', 'required' => false, 'max' => 191],
                    'sender_id' => ['label' => 'Sender ID', 'type' => 'text', 'required' => false, 'max' => 32],
                    'config_status' => ['label' => 'Config status', 'type' => 'select', 'required' => false, 'options' => ['not_configured', 'configured', 'error']],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'leave-types' => [
                'model' => LeaveType::class,
                'label' => 'Leave Types',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'default_days', 'is_paid', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'default_days' => ['label' => 'Default days', 'type' => 'number', 'required' => false, 'step' => '0.5'],
                    'is_paid' => ['label' => 'Paid', 'type' => 'boolean'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'return-reasons' => [
                'model' => ReturnReason::class,
                'label' => 'Return Reasons',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'requires_inspection', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'requires_inspection' => ['label' => 'Requires inspection', 'type' => 'boolean'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                    'sort' => ['label' => 'Sort', 'type' => 'integer', 'required' => false],
                ],
            ],
            'cancel-reasons' => [
                'model' => CancelReason::class,
                'label' => 'Cancel Reasons',
                'permission' => self::PERMISSION_MANAGE,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'is_active', 'sort'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'description' => ['label' => 'Description', 'type' => 'text', 'required' => false, 'max' => 500],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                    'sort' => ['label' => 'Sort', 'type' => 'integer', 'required' => false],
                ],
            ],
            'tax-rates' => [
                'model' => TaxRate::class,
                'label' => 'Tax Rates',
                'permission' => self::PERMISSION_TAX,
                'company_scoped' => true,
                'columns' => ['code', 'name', 'tax_type', 'rate', 'effective_from', 'effective_to', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'tax_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'options' => ['vat', 'sd', 'ait', 'other']],
                    'rate' => ['label' => 'Rate (%)', 'type' => 'number', 'required' => true, 'step' => '0.000001'],
                    'effective_from' => ['label' => 'Effective from', 'type' => 'date', 'required' => true],
                    'effective_to' => ['label' => 'Effective to', 'type' => 'date', 'required' => false],
                    'applicability' => ['label' => 'Applicability', 'type' => 'text', 'required' => false, 'max' => 64],
                    'document_applicability' => ['label' => 'Document applicability', 'type' => 'text', 'required' => false, 'max' => 64],
                    'gl_account_id' => ['label' => 'GL account id', 'type' => 'integer', 'required' => false],
                    'source_note' => ['label' => 'Source note', 'type' => 'text', 'required' => false, 'max' => 500],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'customers' => [
                'model' => Customer::class,
                'label' => 'Customers',
                'permission' => 'customers.manage',
                'company_scoped' => true,
                'columns' => ['code', 'name', 'phone', 'email', 'bin', 'credit_limit', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'phone' => ['label' => 'Phone', 'type' => 'text', 'required' => false, 'max' => 32],
                    'email' => ['label' => 'Email', 'type' => 'text', 'required' => false, 'max' => 191],
                    'bin' => ['label' => 'BIN / TIN', 'type' => 'text', 'required' => false, 'max' => 32],
                    'address_line1' => ['label' => 'Address', 'type' => 'text', 'required' => false, 'max' => 191],
                    'credit_limit' => ['label' => 'Credit limit', 'type' => 'number', 'required' => false, 'step' => '0.01'],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
            'suppliers' => [
                'model' => Supplier::class,
                'label' => 'Suppliers',
                'permission' => 'suppliers.manage',
                'company_scoped' => true,
                'columns' => ['code', 'name', 'phone', 'email', 'bin', 'is_active'],
                'fields' => [
                    'code' => ['label' => 'Code', 'type' => 'text', 'required' => true, 'max' => 32, 'upper' => true],
                    'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'max' => 191],
                    'phone' => ['label' => 'Phone', 'type' => 'text', 'required' => false, 'max' => 32],
                    'email' => ['label' => 'Email', 'type' => 'text', 'required' => false, 'max' => 191],
                    'bin' => ['label' => 'BIN / TIN', 'type' => 'text', 'required' => false, 'max' => 32],
                    'address_line1' => ['label' => 'Address', 'type' => 'text', 'required' => false, 'max' => 191],
                    'is_active' => ['label' => 'Active', 'type' => 'boolean'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** Permission required to open mutations (create/update/delete). */
    public static function mutationPermission(string $slug): string
    {
        $entry = self::get($slug);

        return (string) ($entry['mutation_permission'] ?? $entry['permission'] ?? self::PERMISSION_MANAGE);
    }
}
