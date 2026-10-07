<?php

namespace Database\Seeders;

use App\Domain\Foundation\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Foundation permission matrix (Rule 6): system resources that are not
 * part of the module menu catalog (users, roles, approvals, audit,
 * documents, portals…). Menu-derived permissions are created by
 * NavigationSeeder/CatalogImporter; this seeder completes the matrix and
 * normalises labels for the exportable matrix view.
 *
 * Every key referenced by routes, policies and middleware exists as a
 * row — no permission check ever depends on a string that is absent
 * from this table.
 */
class FoundationPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $rows = [
            // module, resource, action, key, label
            ['dashboard', 'dashboard', 'view', 'dashboard.view', 'View dashboard'],

            ['settings', 'users', 'view', 'users.view', 'View users'],
            ['settings', 'users', 'create', 'users.create', 'Create users'],
            ['settings', 'users', 'update', 'users.update', 'Edit users'],
            ['settings', 'users', 'delete', 'users.delete', 'Delete users'],

            ['employee', 'employees', 'view', 'employees.view', 'View employees'],
            ['employee', 'employees', 'create', 'employees.create', 'Create employees'],
            ['employee', 'employees', 'edit', 'employees.edit', 'Edit employees'],
            ['employee', 'employees', 'delete', 'employees.delete', 'Delete employees'],

            ['masters', 'masters', 'view', 'masters.view', 'View geo masters'],
            ['masters', 'masters', 'manage', 'masters.manage', 'Manage master data'],
            ['masters', 'tax', 'manage', 'tax.manage', 'Manage tax rates'],

            // Phase F — party / price-list masters
            ['masters', 'customers', 'manage', 'customers.manage', 'Manage customer masters'],

            // Customers / CRM (§05) — the commercial relationship, not just a master row
            ['customers', 'customers', 'view', 'customers.view', 'View customers & CRM'],
            ['customers', 'customers', 'create', 'customers.create', 'Add customers'],
            ['customers', 'customers', 'edit', 'customers.edit', 'Edit customers, addresses & contacts'],
            ['customers', 'customers', 'delete', 'customers.delete', 'Delete customers'],
            ['customers', 'customers', 'groups', 'customers.groups', 'Manage customer groups & discounts'],
            ['customers', 'customers', 'credit_limit', 'customers.credit_limit', 'Set credit limits'],
            ['customers', 'customers', 'blacklist', 'customers.blacklist', 'Blacklist / restore customers'],
            ['customers', 'customers', 'due_view', 'customers.due.view', 'View customer dues & ageing'],
            ['customers', 'customers', 'feedback', 'customers.feedback', 'Record customer feedback'],
            ['customers', 'customers', 'referrals', 'customers.referrals', 'Record referrals'],
            ['customers', 'customers', 'export', 'customers.export', 'Export customers'],
            ['accounting', 'ledger', 'view', 'accounting.ledger.view', 'View customer / account ledgers'],
            ['masters', 'suppliers', 'manage', 'suppliers.manage', 'Manage supplier masters'],
            ['masters', 'price_lists', 'view', 'pricing.view', 'View price history'],
            ['masters', 'price_lists', 'manage', 'pricing.manage', 'Manage price lists'],
            ['masters', 'price_lists', 'bulk_update', 'pricing.bulk_update', 'Bulk update prices'],
            ['masters', 'price_lists', 'rules', 'pricing.rules', 'Manage pricing rules'],
            ['masters', 'price_lists', 'override', 'pricing.override', 'Override pricing rules without approval'],

            ['settings', 'company', 'manage', 'settings.company', 'Edit company profile'],
            ['settings', 'company', 'manage', 'company.manage', 'Edit company profile (alias)'],

            ['settings', 'roles', 'view', 'roles.view', 'View roles & permissions'],
            ['settings', 'roles', 'create', 'roles.create', 'Create roles'],
            ['settings', 'roles', 'update', 'roles.update', 'Edit roles'],
            ['settings', 'roles', 'delete', 'roles.delete', 'Delete roles'],

            ['settings', 'branches', 'view', 'branches.view', 'View branches'],
            ['settings', 'branches', 'create', 'branches.create', 'Create branches'],
            ['settings', 'branches', 'update', 'branches.update', 'Edit branches'],
            ['settings', 'branches', 'delete', 'branches.delete', 'Delete branches'],
            ['settings', 'branches', 'compare', 'branches.compare', 'Compare branches'],

            ['inventory', 'warehouses', 'view', 'warehouses.view', 'View warehouses'],
            ['inventory', 'warehouses', 'create', 'warehouses.create', 'Create warehouses'],
            ['inventory', 'warehouses', 'update', 'warehouses.update', 'Edit warehouses'],
            ['inventory', 'warehouses', 'delete', 'warehouses.delete', 'Delete warehouses'],

            ['settings', 'settings', 'view', 'settings.view', 'View settings'],
            ['settings', 'settings', 'update', 'settings.update', 'Change settings'],
            ['settings', 'settings', 'reset', 'settings.reset', 'Reset settings to defaults'],

            ['settings', 'menus', 'view', 'menus.view', 'View navigation registry'],
            ['settings', 'menus', 'manage', 'menus.manage', 'Manage navigation entries'],

            ['settings', 'workflows', 'view', 'workflows.view', 'View approval workflows'],
            ['settings', 'workflows', 'manage', 'workflows.manage', 'Manage approval workflows'],

            ['settings', 'approvals', 'view', 'approvals.view', 'Open approval inbox'],
            ['settings', 'approvals', 'decide', 'approvals.decide', 'Approve / reject / return requests'],
            ['settings', 'approvals', 'comment', 'approvals.comment', 'Comment on approval requests'],
            ['settings', 'approvals', 'cancel', 'approvals.cancel', 'Cancel approval requests'],

            ['settings', 'audit', 'view', 'audit.view', 'View audit log'],
            ['settings', 'audit', 'export', 'audit.export', 'Export audit log'],

            ['business_management', 'documents', 'view', 'documents.view', 'View documents'],
            ['business_management', 'documents', 'upload', 'documents.upload', 'Upload documents'],
            ['business_management', 'documents', 'download', 'documents.download', 'Download documents'],
            ['business_management', 'documents', 'manage', 'documents.manage', 'Manage (replace/delete) documents'],

            ['settings', 'notifications', 'view', 'notifications.view', 'View notifications'],
            ['settings', 'security', 'view', 'security.alerts.view', 'View security alerts'],

            ['settings', 'maintenance', 'index', 'maintenance.index', 'Rebuild search index / run maintenance'],
            ['settings', 'search', 'view', 'search.view', 'Use global search'],

            ['system', 'portal', 'access', 'portal.erp.access', 'Access the ERP portal'],
            ['system', 'portal', 'access', 'portal.technician.access', 'Access the technician portal'],
            ['system', 'portal', 'access', 'portal.supplier.access', 'Access the supplier portal'],

            ['settings', 'company', 'manage', 'settings.company', 'Edit company profile'],
            ['settings', 'company', 'manage', 'company.manage', 'Edit company profile (alias)'],

            ['masters', 'masters', 'view', 'masters.view', 'View master data (geo)'],
            ['masters', 'masters', 'manage', 'masters.manage', 'Manage master data'],
            ['masters', 'tax_rates', 'manage', 'tax.manage', 'Manage tax rates'],
            ['masters', 'customers', 'manage', 'customers.manage', 'Manage customer masters'],
            ['masters', 'suppliers', 'manage', 'suppliers.manage', 'Manage supplier masters'],
            ['masters', 'price_lists', 'manage', 'pricing.manage', 'Manage price lists'],

            ['hr', 'employees', 'view', 'employees.view', 'View employees'],
            ['hr', 'employees', 'create', 'employees.create', 'Create employees'],
            ['hr', 'employees', 'edit', 'employees.edit', 'Edit employees'],

            // Phase H — HRM (§10): attendance, leave, structure
            ['hr', 'attendance', 'view', 'attendance.view', 'View attendance'],
            ['hr', 'attendance', 'manage', 'attendance.manage', 'Mark and correct attendance'],
            ['hr', 'attendance', 'report', 'attendance.report', 'View attendance reports'],
            ['hr', 'leave', 'view', 'leave.view', 'View leave requests'],
            ['hr', 'leave', 'request', 'leave.request', 'Request leave'],
            ['hr', 'leave', 'approve', 'leave.approve', 'Approve or reject leave'],
            ['hr', 'leave', 'balance', 'leave.balance', 'View and adjust leave balances'],
            ['hr', 'structure', 'manage', 'hr.structure.manage', 'Manage departments and designations'],
            ['hr', 'leave_types', 'manage', 'hr.leave_types.manage', 'Manage leave types'],

            // Phase I — purchasing (§03) and supplier parties (§06)
            ['purchase', 'suppliers', 'view', 'suppliers.view', 'View suppliers'],
            ['purchase', 'suppliers', 'create', 'suppliers.create', 'Create suppliers'],
            ['purchase', 'suppliers', 'edit', 'suppliers.edit', 'Edit suppliers'],
            ['purchase', 'suppliers', 'blacklist', 'suppliers.blacklist', 'Blacklist and reinstate suppliers'],
            ['purchase', 'orders', 'view', 'purchase.orders.view', 'View purchase orders'],
            ['purchase', 'orders', 'create', 'purchase.orders.create', 'Raise purchase orders'],
            ['purchase', 'orders', 'approve', 'purchase.orders.approve', 'Approve purchase orders'],
            ['purchase', 'orders', 'cancel', 'purchase.orders.cancel', 'Cancel purchase orders'],
            ['purchase', 'receipts', 'view', 'purchase.receipts.view', 'View goods receipts'],
            ['purchase', 'receipts', 'create', 'purchase.receipts.create', 'Draft goods receipts'],
            ['purchase', 'receipts', 'post', 'purchase.receipts.post', 'Post goods receipts to stock'],
            ['purchase', 'receipts', 'cancel', 'purchase.receipts.cancel', 'Cancel draft goods receipts'],

            ['accounting', 'fiscal_years', 'manage', 'fiscal.manage', 'Manage fiscal years'],

            // Phase D — accounting core (09-03…09-10, 09-32)
            ['accounting', 'coa', 'view', 'accounting.coa.view', 'View chart of accounts'],
            ['accounting', 'coa', 'manage', 'accounting.coa.manage', 'Manage chart of accounts'],
            ['accounting', 'journals', 'view', 'accounting.journals.view', 'View journal entries'],
            ['accounting', 'journals', 'create', 'accounting.journals.create', 'Create manual journals'],
            ['accounting', 'journals', 'reverse', 'accounting.journals.reverse', 'Reverse journal entries'],
            ['accounting', 'journals', 'approve', 'accounting.journals.approve', 'Approve journal entries'],
            ['accounting', 'reports', 'view', 'accounting.reports.view', 'View financial reports'],
            ['accounting', 'opening', 'create', 'accounting.opening.create', 'Post opening balances'],
            ['accounting', 'receipts', 'view', 'accounting.receipts.view', 'View receipts'],
            ['accounting', 'receipts', 'create', 'accounting.receipts.create', 'Record receipts'],
            ['accounting', 'payments', 'view', 'accounting.payments.view', 'View payments'],
            ['accounting', 'payments', 'create', 'accounting.payments.create', 'Record payments'],
            ['accounting', 'ar', 'view', 'accounting.ar.view', 'View accounts receivable'],
            ['accounting', 'ap', 'view', 'accounting.ap.view', 'View accounts payable'],
            ['accounting', 'fiscal', 'close', 'accounting.fiscal.close', 'Close fiscal periods'],

            // Phase E — inventory core (04-01…04-33)
            ['inventory', 'products', 'view', 'inventory.products.view', 'View products'],
            ['inventory', 'products', 'create', 'inventory.products.create', 'Create products'],
            ['inventory', 'products', 'edit', 'inventory.products.edit', 'Edit products'],
            ['inventory', 'stock', 'view', 'inventory.stock.view', 'View stock overview'],
            ['inventory', 'adjustments', 'view', 'inventory.adjustments.view', 'View stock adjustments'],
            ['inventory', 'adjustments', 'create', 'inventory.adjustments.create', 'Create stock adjustments / opening'],
            ['inventory', 'adjustments', 'approve', 'inventory.adjustments.approve', 'Approve stock adjustments'],
            ['inventory', 'transfers', 'create', 'inventory.transfers.create', 'Create stock transfers'],
            ['inventory', 'transfers', 'dispatch', 'inventory.transfers.dispatch', 'Dispatch stock transfers'],
            ['inventory', 'transfers', 'receive', 'inventory.transfers.receive', 'Receive stock transfers'],
            ['inventory', 'ledger', 'view', 'inventory.ledger.view', 'View stock movement ledger'],
            ['inventory', 'valuation', 'view', 'inventory.valuation.view', 'View stock valuation'],
            ['inventory', 'reports', 'view', 'inventory.reports.view', 'View inventory reports'],
            ['inventory', 'counts', 'create', 'inventory.counts.create', 'Create cycle counts'],
            ['inventory', 'writeoffs', 'create', 'inventory.writeoffs.create', 'Create write-offs'],
            ['inventory', 'writeoffs', 'approve', 'inventory.writeoffs.approve', 'Approve write-offs'],
            ['inventory', 'damage', 'create', 'inventory.damage.create', 'Record damage'],
            ['inventory', 'loss', 'create', 'inventory.loss.create', 'Record loss'],
            ['inventory', 'reorder', 'view', 'inventory.reorder.view', 'View reorder alerts'],
            ['inventory', 'reorder', 'configure', 'inventory.reorder.configure', 'Configure reorder policies'],
            ['inventory', 'batch', 'view', 'inventory.batch.view', 'View batches'],
            ['inventory', 'serial', 'view', 'inventory.serial.view', 'View serials'],
            ['inventory', 'reservations', 'view', 'inventory.reservations.view', 'View stock reservations'],
            ['inventory', 'labels', 'manage', 'inventory.labels', 'Generate and print labels'],
            ['inventory', 'configure', 'manage', 'inventory.configure', 'Configure inventory settings'],

            // Phase G — sales core (02-01…02-70)
            ['sales', 'quotations', 'view', 'sales.quotations.view', 'View quotations'],
            ['sales', 'quotations', 'create', 'sales.quotations.create', 'Create quotations'],
            ['sales', 'quotations', 'revise', 'sales.quotations.revise', 'Revise quotations'],
            ['sales', 'quotations', 'convert', 'sales.quotations.convert', 'Convert quotations to orders'],
            ['sales', 'quotations', 'process', 'sales.quotations.process', 'Accept or decline quotations'],
            ['sales', 'quotations', 'send', 'sales.quotations.send', 'Send quotations'],
            ['sales', 'orders', 'view', 'sales.orders.view', 'View sales orders'],
            ['sales', 'orders', 'create', 'sales.orders.create', 'Create sales orders'],
            ['sales', 'orders', 'edit', 'sales.orders.edit', 'Edit sales orders'],
            ['sales', 'orders', 'confirm', 'sales.orders.confirm', 'Confirm sales orders'],
            ['sales', 'orders', 'cancel', 'sales.orders.cancel', 'Cancel sales orders'],
            ['sales', 'orders', 'print', 'sales.orders.print', 'Print packing slips'],
            ['sales', 'orders', 'notify', 'sales.orders.notify', 'Send customer notifications'],
            ['sales', 'orders', 'export', 'sales.orders.export', 'Export sales orders'],
            ['sales', 'orders', 'review', 'sales.orders.review', 'Review suspicious orders'],
            ['sales', 'invoices', 'view', 'sales.invoices.view', 'View invoices'],
            ['sales', 'invoices', 'create', 'sales.invoices.create', 'Create invoices'],
            ['sales', 'invoices', 'issue', 'sales.invoices.issue', 'Issue invoices'],
            ['sales', 'invoices', 'print', 'sales.invoices.print', 'Print invoices'],
            ['sales', 'invoices', 'statutory_print', 'sales.invoices.statutory_print', 'Print statutory tax documents'],
            ['sales', 'payments', 'view', 'sales.payments.view', 'View money receipts'],
            ['sales', 'payments', 'create', 'sales.payments.create', 'Record money receipts'],
            ['sales', 'delivery', 'view', 'sales.delivery.view', 'View delivery challans'],
            ['sales', 'delivery', 'create', 'sales.delivery.create', 'Create delivery challans'],
            ['sales', 'delivery', 'dispatch', 'sales.delivery.dispatch', 'Dispatch and deliver challans'],
            ['sales', 'delivery', 'assign', 'sales.delivery.assign', 'Assign couriers to orders'],
            ['sales', 'delivery', 'print', 'sales.delivery.print', 'Print shipping labels'],
            ['sales', 'delivery', 'zones', 'sales.delivery.zones', 'Manage delivery zones and charges'],
            ['sales', 'delivery', 'shipments', 'sales.delivery.shipments', 'View shipments'],
            ['sales', 'delivery', 'shipments_create', 'sales.delivery.shipments.create', 'Create and dispatch shipments'],
            ['sales', 'delivery', 'configure', 'sales.delivery.configure', 'Configure couriers and integrations'],
            ['sales', 'delivery', 'riders', 'sales.delivery.riders', 'Manage own delivery riders'],
            ['sales', 'delivery', 'routes', 'sales.delivery.routes', 'Plan delivery routes'],
            ['sales', 'delivery', 'pod', 'sales.delivery.pod', 'Record proof of delivery'],
            ['sales', 'delivery', 'cod', 'sales.delivery.cod', 'View COD collections and reconciliation'],
            ['sales', 'delivery', 'cod_reconcile', 'sales.delivery.cod.reconcile', 'Reconcile rider COD remittances'],
            ['sales', 'delivery', 'packaging', 'sales.delivery.packaging', 'Manage packaging consumption'],
            ['sales', 'coupons', 'view', 'sales.coupons.view', 'View coupons'],
            ['sales', 'coupons', 'create', 'sales.coupons.create', 'Create coupons'],
            ['sales', 'coupons', 'bulk', 'sales.coupons.bulk', 'Bulk generate coupons'],
            ['sales', 'promotions', 'view', 'sales.promotions.view', 'View promotions'],
            ['sales', 'promotions', 'create', 'sales.promotions.create', 'Create promotions'],
            ['sales', 'reports', 'view', 'sales.reports.view', 'View sales reports'],
            ['sales', 'team', 'view', 'sales.team.view', 'View sales team'],
            ['sales', 'team', 'create', 'sales.team.create', 'Manage sales persons'],
            ['sales', 'team', 'targets', 'sales.team.targets', 'Manage sales targets'],
            ['sales', 'team', 'commissions', 'sales.team.commissions', 'View sales commissions'],
            ['sales', 'team', 'commission_pay', 'sales.team.commission_pay', 'Pay sales commissions'],
            ['sales', 'team', 'calls', 'sales.team.calls', 'Manage sales call log'],
            ['sales', 'team', 'field_tracking', 'sales.team.field_tracking', 'Manage field visits and GPS'],
            ['sales', 'team', 'territories', 'sales.team.territories', 'Manage sales territories'],
            ['returns', 'sales', 'view', 'returns.view', 'View sales returns'],
            ['returns', 'sales', 'create', 'returns.create', 'Create sales returns'],
            ['returns', 'sales', 'receive', 'returns.receive', 'Receive sales returns'],
            ['returns', 'sales', 'credit', 'returns.credit', 'Issue credit notes'],
            ['returns', 'refunds', 'view', 'returns.refunds.view', 'View refunds'],
            ['returns', 'refunds', 'create', 'returns.refunds.create', 'Process refunds'],
            ['pos', 'sell', 'sell', 'pos.sell', 'Use POS terminal'],
            ['pos', 'sessions', 'view', 'pos.sessions.view', 'View POS sessions'],
            ['pos', 'sessions', 'open', 'pos.sessions.open', 'Open POS sessions'],
            ['pos', 'sessions', 'close', 'pos.sessions.close', 'Close POS sessions'],
            ['pos', 'reports', 'view', 'pos.reports.view', 'View POS X/Z reports'],
            ['pos', 'reports', 'x', 'pos.reports.x', 'View POS X report'],
            ['pos', 'reports', 'z', 'pos.reports.z', 'View POS Z report'],
            ['pos', 'hold', 'sell', 'pos.hold', 'Hold and resume POS carts'],
            ['pos', 'offline', 'sell', 'pos.offline', 'Use POS offline mode'],
            ['pos', 'price_check', 'sell', 'pos.price_check', 'Check product prices'],
            ['pos', 'returns', 'create', 'pos.returns.create', 'Process POS counter returns'],
            ['pos', 'returns', 'exchange', 'pos.returns.exchange', 'Process POS counter exchanges'],
            ['pos', 'layaway', 'create', 'pos.layaway', 'Create POS layaway deposits'],
            ['pos', 'cash_drawer', 'view', 'pos.cash_drawer', 'View POS cash drawer'],
            ['pos', 'cash_io', 'create', 'pos.cash_io', 'Record POS cash in/out'],
            ['pos', 'customer_display', 'view', 'pos.customer_display', 'Use POS customer display'],
            ['pos', 'settings', 'configure', 'pos.settings.configure', 'Configure POS settings'],
        ];

        foreach ($rows as [$module, $resource, $action, $key, $label]) {
            Permission::updateOrCreate(
                ['key' => $key],
                [
                    'module' => $module,
                    'resource' => $resource,
                    'action' => $action,
                    'label' => $label,
                    'is_system' => true,
                ],
            );
        }
    }
}
