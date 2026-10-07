<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Module;
use App\Domain\Foundation\Permission;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Imports the verbatim menu catalog (database/catalog/menu_tree.txt,
 * §47) into the navigation registry.
 *
 *  - module headers become module rows + root menu rows (group nodes),
 *  - tree lines become menu rows with derived codes and routes,
 *  - verb-first leaves ("Add Product", "Bulk Print Invoice") are ACTION
 *    entries that share their parent page's route,
 *  - a permission row is created for every entry (module/resource/action
 *    matrix, exportable), with explicit overrides for the pages that are
 *    implemented first,
 *  - status is 'active' only when a real GET route exists; everything
 *    else stays 'planned' and NEVER renders (clarification C1),
 *  - branch nodes become active only when some child is active, so the
 *    sidebar never shows dead groups.
 *
 * Idempotent: run via `php artisan menu:sync` after each phase.
 */
class CatalogImporter
{
    /** Explicit module code → feature entitlement key mapping. */
    protected const MODULE_CODES = [
        'DASHBOARD' => 'dashboard',
        'SALES' => 'sales',
        'PURCHASE' => 'purchase',
        'INVENTORY' => 'inventory',
        'CUSTOMERS' => 'customers',
        'SUPPLIERS' => 'suppliers',
        'RETURNS' => 'returns',
        'CASH & BANK' => 'cash_bank',
        'ACCOUNTING' => 'accounting',
        'EMPLOYEE' => 'employee',
        'MARKETING' => 'marketing',
        'BUSINESS MANAGEMENT' => 'business_management',
        'REPORTS' => 'reports',
        'MASTERS' => 'masters',
        'SETTINGS' => 'settings',
    ];

    protected const MODULE_ICONS = [
        'dashboard' => 'bi-grid-1x2-fill',
        'sales' => 'bi-cart3',
        'purchase' => 'bi-bag-check',
        'inventory' => 'bi-box-seam',
        'customers' => 'bi-people',
        'suppliers' => 'bi-truck',
        'returns' => 'bi-arrow-counterclockwise',
        'cash_bank' => 'bi-bank',
        'accounting' => 'bi-journal-text',
        'employee' => 'bi-person-badge',
        'marketing' => 'bi-megaphone',
        'business_management' => 'bi-building',
        'reports' => 'bi-graph-up',
        'masters' => 'bi-list-check',
        'settings' => 'bi-sliders',
    ];

    /** First word of a label that marks an ACTION leaf rather than a page. */
    protected const ACTION_VERBS = [
        'add', 'apply', 'allocate', 'archive', 'approve', 'attach', 'bulk', 'cancel',
        'clear', 'confirm', 'create', 'deactivate', 'delete', 'detach', 'download',
        'duplicate', 'edit', 'export', 'force', 'generate', 'import', 'issue', 'lock',
        'mark', 'merge', 'notify', 'optimize', 'pay', 'post', 'purge', 'rebuild',
        'recharge', 'recalculate', 'refund', 'regenerate', 'regen', 'register',
        'reconcile', 'remove', 'rename', 'reorder', 'repair', 'resend', 'reset',
        'restore', 'revert', 'rollback', 'run', 'save', 'send', 'split', 'sync',
        'test', 'unlock', 'update', 'upload', 'verify', 'view', 'void', 'print',
    ];

    /**
     * label-path (lowercase, ' > '-joined) => [route, permission key]
     * for pages implemented in the foundation phases.
     */
    protected const OVERRIDES = [
        'settings > general settings' => ['/app/settings/general', 'settings.view'],
        'settings > company settings' => ['/app/settings/company', 'settings.company'],
        'settings > security settings' => ['/app/settings/security', 'settings.view'],
        'settings > notification settings' => ['/app/settings/notifications', 'settings.view'],
        'settings > audit log retention' => ['/app/settings/audit', 'settings.view'],
        'settings > user management' => ['/app/users', 'users.view'],
        'settings > roles & permissions' => ['/app/roles', 'roles.view'],
        'settings > branch settings' => ['/app/branches', 'branches.view'],
        'settings > invoice settings > invoice number format' => ['/app/settings/numbering', 'settings.view'],
        'settings > bengali settings > bengali language toggle' => ['/app/settings/localization', 'settings.view'],
        'settings > bengali settings > bengali numerals' => ['/app/settings/localization#numerals', 'settings.view'],
        'settings > bengali settings > amount in words (bengali)' => ['/app/settings/localization#amount-words', 'settings.view'],
        'settings > bengali settings > lakh / crore format' => ['/app/settings/localization#lakh-crore', 'settings.view'],
        'employee > employees > all employees' => ['/app/employees', 'employees.view'],

        // §03 purchase + §06 suppliers — the pages that now exist
        'purchase > suppliers > all suppliers' => ['/app/suppliers', 'suppliers.view'],
        'purchase > suppliers > add supplier' => ['/app/suppliers/create', 'suppliers.create'],
        'purchase > suppliers > supplier profile' => ['/app/suppliers', 'suppliers.view'],

        // §06 suppliers — the ledger, the due screens and the statement now exist
        'suppliers > all suppliers' => ['/app/suppliers', 'suppliers.view'],
        'suppliers > add supplier' => ['/app/suppliers/create', 'suppliers.create'],
        'suppliers > supplier profile' => ['/app/suppliers', 'suppliers.view'],
        'suppliers > supplier ledger' => ['/app/suppliers/ledger', 'suppliers.view'],
        'suppliers > supplier statements' => ['/app/suppliers/ledger', 'suppliers.view'],
        'suppliers > supplier due' => ['/app/purchase/payables', 'purchase.bills.view'],
        'suppliers > supplier due > all due' => ['/app/purchase/payables', 'purchase.bills.view'],
        'suppliers > supplier due > overdue (0-30 days)' => ['/app/purchase/payables?bucket=d1_30', 'purchase.bills.view'],
        'suppliers > supplier due > overdue (31-60 days)' => ['/app/purchase/payables?bucket=d31_60', 'purchase.bills.view'],
        'suppliers > supplier due > overdue (60+ days)' => ['/app/purchase/payables?bucket=d90_plus', 'purchase.bills.view'],
        'suppliers > supplier payments' => ['/app/purchase/payments', 'purchase.payments.view'],
        'suppliers > supplier payments > record payment' => ['/app/purchase/payments/create', 'purchase.payments.create'],
        'suppliers > supplier payments > advance adjustment' => ['/app/purchase/payments', 'purchase.payments.view'],
        'purchase > purchase orders > all purchase orders' => ['/app/purchase/orders', 'purchase.orders.view'],
        'purchase > purchase orders > create po' => ['/app/purchase/orders/create', 'purchase.orders.create'],
        'purchase > purchase orders > pending approval' => ['/app/purchase/orders?status=pending_approval', 'purchase.orders.view'],
        'purchase > purchase orders > approved pos' => ['/app/purchase/orders?status=approved', 'purchase.orders.view'],
        'purchase > purchase orders > partially received' => ['/app/purchase/orders?status=partially_received', 'purchase.orders.view'],
        'purchase > purchase orders > fully received' => ['/app/purchase/orders?status=received', 'purchase.orders.view'],
        'purchase > purchase orders > cancelled pos' => ['/app/purchase/orders?status=cancelled', 'purchase.orders.view'],
        'purchase > goods receipt (grn) > all grns' => ['/app/purchase/receipts', 'purchase.receipts.view'],
        'purchase > goods receipt (grn) > create grn' => ['/app/purchase/receipts/create', 'purchase.receipts.create'],
        'purchase > goods receipt (grn) > grn to stock update' => ['/app/purchase/receipts?status=draft', 'purchase.receipts.post'],
        'purchase > goods receipt (grn) > grn history' => ['/app/purchase/receipts?status=posted', 'purchase.receipts.view'],
        'purchase > purchase bills > all bills' => ['/app/purchase/bills', 'purchase.bills.view'],
        'purchase > purchase bills > create bill' => ['/app/purchase/bills/create', 'purchase.bills.create'],
        'purchase > purchase bills > bill from grn' => ['/app/purchase/bills/create', 'purchase.bills.create'],
        'purchase > purchase bills > pending bills' => ['/app/purchase/bills?status=pending_approval', 'purchase.bills.view'],
        'purchase > purchase bills > paid bills' => ['/app/purchase/bills?status=paid', 'purchase.bills.view'],
        'purchase > purchase bills > overdue bills' => ['/app/purchase/bills?status=overdue', 'purchase.bills.view'],
        'purchase > purchase bills > 3-way match' => ['/app/purchase/bills?status=open', 'purchase.bills.view'],
        'purchase > purchase bills > bill aging' => ['/app/purchase/payables', 'purchase.bills.view'],
        'purchase > supplier payments > record payment' => ['/app/purchase/payments/create', 'purchase.payments.create'],
        'purchase > supplier payments > payment history' => ['/app/purchase/payments', 'purchase.payments.view'],
        'purchase > purchase bills > bill payment recording' => ['/app/purchase/payments/create', 'purchase.payments.create'],
        'purchase > supplier returns > all returns' => ['/app/purchase/returns', 'purchase.returns.view'],
        'purchase > supplier returns > create return' => ['/app/purchase/returns/create', 'purchase.returns.create'],
        'purchase > supplier returns > return authorization' => ['/app/purchase/returns?status=pending_approval', 'purchase.returns.approve'],
        'purchase > supplier returns > auto debit note' => ['/app/purchase/returns?status=approved', 'purchase.returns.view'],
        'purchase > supplier returns > return history' => ['/app/purchase/returns?status=approved', 'purchase.returns.view'],
        'purchase > supplier returns > supplier ledger credit' => ['/app/purchase/returns', 'purchase.returns.view'],

        // §10 HRM — attendance, leave, structure (pages that really exist)
        'employee > attendance > attendance list' => ['/app/hr/attendance', 'attendance.view'],
        'employee > attendance > live attendance' => ['/app/hr/attendance', 'attendance.view'],
        'employee > attendance > manual attendance entry' => ['/app/hr/attendance', 'attendance.manage'],
        'employee > attendance > attendance reports' => ['/app/hr/attendance/summary', 'attendance.report'],
        'employee > attendance > monthly attendance' => ['/app/hr/attendance/summary', 'attendance.report'],
        'employee > leave management > leave requests' => ['/app/hr/leave', 'leave.view'],
        'employee > leave management > leave approval' => ['/app/hr/leave', 'leave.approve'],
        'employee > leave management > leave calendar' => ['/app/hr/leave/calendar', 'leave.view'],
        'employee > leave management > leave balances' => ['/app/hr/leave', 'leave.balance'],
        'employee > leave management > leave types' => ['/app/hr/leave-types', 'hr.leave_types.manage'],
        'masters > units of measure' => ['/app/masters/units', 'masters.manage'],
        'masters > brands' => ['/app/masters/brands', 'masters.manage'],
        'masters > bangladesh holidays' => ['/app/masters/holidays', 'masters.manage'],
        'masters > bank list' => ['/app/masters/banks', 'masters.manage'],
        'masters > courier list' => ['/app/masters/couriers', 'masters.manage'],
        'masters > leave types' => ['/app/masters/leave-types', 'masters.manage'],
        'masters > return reasons' => ['/app/masters/return-reasons', 'masters.manage'],
        'masters > cancel reasons' => ['/app/masters/cancel-reasons', 'masters.manage'],
        'masters > tax rates' => ['/app/masters/tax-rates', 'tax.manage'],
        'masters > district + upazila list' => ['/app/masters/districts', 'masters.view'],
        'masters > sms providers' => ['/app/masters/sms-providers', 'masters.manage'],
        'masters > expense categories' => ['/app/masters/expense-categories', 'masters.manage'],
        'masters > payment methods' => ['/app/masters/payment-methods', 'masters.manage'],
        'masters > delivery zones' => ['/app/masters/delivery-zones', 'masters.manage'],
        'sales > delivery > delivery zones' => ['/app/sales/delivery/zones', 'sales.delivery.zones'],
        'sales > delivery > add delivery zone' => ['/app/sales/delivery/zones', 'sales.delivery.zones'],
        'sales > delivery > zone-wise charges' => ['/app/sales/delivery/zones', 'sales.delivery.zones'],
        'sales > delivery > delivery charges setup' => ['/app/sales/delivery/zones', 'sales.delivery.zones'],
        'sales > delivery > shipments' => ['/app/sales/shipments', 'sales.delivery.shipments'],
        'sales > delivery > create shipment' => ['/app/sales/shipments', 'sales.delivery.shipments.create'],
        'sales > delivery > bulk shipment' => ['/app/sales/shipments', 'sales.delivery.shipments.create'],
        'sales > delivery > shipment tracking' => ['/app/sales/shipments', 'sales.delivery.view'],
        'sales > delivery > tracking events' => ['/app/sales/shipments', 'sales.delivery.view'],
        'sales > delivery > courier partners' => ['/app/settings/couriers', 'sales.delivery.configure'],
        'sales > delivery > pathao courier' => ['/app/settings/couriers/pathao', 'sales.delivery.configure'],
        'sales > delivery > redx courier' => ['/app/settings/couriers/redx', 'sales.delivery.configure'],
        'sales > delivery > steadfast courier' => ['/app/settings/couriers/steadfast', 'sales.delivery.configure'],
        'sales > delivery > paperfly courier' => ['/app/settings/couriers/paperfly', 'sales.delivery.configure'],
        'sales > delivery > e-courier bd' => ['/app/settings/couriers/e-courier', 'sales.delivery.configure'],
        'sales > delivery > sundarban courier' => ['/app/settings/couriers/sundarban', 'sales.delivery.configure'],
        'sales > delivery > sa paribahan' => ['/app/settings/couriers/sa-paribahan', 'sales.delivery.configure'],
        'sales > delivery > own delivery riders' => ['/app/sales/delivery/riders', 'sales.delivery.riders'],
        'sales > delivery > rider management' => ['/app/sales/delivery/riders', 'sales.delivery.riders'],
        'sales > delivery > rider gps tracking' => ['/app/sales/delivery/riders/gps', 'sales.delivery.riders'],
        'sales > delivery > rider assignment' => ['/app/sales/delivery/rider-assignments', 'sales.delivery.riders'],
        'sales > delivery > rider cod collection' => ['/app/sales/delivery/rider-cod', 'sales.delivery.riders'],
        'sales > delivery > route optimization' => ['/app/sales/delivery/routes', 'sales.delivery.routes'],
        'sales > delivery > proof of delivery' => ['/app/sales/delivery/pod', 'sales.delivery.pod'],
        'sales > delivery > failed delivery management' => ['/app/sales/delivery/failed', 'sales.delivery.view'],
        'sales > delivery > cod collection tracking' => ['/app/sales/delivery/cod', 'sales.delivery.cod'],
        'sales > delivery > cod reconciliation' => ['/app/sales/delivery/cod', 'sales.delivery.cod.reconcile'],
        'sales > delivery > packaging management' => ['/app/sales/delivery/packaging', 'sales.delivery.packaging'],
        'sales > pos / counter sales > pos return' => ['/pos/returns', 'pos.returns.create'],
        'sales > pos / counter sales > pos exchange' => ['/pos/exchange', 'pos.returns.exchange'],
        'sales > pos / counter sales > cash drawer' => ['/pos/drawer', 'pos.cash_drawer'],
        'sales > pos / counter sales > cash in / cash out' => ['/pos/cash-in-out', 'pos.cash_io'],
        'sales > pos / counter sales > pos quotation' => ['/pos', 'sales.quotations.create'],
        'sales > pos / counter sales > layaway / advance deposit' => ['/pos', 'pos.layaway'],
        'sales > pos / counter sales > customer display' => ['/pos/customer-display', 'pos.customer_display'],
        'sales > pos / counter sales > pos settings' => ['/app/settings/pos', 'pos.settings.configure'],
        'sales > orders > return requested' => ['/app/sales/orders?status=return_requested', 'returns.view'],
        'sales > orders > return approved' => ['/app/sales/orders?status=return_approved', 'returns.view'],
        'sales > orders > returned' => ['/app/sales/orders?status=returned', 'returns.view'],
        'sales > orders > refunded' => ['/app/sales/orders?status=refunded', 'returns.refunds.view'],
        'sales > orders > fake / suspicious orders' => ['/app/sales/orders?flag=suspicious', 'sales.orders.review'],
        'masters > product categories' => ['/app/masters/product-categories', 'masters.manage'],
        'business management > documents' => ['/app/documents', 'documents.view'],
        'sales > price management > price list' => ['/app/pricing/price-lists', 'pricing.manage'],
        'sales > price management > bulk price update' => ['/app/pricing/bulk-update', 'pricing.bulk_update'],
        'sales > price management > price history' => ['/app/pricing/history', 'pricing.view'],

        'sales > price management > price comparison' => ['/app/pricing/compare', 'pricing.view'],
        'sales > price management > special pricing' => ['/app/pricing/rules', 'pricing.rules'],
        'sales > price management > customer group pricing' => ['/app/pricing/rules', 'pricing.rules'],
        'sales > price management > quantity break pricing' => ['/app/pricing/rules', 'pricing.rules'],
        'sales > price management > geographic pricing' => ['/app/pricing/rules', 'pricing.rules'],
        'sales > price management > time-based pricing' => ['/app/pricing/rules', 'pricing.rules'],
        'sales > invoices > invoice aging report' => ['/app/reports/sales/invoice-aging', 'sales.reports.view'],
        'sales > invoices > tax invoice (mushak 9.1)' => ['/app/sales/invoices', 'sales.invoices.statutory_print'],
        'sales > sales reports > sales summary' => ['/app/reports/sales/summary', 'sales.reports.view'],
        'sales > sales reports > sales by product' => ['/app/reports/sales/by-product', 'sales.reports.view'],
        'sales > sales reports > sales by category' => ['/app/reports/sales/by-category', 'sales.reports.view'],
        'sales > sales reports > sales by brand' => ['/app/reports/sales/by-brand', 'sales.reports.view'],
        'sales > sales reports > sales by customer' => ['/app/reports/sales/by-customer', 'sales.reports.view'],
        'sales > sales reports > sales by employee' => ['/app/reports/sales/by-employee', 'sales.reports.view'],
        'sales > sales reports > sales by branch' => ['/app/reports/sales/by-branch', 'branches.compare'],
        'sales > sales reports > sales by payment method' => ['/app/reports/sales/by-method', 'sales.reports.view'],
        'sales > sales reports > sales by area/zone' => ['/app/reports/sales/by-zone', 'sales.reports.view'],
        'sales > sales reports > peak hours analysis' => ['/app/reports/sales/peak-hours', 'sales.reports.view'],
        'sales > sales reports > sales trend' => ['/app/reports/sales/trend', 'sales.reports.view'],
        'sales > sales reports > custom sales report' => ['/app/reports/sales/custom', 'sales.reports.view'],
        'settings > system maintenance > rebuild search index' => ['/app/maintenance', 'maintenance.index'],

        // Phase D accounting (09-03…09-10, 09-32)
        'accounting > chart of accounts > coa tree' => ['/app/accounting/coa', 'accounting.coa.view'],
        'accounting > chart of accounts > account groups' => ['/app/accounting/account-groups', 'accounting.coa.view'],
        'accounting > chart of accounts > add account' => ['/app/accounting/accounts/create', 'accounting.coa.manage'],
        'accounting > chart of accounts > edit account' => ['/app/accounting/accounts', 'accounting.coa.manage'],
        'accounting > journal entries > all entries' => ['/app/accounting/journals', 'accounting.journals.view'],
        'accounting > journal entries > create entry' => ['/app/accounting/journals/create', 'accounting.journals.create'],
        'accounting > journal entries > manual journal' => ['/app/accounting/journals/create', 'accounting.journals.create'],
        'accounting > journal entries > auto journal' => ['/app/accounting/journals?source=auto', 'accounting.journals.view'],
        'accounting > journal entries > journal reversal' => ['/app/accounting/journals', 'accounting.journals.reverse'],
        'accounting > opening balance entry' => ['/app/accounting/journals/create', 'accounting.opening.create'],
        'accounting > opening trial balance' => ['/app/accounting/opening-trial-balance', 'accounting.reports.view'],
        'accounting > financial reports > trial balance' => ['/app/accounting/trial-balance', 'accounting.reports.view'],

        // Phase E inventory core (04-01…04-33)
        'inventory > products > all products' => ['/app/inventory/products', 'inventory.products.view'],
        'inventory > products > add product' => ['/app/inventory/products/create', 'inventory.products.create'],
        'inventory > stock > stock overview' => ['/app/inventory/stock', 'inventory.stock.view'],
        'inventory > stock > stock list' => ['/app/inventory/stock', 'inventory.stock.view'],
        'inventory > stock > stock per branch' => ['/app/inventory/stock', 'inventory.stock.view'],
        'inventory > stock > opening stock entry' => ['/app/inventory/stock/opening', 'inventory.adjustments.create'],
        'inventory > stock > stock adjustment' => ['/app/inventory/adjustments', 'inventory.adjustments.view'],
        'inventory > stock > create adjustment' => ['/app/inventory/adjustments/create', 'inventory.adjustments.create'],
        'inventory > stock > stock transfer' => ['/app/inventory/transfers', 'inventory.transfers.create'],
        'inventory > stock > create transfer' => ['/app/inventory/transfers/create', 'inventory.transfers.create'],
        'inventory > stock > stock movement ledger' => ['/app/inventory/movements', 'inventory.ledger.view'],
        'inventory > stock > stock reservation' => ['/app/inventory/reservations', 'inventory.reservations.view'],
        'inventory > stock > stock aging' => ['/app/reports/inventory/aging', 'inventory.reports.view'],
        'inventory > stock > dead stock report' => ['/app/reports/inventory/dead-stock', 'inventory.reports.view'],
        'inventory > stock > stock reports' => ['/app/reports/inventory/stock', 'inventory.reports.view'],
        'inventory > stock > stock ledger' => ['/app/inventory/movements', 'inventory.ledger.view'],
        // §04-23/04-24: alerts judged by a real policy, and the policies themselves
        'inventory > stock > low stock alert' => ['/app/inventory/stock/alerts?type=low', 'inventory.reorder.view'],
        'inventory > stock > out of stock' => ['/app/inventory/stock/alerts?type=out', 'inventory.reorder.view'],
        'inventory > stock > overstock alert' => ['/app/inventory/stock/alerts?type=over', 'inventory.reorder.view'],
        'inventory > stock > minimum stock level' => ['/app/inventory/reorder-levels', 'inventory.reorder.view'],
        // §04-31: a count sheet is opened here; "Cycle Count" starts a subset sheet.
        'inventory > stock > stock count' => ['/app/inventory/counts', 'inventory.counts.view'],
        'inventory > stock > cycle count' => ['/app/inventory/counts/create?scope=cycle', 'inventory.counts.create'],
        // §04-42/04-43/04-45: a warehouse is opened to see its layout. Zones, bins
        // and assignments live on that screen, so their leaves land on the list
        // rather than pretending to be separate pages. Warehousing lives at
        // /app/warehouses — one surface, not a second module hidden beside it,
        // so each structure leaf lands on the list and the warehouse is opened
        // from there.
        'inventory > warehouse > warehouses' => ['/app/warehouses', 'warehouses.view'],
        'inventory > warehouse > add warehouse' => ['/app/warehouses/create', 'warehouses.create'],
        'inventory > warehouse > warehouse zones' => ['/app/warehouses', 'warehouses.view'],
        'inventory > warehouse > bin locations' => ['/app/warehouses', 'warehouses.view'],
        'inventory > warehouse > product bin assignment' => ['/app/warehouses', 'warehouses.view'],
        'inventory > warehouse > warehouse map' => ['/app/warehouses', 'warehouses.view'],
        'inventory > stock > maximum stock level' => ['/app/inventory/reorder-levels', 'inventory.reorder.view'],
        // §04-46…04-51: damage and loss are their own register, and the write-off
        // is the document that needs a second person — so it gets its own queue.
        'inventory > damage & loss > damage records' => ['/app/inventory/damage', 'inventory.stock.view'],
        'inventory > damage & loss > create damage entry' => ['/app/inventory/damage/create', 'inventory.damage.create'],
        'inventory > damage & loss > loss records' => ['/app/inventory/loss', 'inventory.stock.view'],
        'inventory > damage & loss > create loss entry' => ['/app/inventory/loss/create', 'inventory.loss.create'],
        'inventory > damage & loss > write-off approval' => ['/app/inventory/writeoffs?status=pending_approval', 'inventory.writeoffs.approve'],
        'inventory > damage & loss > damage valuation' => ['/app/reports/inventory/damage', 'inventory.reports.view'],
        'inventory > damage & loss > damage analytics' => ['/app/reports/inventory/damage', 'inventory.reports.view'],
        // Dashboard leaves that already have a real screen behind them
        'dashboard > low stock alert' => ['/app/inventory/stock/alerts?type=low', 'inventory.reorder.view'],
        'dashboard > out of stock alert' => ['/app/inventory/stock/alerts?type=out', 'inventory.reorder.view'],
        'dashboard > payable aging (0-30 / 31-60 / 61-90 / 90+)' => ['/app/purchase/payables', 'purchase.bills.view'],
        'dashboard > receivable aging (0-30 / 31-60 / 61-90 / 90+)' => ['/app/customers/due', 'customers.due.view'],
        'accounting > financial reports > general ledger' => ['/app/accounting/coa', 'accounting.coa.view'],

        // Phase G sales team (02-78…02-83)
        'sales > sales team > sales persons' => ['/app/sales/team', 'sales.team.view'],
        'sales > sales team > add sales person' => ['/app/sales/team', 'sales.team.create'],
        'sales > sales team > daily sales target' => ['/app/sales/team/targets', 'sales.team.targets'],
        'sales > sales team > monthly sales target' => ['/app/sales/team/targets', 'sales.team.targets'],
        'sales > sales team > yearly sales target' => ['/app/sales/team/targets', 'sales.team.targets'],
        'sales > sales team > target achievement' => ['/app/sales/team/achievement', 'sales.team.view'],
        'sales > sales team > sales leaderboard' => ['/app/sales/team/leaderboard', 'sales.team.view'],
        'sales > sales team > sales performance' => ['/app/sales/team/performance', 'sales.team.view'],
        'sales > sales team > sales commission' => ['/app/sales/team/commissions', 'sales.team.commissions'],
        'sales > sales team > commission rules' => ['/app/sales/team/commission-rules', 'sales.team.commissions'],
        'sales > sales team > commission payment' => ['/app/sales/team/commissions', 'sales.team.commission_pay'],
        'sales > sales team > sales call log' => ['/app/sales/team/calls', 'sales.team.calls'],
        'sales > sales team > field sales' => ['/app/sales/team/field-sales', 'sales.team.view'],
        'sales > sales team > field visit log' => ['/app/sales/team/field-visits', 'sales.team.field_tracking'],
        'sales > sales team > gps track replay' => ['/app/sales/team/field-visits', 'sales.team.field_tracking'],
        'sales > sales team > beat plan' => ['/app/sales/team/beat-plans', 'sales.team.field_tracking'],
        'sales > sales team > territory management' => ['/app/sales/team/territories', 'sales.team.territories'],

        // Phase G coupons & promotions (02-101…02-107)
        'sales > coupons & discounts > all coupons' => ['/app/sales/coupons', 'sales.coupons.view'],
        'sales > coupons & discounts > create coupon' => ['/app/sales/coupons', 'sales.coupons.create'],
        'sales > coupons & discounts > percent off coupon' => ['/app/sales/coupons?type=percent_off', 'sales.coupons.view'],
        'sales > coupons & discounts > fixed off coupon' => ['/app/sales/coupons?type=fixed_off', 'sales.coupons.view'],
        'sales > coupons & discounts > free shipping coupon' => ['/app/sales/coupons?type=free_shipping', 'sales.coupons.view'],
        'sales > coupons & discounts > buy x get y coupon' => ['/app/sales/coupons?type=buy_x_get_y', 'sales.coupons.view'],
        'sales > coupons & discounts > coupon usage' => ['/app/sales/coupons/usage', 'sales.coupons.view'],
        'sales > coupons & discounts > coupon analytics' => ['/app/sales/coupons/usage?view=analytics', 'sales.coupons.view'],
        'sales > coupons & discounts > bulk coupon generation' => ['/app/sales/coupons', 'sales.coupons.bulk'],
        'sales > coupons & discounts > promotions' => ['/app/sales/promotions', 'sales.promotions.view'],
        'sales > coupons & discounts > create promotion' => ['/app/sales/promotions', 'sales.promotions.create'],
        'sales > coupons & discounts > seasonal promotions' => ['/app/sales/promotions?kind=seasonal', 'sales.promotions.view'],
        'sales > coupons & discounts > flash sales' => ['/app/sales/promotions/flash', 'sales.promotions.view'],
        'sales > coupons & discounts > create flash sale' => ['/app/sales/promotions/flash', 'sales.promotions.create'],
        'sales > coupons & discounts > flash sale countdown' => ['/app/sales/promotions/flash', 'sales.promotions.view'],
        'sales > coupons & discounts > promotion reports' => ['/app/reports/sales/promotions', 'sales.reports.view'],
    ];

    /** @return array{modules:int,items:int,active:int,planned:int,permissions:int} */
    public function sync(bool $dryRun = false): array
    {
        $file = (string) config('erp.navigation.catalog_file');

        if (! is_readable($file)) {
            throw new RuntimeException("Menu catalog not readable: {$file}");
        }

        $uris = $this->registeredUris();
        $stats = ['modules' => 0, 'items' => 0, 'active' => 0, 'planned' => 0, 'permissions' => 0, 'pruned' => 0];

        $module = null;          // current Module model (or code in dry-run)
        $stack = [];             // parsed ancestors: ['slug'=>, 'route_segment'=>, 'is_page'=>, 'code'=>, 'path'=>[]]
        $created = [];           // code => ['row' => model|array, 'parent' => ?code, 'is_page' => bool]
        $usedCodes = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '---')) {
                continue;
            }

            // ---- module header: "01. DASHBOARD" (never indented)
            if (preg_match('/^(\d{2})\.\s+(.+)$/', $line, $m)) {
                $module = $this->syncModule($m[2], (int) $m[1], $stats, $dryRun, $uris, $created, $usedCodes);
                $stack = [];

                continue;
            }

            if ($module === null) {
                continue; // note lines before the first module
            }

            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '(') || ! preg_match('/[├└]──\s*(.+)$/', $line, $lm)) {
                continue; // widget note, structure-only line
            }

            // Dashboard children are WIDGET labels (§46), imported by WidgetSeeder.
            if (($module['code'] ?? $module) === 'dashboard') {
                continue;
            }

            $positions = array_values(array_filter([
                strpos($line, '├──'),
                strpos($line, '└──'),
            ], fn ($p) => $p !== false));

            if ($positions === []) {
                continue; // no marker (defensive: regex above guarantees one)
            }

            $markerPos = (int) min($positions); // min(array) — spreading a single int fatals
            // Depth must be measured in CHARACTERS: `│` is 3 UTF-8 bytes, so a
            // byte offset would jump two levels per visual level and silently
            // orphan every nested node (breaking the OVERRIDES path keys).
            $charPos = mb_strlen(substr($line, 0, $markerPos), 'UTF-8');
            $depth = max(0, intdiv($charPos, 6));
            $label = trim($lm[1]);

            while (count($stack) > $depth) {
                array_pop($stack);
            }

            $parent = $depth > 0 ? ($stack[$depth - 1] ?? null) : null;
            $moduleCode = $module['code'];
            $moduleModel = $module['model'];
            $moduleName = $module['name'];

            $path = $parent === null
                ? [$moduleName, $label]
                : array_merge($parent['path'], [$label]);

            $pathKey = strtolower(implode(' > ', $path));
            $slug = $this->slug($label);
            $isAction = $this->isAction($label);

            // ---- code (stable unique key)
            $chain = array_merge(
                [$moduleCode],
                array_map(fn ($s) => $s['slug'], $stack),
                [$slug],
            );
            $code = implode('.', $chain);
            $code = $this->uniqueCode($code, $usedCodes);

            // ---- route
            $ownSegment = str_starts_with($slug, 'all_') ? substr($slug, 4) : $slug;
            $segments = [$moduleCode];
            foreach ($stack as $entry) {
                if ($entry['is_page']) {
                    $segments[] = $entry['route_segment'];
                }
            }
            if ($ownSegment !== end($segments)) {
                $segments[] = $ownSegment;
            }
            $derivedRoute = '/app/'.implode('/', $segments);

            $parentRoute = $parent['route'] ?? null;

            $fromOverride = false;

            if (isset(self::OVERRIDES[$pathKey])) {
                [$route, $permissionKey] = self::OVERRIDES[$pathKey];
                $fromOverride = true;

                // Action-labelled leaves normally collapse into their parent
                // page — but an explicit override that RESOLVES wins (e.g.
                // "Bulk Price Update" has its own screen). A dead override
                // still falls back to the parent so nothing regresses.
                if ($isAction && $parentRoute !== null && ! $this->routeExists($route, $uris, true)) {
                    $route = $parentRoute;
                    $fromOverride = false;
                }
            } elseif ($isAction) {
                $route = $parentRoute;
                $permissionKey = $code;
            } else {
                $route = $derivedRoute;
                $permissionKey = $code.'.view';
            }

            $active = $route !== null && $this->routeExists($route, $uris, $fromOverride);
            $stats['items']++;
            $stats[$active ? 'active' : 'planned']++;

            // ---- permission row (matrix: module/resource/action)
            $resource = count($stack) > 1
                ? implode('.', array_map(fn ($s) => $s['slug'], $stack))
                : $moduleCode;
            $actionName = $isAction ? $this->normalizeVerb($slug) : 'view';

            $permissionId = null;

            if ($permissionKey !== null) {
                $permissionId = $this->syncPermission(
                    $permissionKey,
                    $moduleCode,
                    $resource,
                    $actionName,
                    $label,
                    $stats,
                    $dryRun,
                );
            }

            /*
             * LOCATION classifies the entry, and the sidebar is curated from
             * it. Verb-first leaves ("Bulk Print Invoice", "Add Product",
             * "Session Opening") are ACTIONS that share their parent page's
             * route — rendering them as nav links is exactly how the sidebar
             * turned into a wall of near-identical links (§18.3). They keep
             * their permission row and route (so nothing becomes unreachable)
             * but they are no longer destinations: they surface through page
             * toolbars and the ⌘K palette.
             */
            $location = $isAction ? 'action' : 'sidebar';

            $row = $this->syncItem($code, [
                'module_id' => $moduleModel?->id,
                'parent_id' => $parent['id'] ?? null,
                'label' => $label,
                'label_key' => 'menu.'.$code,
                'route' => $route,
                'icon' => $parent === null ? (self::MODULE_ICONS[$moduleCode] ?? null) : null,
                'permission_id' => $permissionId,
                'location' => $location,
                'status' => $active ? 'active' : 'planned',
                'action' => $isAction ? $actionName : null,
                'feature_key' => $moduleCode,
                'sort' => (count($stack) + 1) * 10,
            ], $dryRun, $created, $usedCodes);

            $stack[] = [
                'slug' => $slug,
                'route_segment' => $ownSegment,
                'is_page' => ! $isAction,
                'route' => $route,
                'id' => $row['id'],
                'code' => $code,
                'path' => $path,
            ];

            $created[$code] = ['row' => $row, 'parent' => $parent['code'] ?? null, 'is_page' => ! $isAction];
        }

        if (! $dryRun) {
            $this->activateBranchNodes($created, $stats);
            $stats['pruned'] = $this->pruneStaleItems(array_keys($created));
        }

        return $stats;
    }

    /**
     * Drop sidebar rows whose codes this run did not (re)create — keeps the
     * registry exactly equal to the catalog when labels/nesting change.
     *
     * @param  array<int, string>  $touchedCodes
     */
    protected function pruneStaleItems(array $touchedCodes): int
    {
        return MenuItem::query()
            ->whereIn('location', ['sidebar', 'action'])
            ->whereNotIn('code', $touchedCodes)
            ->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Module header → module row + root menu group row.
     *
     * @return array{code:string,name:string,model:?object}
     */
    protected function syncModule(
        string $rawName,
        int $number,
        array &$stats,
        bool $dryRun,
        array $uris,
        array &$created,
        array &$usedCodes,
    ): array {
        $name = trim($rawName);
        $code = self::MODULE_CODES[strtoupper($name)] ?? $this->slug($name);
        $stats['modules']++;

        $moduleModel = null;

        if (! $dryRun) {
            $moduleModel = Module::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'label_key' => 'module.'.$code,
                    'icon' => self::MODULE_ICONS[$code] ?? null,
                    'sort' => $number * 10,
                    'is_active' => true,
                ],
            );
        }

        $route = $code === 'dashboard' ? '/app/dashboard' : null;
        $active = $route !== null && $this->routeExists($route, $uris);
        $stats['items']++;
        $stats[$active ? 'active' : 'planned']++;

        $row = $this->syncItem($code, [
            'module_id' => $moduleModel?->id,
            'parent_id' => null,
            'label' => $this->title($name),
            'label_key' => 'module.'.$code,
            'route' => $route,
            'icon' => self::MODULE_ICONS[$code] ?? null,
            'permission_id' => ($code === 'dashboard' && ! $dryRun)
                ? Permission::updateOrCreate(['key' => 'dashboard.view'], [
                    'module' => 'dashboard', 'resource' => 'dashboard', 'action' => 'view',
                    'label' => 'View dashboard', 'is_system' => true,
                ])->id
                : null,
            'location' => 'sidebar',
            'status' => $active ? 'active' : 'planned',
            'action' => null,
            'feature_key' => $code,
            'sort' => $number * 10,
        ], $dryRun, $created, $usedCodes);

        if ($code === 'dashboard') {
            $stats['permissions']++;
        }

        $created[$code] = ['row' => $row, 'parent' => null, 'is_page' => $route !== null];

        return ['code' => $code, 'name' => $name, 'model' => $moduleModel];
    }

    /**
     * @param  array<string, bool>  $uris
     * @return array{id:int}
     */
    protected function syncItem(string $code, array $attrs, bool $dryRun, array &$created, array &$usedCodes): array
    {
        if ($dryRun) {
            return ['id' => 0];
        }

        $item = MenuItem::updateOrCreate(['code' => $code], $attrs);

        return ['id' => $item->id];
    }

    protected function syncPermission(
        string $key,
        string $module,
        string $resource,
        string $action,
        string $label,
        array &$stats,
        bool $dryRun,
    ): ?int {
        $stats['permissions']++;

        if ($dryRun) {
            return null;
        }

        $permission = Permission::updateOrCreate(
            ['key' => $key],
            [
                'module' => $module,
                'resource' => $resource,
                'action' => $action,
                'label' => "Allow: {$label}",
                'is_system' => true,
            ],
        );

        return $permission->id;
    }

    /**
     * Bottom-up: a branch node becomes active only when at least one
     * descendant is active — no dead groups in the sidebar.
     *
     * Section pages carry derived routes (`/app/sales/Delivery`) that
     * never resolve; when their children activate they are promoted to
     * GROUPS (route nulled) so the sidebar renders the leaves without a
     * dead link. Stats are kept in sync with the final row states.
     *
     * @param  array<string, array{row:array, parent:?string, is_page:bool}>  $created
     */
    protected function activateBranchNodes(array $created, array &$stats): void
    {
        $children = [];

        foreach ($created as $code => $entry) {
            if ($entry['row']['id'] && ($parentId = MenuItem::query()->where('code', $code)->value('parent_id'))) {
                $children[$parentId][] = $code;
            }
        }

        // Compute active-ness recursively; promote dead-route sections.
        $resolve = function (string $code) use (&$resolve, &$created, &$stats, $children): bool {
            $row = $created[$code]['row'] ?? null;

            if (! $row || ! ($row['id'] ?? null)) {
                return false;
            }

            $item = MenuItem::query()->find($row['id']);

            if ($item === null) {
                return false;
            }

            $ownActive = $item->status === 'active';
            $anyChild = false;

            foreach ($children[$item->id] ?? [] as $childCode) {
                if ($resolve($childCode)) {
                    $anyChild = true;
                }
            }

            if ($item->route !== null) {
                if (! $ownActive && $anyChild) {
                    // Dead derived route + active children → group (no dead link).
                    $item->status = 'active';
                    $item->route = null;
                    $item->save();
                    $stats['active']++;
                    $stats['planned']--;
                }

                return $ownActive || $anyChild;
            }

            $target = $anyChild ? 'active' : 'planned';

            if ($item->status !== $target) {
                $item->status = $target;
                $item->save();
            }

            if ($target === 'active') {
                // Route-less rows are always counted planned above.
                $stats['active']++;
                $stats['planned']--;
            }

            return $anyChild;
        };

        foreach ($created as $code => $entry) {
            if (($entry['row']['id'] ?? null) && ($entry['parent'] ?? null) === null) {
                $resolve($code); // roots propagate downward
            }
        }
    }

    /** @return array<string, bool> '/app/...' => true for GET routes */
    protected function registeredUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $methods = $route->methods();

            if (in_array('GET', $methods, true) || in_array('HEAD', $methods, true)) {
                $uris['/'.$route->uri()] = true;
            }
        }

        return $uris;
    }

    /** Whether a catalogue route resolves to a real registered GET page. */
    public function hasRoute(?string $route): bool
    {
        return $this->routeExists($route, $this->registeredUris());
    }

    /**
     * Whether a catalogue route resolves to a real registered GET page.
     *
     * Explicit OVERRIDES may deep-link a parameterised route — the router
     * genuinely serves that page (e.g. /app/settings/couriers/pathao →
     * couriers.provider.show), so the link can never 404. Derived or
     * action-fallback guesses must still match a static URI exactly
     * (clarification C1), and /app/settings/{group} stays governed by its
     * config whitelist for every caller.
     */
    protected function routeExists(?string $route, array $uris, bool $allowParameterised = false): bool
    {
        if ($route === null) {
            return false;
        }

        // Fragment (#anchor) and query (?status=…) suffixes point at the
        // base route — the path must match a registered GET URI exactly.
        $path = explode('#', $route)[0];
        $path = explode('?', $path)[0];

        if (isset($uris[$path])) {
            return true;
        }

        /*
         * Parameterised foundation route: /app/settings/{group} pages exist
         * only for groups actually declared in config — an arbitrary
         * derived path like /app/settings/Company_Settings must stay
         * 'planned' (a 404 link may never render, clarification C1).
         */
        if (preg_match('#^/app/settings/([^/]+)$#', $path, $m)) {
            $groups = config('erp.settings.groups', []);

            return is_array($groups) && isset($groups[$m[1]]);
        }

        if ($allowParameterised) {
            foreach (array_keys($uris) as $template) {
                if (! str_contains($template, '{') || $template === '/app/settings/{group}') {
                    continue;
                }

                $pattern = '#^'.implode('[^/]+', array_map(
                    fn (string $segment) => preg_quote($segment, '#'),
                    preg_split('/\{[^}]+\}/', $template),
                )).'$#';

                if (preg_match($pattern, $path) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isAction(string $label): bool
    {
        $words = preg_split('/\s+/', trim($label), 2);
        $first = strtolower($words[0] ?? '');

        if (in_array($first, self::ACTION_VERBS, true)) {
            return true;
        }

        return in_array(strtolower($label), ['factory reset'], true);
    }

    protected function normalizeVerb(string $slug): string
    {
        $first = explode('_', $slug)[0];

        return match ($first) {
            'add', 'create' => 'create',
            'remove', 'delete' => 'delete',
            'edit', 'update' => 'update',
            'view' => 'view',
            'bulk' => 'bulk',
            default => $first,
        };
    }

    protected function slug(string $label): string
    {
        $label = str_replace(['&', "'", '’'], [' and ', '', ''], $label);
        $label = preg_replace('/[^a-zA-Z0-9]+/', '_', $label);

        return trim((string) $label, '_');
    }

    protected function title(string $upper): string
    {
        if (str_contains($upper, '&')) {
            $parts = array_map('ucfirst', explode(' ', strtolower($upper)));

            return implode(' ', $parts);
        }

        return ucwords(strtolower($upper));
    }

    protected function uniqueCode(string $code, array &$used): string
    {
        if (! isset($used[$code])) {
            $used[$code] = 1;

            return $code;
        }

        $used[$code]++;

        return $code.'_'.$used[$code];
    }
}
