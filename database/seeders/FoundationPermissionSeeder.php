<?php

namespace Database\Seeders;

use App\Domain\Foundation\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

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
            //
            // One key, one row. `updateOrCreate` is keyed by the permission key,
            // so a second row for the same key silently overwrites the first
            // one's resource and label — the matrix then lists a permission whose
            // name does not describe the screen it opens. This catalogue has
            // carried `tax.manage`, `masters.view`, `maintenance.index` and seven
            // more twice (some of them with different labels), so a duplicate is
            // refused here instead of being settled by whichever row came last.
            ['dashboard', 'dashboard', 'view', 'dashboard.view', 'View dashboard'],

            ['settings', 'users', 'view', 'users.view', 'View users'],
            ['settings', 'users', 'create', 'users.create', 'Create users'],
            ['settings', 'users', 'update', 'users.update', 'Edit users'],
            ['settings', 'users', 'delete', 'users.delete', 'Delete users'],

            ['employee', 'employees', 'view', 'employees.view', 'View employees'],
            ['employee', 'employees', 'create', 'employees.create', 'Create employees'],
            ['employee', 'employees', 'edit', 'employees.edit', 'Edit employees'],
            ['employee', 'employees', 'delete', 'employees.delete', 'Delete employees'],

            ['masters', 'masters', 'view', 'masters.view', 'View master data (geo)'],
            ['masters', 'masters', 'manage', 'masters.manage', 'Manage master data'],
            ['masters', 'tax_rates', 'manage', 'tax.manage', 'Manage tax rates'],

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

            // settings.company is declared once, above. The alias key below is
            // the older name of the same permission, kept for tenants that still
            // hold it.
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
            // §15-01…§15-17: the settings desk is one page per group and the
            // groups are different jobs. `settings.view` opens the module
            // (the index lists every group either way), `settings.<group>` opens
            // that group's numbers, and `settings.update` is what actually
            // writes. A person who may set the company's document numbering has
            // no business rewriting the password policy, and one key for both
            // would have said they did.
            ['settings', 'general', 'manage', 'settings.general', 'Set formats, decimal places and the landing page'],
            ['settings', 'localization', 'manage', 'settings.localization', 'Set language, Bengali numerals, amount in words and lakh/crore grouping'],
            ['settings', 'security', 'manage', 'settings.security', 'Set the password, lockout and session policy'],
            ['settings', 'notifications', 'manage', 'settings.notifications', 'Decide which channels notify whom'],
            ['settings', 'workflow', 'manage', 'settings.workflow', 'Set approval SLAs, escalation and self-approval rules'],
            ['settings', 'numbering', 'manage', 'settings.numbering', 'Set how documents are numbered'],
            ['settings', 'dashboard', 'manage', 'settings.dashboard', 'Set dashboard refresh behaviour'],
            ['settings', 'audit', 'manage', 'settings.audit', 'Set how long audit evidence is kept and whether it may be exported'],
            ['settings', 'cash', 'manage', 'settings.cash', 'Set the cash approval limits and count tolerance'],
            ['settings', 'inventory', 'manage', 'settings.inventory', 'Set the stock valuation method, approval gates and stock switches'],
            ['settings', 'reorder', 'manage', 'settings.reorder', 'Set how demand is measured for reorder suggestions'],
            ['settings', 'labels', 'manage', 'settings.labels', 'Set label sheet geometry and QR density'],
            ['settings', 'barcode', 'manage', 'settings.barcode', 'Set scanner input and barcode symbology'],
            ['settings', 'appearance', 'manage', 'settings.appearance', 'Set the accent colour, density and sidebar behaviour'],
            ['settings', 'branches', 'manage', 'settings.branch', 'Set what a branch may decide for itself, and remove an override'],

            ['settings', 'settings', 'update', 'settings.update', 'Change settings'],
            ['settings', 'settings', 'reset', 'settings.reset', 'Reset settings to defaults'],

            ['settings', 'menus', 'view', 'menus.view', 'View navigation registry'],
            ['settings', 'menus', 'manage', 'menus.manage', 'Manage navigation entries'],

            ['settings', 'workflows', 'view', 'workflows.view', 'View approval workflows'],
            ['settings', 'workflows', 'manage', 'workflows.manage', 'Manage approval workflows'],

            // §15-23…§15-33 — the maintenance desk. One key per kind of work:
            // an operator who may clear a cache is not automatically somebody
            // who may repair a table or reset the company's settings.
            ['maintenance', 'desk', 'index', 'maintenance.index', 'Open the maintenance desk and rebuild the search index'],
            ['maintenance', 'cache', 'clear', 'maintenance.cache', 'Clear cache and compiled templates'],
            ['maintenance', 'sessions', 'clear', 'maintenance.sessions', 'End other people’s sessions'],
            ['maintenance', 'temp', 'clear', 'maintenance.temp', 'Remove temporary files'],
            ['maintenance', 'database', 'optimize', 'maintenance.database', 'Optimise the database and run integrity checks'],
            ['maintenance', 'database', 'repair', 'maintenance.repair', 'Repair database tables'],
            ['maintenance', 'logs', 'view', 'maintenance.logs', 'Read the error log'],
            ['maintenance', 'heal', 'run', 'maintenance.heal', 'Run a self-healing pass'],
            ['maintenance', 'settings', 'reset', 'maintenance.reset', 'Reset settings to their defaults'],

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

            ['settings', 'search', 'view', 'search.view', 'Use global search'],

            ['system', 'portal', 'access', 'portal.erp.access', 'Access the ERP portal'],
            ['system', 'portal', 'access', 'portal.technician.access', 'Access the technician portal'],
            ['system', 'portal', 'access', 'portal.supplier.access', 'Access the supplier portal'],

            // Phase N — §12 Business Management (§12-12 notice board, §12-13 tasks & projects)
            ['business', 'notices', 'view', 'business.notices.view', 'Read the notice board'],
            ['business', 'notices', 'create', 'business.notices.create', 'Write, publish and archive notices'],
            ['business', 'tasks', 'view_own', 'tasks.view_own', 'See your own tasks'],
            ['business', 'tasks', 'view_all', 'tasks.view_all', 'See everybody’s tasks'],
            ['business', 'tasks', 'manage', 'tasks.manage', 'Create, assign, move and comment on tasks and projects'],

            // §12-03/04/09/10: the company's registers — licence, TIN & BIN,
            // certificates, contracts, agreements, brand assets, insurance,
            // RJSC filings and the statutory calendar. Reading them is an
            // office job; writing one, renewing one or retiring one is the
            // manager's, because a register is only evidence if the people
            // who keep it are accountable for what it says.
            ['business', 'records', 'view', 'business.records.view', 'Read the company registers — licences, contracts, insurance, compliance'],
            ['business', 'records', 'manage', 'business.records.manage', 'Record, renew, file, attach and retire entries in the company registers'],

            // §12-11: a meeting is the office talking to itself, and the minutes
            // are the only part of it that survives. Reading your own meetings is
            // everybody's; calling one, minuting it and raising work out of it is
            // the person who runs the diary.
            ['business', 'meetings', 'view', 'business.meetings.view', 'See the meetings you are on, their minutes and your action items'],
            ['business', 'meetings', 'manage', 'business.meetings.manage', 'Call, move, cancel, hold and minute meetings, and raise action items from them'],

            ['settings', 'company', 'manage', 'settings.company', 'Edit company profile'],

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
            ['purchase', 'bills', 'view', 'purchase.bills.view', 'View purchase bills'],
            ['purchase', 'bills', 'create', 'purchase.bills.create', 'Enter purchase bills'],
            ['purchase', 'bills', 'approve', 'purchase.bills.approve', 'Approve purchase bills (posts the payable)'],
            ['purchase', 'bills', 'cancel', 'purchase.bills.cancel', 'Cancel an unposted purchase bill'],
            ['purchase', 'payments', 'view', 'purchase.payments.view', 'View supplier payments'],
            ['purchase', 'payments', 'create', 'purchase.payments.create', 'Record a supplier payment'],
            ['purchase', 'returns', 'view', 'purchase.returns.view', 'View purchase returns and debit notes'],
            ['purchase', 'returns', 'create', 'purchase.returns.create', 'Raise a purchase return'],
            ['purchase', 'returns', 'approve', 'purchase.returns.approve', 'Approve a purchase return and its debit note'],
            ['purchase', 'returns', 'cancel', 'purchase.returns.cancel', 'Cancel an unposted purchase return'],

            ['accounting', 'fiscal_years', 'manage', 'fiscal.manage', 'Manage fiscal years'],

            // Phase D — accounting core (09-03…09-10, 09-32)
            ['accounting', 'coa', 'view', 'accounting.coa.view', 'View chart of accounts'],
            ['accounting', 'coa', 'manage', 'accounting.coa.manage', 'Manage chart of accounts'],
            ['accounting', 'journals', 'view', 'accounting.journals.view', 'View journal entries'],
            ['accounting', 'journals', 'create', 'accounting.journals.create', 'Create manual journals'],
            ['accounting', 'journals', 'reverse', 'accounting.journals.reverse', 'Reverse journal entries'],
            ['accounting', 'journals', 'approve', 'accounting.journals.approve', 'Approve journal entries'],
            ['accounting', 'reports', 'view', 'accounting.reports.view', 'View financial reports'],
            // ---- Cash & bank (§08): the desk where money the ledger has to see is
            // entered. Reading a position and moving money are different powers on
            // purpose — a cashier who may take money in does not thereby get to
            // pay it out, and neither of them may open a bank account.
            ['cash', 'position', 'view', 'cash.view', 'See where the money is: cash, bank and wallet positions'],
            ['cash', 'receipts', 'create', 'cash.receipts.create', 'Record money received'],
            ['cash', 'payments', 'create', 'cash.payments.create', 'Record money paid out'],
            ['cash', 'transfers', 'create', 'cash.transfers', "Move money between the company's own accounts"],
            // §08-05: counting a drawer and answering for what is missing from it
            // are two different jobs. Anybody trusted with the till can count it;
            // writing off a difference at or above the tolerance is somebody
            // else's signature, which is the whole reason a big gap waits.
            ['cash', 'counts', 'create', 'cash.counts', 'Count a cash drawer against the books'],
            ['cash', 'counts', 'decide', 'cash.counts.approve', 'Approve or refuse a counted difference at or above the tolerance'],
            ['bank', 'accounts', 'manage', 'bank.accounts', 'Declare, rename and close cash and bank accounts'],
            ['bank', 'books', 'view', 'bank.view', "Read an account's book, the way a statement is read"],
            ['wallets', 'accounts', 'manage', 'wallets.accounts', 'Open and configure mobile wallets'],
            // Reading a book and proving it against the bank are different
            // jobs: the second one is the signature at the bottom of the page.
            ['bank', 'reconciliations', 'manage', 'bank.reconcile', 'Reconcile a bank account against its own statement'],
            // §08-10: recording a bank charge is bookkeeping — the bank already
            // took the money. Writing the rule that decides what will be charged
            // automatically from now on is policy, and it is the act that can
            // quietly move money every quarter for years, so it is its own key.
            ['bank', 'charges', 'create', 'bank.charges', 'Record the charges a bank takes and reverse one that was wrong'],
            ['bank', 'charge_rules', 'manage', 'bank.charges.rules', 'Write the standing rules that post bank charges automatically'],
            ['wallets', 'reconciliations', 'manage', 'wallets.reconcile', 'Reconcile a mobile wallet against a statement the provider exported'],
            // §08-13: writing a cheque into the register is a clerk's job;
            // saying the bank has paid it is the moment the ledger moves, and
            // that is a different hand — the same split as reading a book and
            // signing off a reconciliation. Printing an issued cheque is a
            // third: it puts the company's figures on paper a bank will read.
            ['cheques', 'register', 'view', 'cheques.view', 'Read the cheque register'],
            ['cheques', 'register', 'manage', 'cheques.manage', 'Write cheques into the register'],
            ['cheques', 'register', 'clear', 'cheques.clear', 'Say a cheque cleared or failed — the moment it reaches the ledger'],
            ['cheques', 'register', 'print', 'cheques.print', 'Print the record of a cheque the company issued'],
            // §08-15…§08-18: an expense, the signature that lets a large one
            // post, and the mapping that decides which account the whole
            // expense report is built from. They are four jobs: reading the
            // register is a manager's, recording an expense is a clerk's,
            // approving one belongs to whoever answers for the money, and
            // re-pointing a category moves every future figure in the report.
            ['expenses', 'register', 'view', 'expenses.view', 'Read the expense register'],
            ['expenses', 'register', 'create', 'expenses.create', 'Record an expense'],
            ['expenses', 'approval', 'decide', 'expenses.approve', 'Approve, refuse or reverse an expense — the moments the ledger moves'],
            ['expenses', 'categories', 'manage', 'expenses.categories', 'Decide which ledger account an expense category books to'],
            // §08-19: a schedule is a standing instruction to spend, so it is
            // its own key rather than part of recording one expense. Generating
            // what is due additionally needs expenses.create (the route says so),
            // because automation must not be able to record what its operator
            // could not type.
            ['expenses', 'recurring', 'manage', 'expenses.recurring', 'Schedule the expenses that come round again and generate what is due'],
            // §08-21: the float in the drawer. Four keys rather than one,
            // because the people are different people. `pettycash.funds` declares
            // the float itself, which is a chart-of-accounts act rather than a
            // spending one; `pettycash.spend` is the custodian paying vouchers and
            // reading the register; `pettycash.replenish` puts money back into the
            // tin; `pettycash.approve` is the signature a voucher at or above the
            // limit waits for. The service refuses a request decided by the person
            // who asked, so holding both keys is not a way round it.
            // §08-20/§08-22: reading what the company spent and reading where
            // its money actually is are two different audiences, so they are two
            // keys. Both are read-only — nothing on the report screens moves
            // money — which is why neither of them is a corner of the desks that
            // can. Every figure on them is a posted journal line.
            ['expenses', 'reports', 'view', 'expenses.reports', 'Read the expense report: what the company spent, by category, branch and month'],
            ['cash', 'reports', 'view', 'cash.reports', 'Read the cash reports: the cash book, the bank book, the cash flow and the till variances'],
            // §13: reporting is an audience question, so the centre has a key
            // per family rather than one “reports” switch. The floor key opens
            // the index only, where every family is listed — including the ones
            // the reader cannot open, with the key each one needs. Hiding a
            // report somebody does not hold is how a manager ends up asking for
            // a screen the company already has.
            ['reports', 'centre', 'view', 'reports.view', 'See the report centre: every family the catalogue names, with what each one holds'],
            ['reports', 'sales', 'view', 'reports.sales', 'Read the sales report family'],
            ['reports', 'purchase', 'view', 'reports.purchase', 'Read the purchase report family'],
            ['reports', 'inventory', 'view', 'reports.inventory', 'Read the inventory report family'],
            ['reports', 'customers', 'view', 'reports.customers', 'Read the customer report family'],
            ['reports', 'suppliers', 'view', 'reports.suppliers', 'Read the supplier report family'],
            ['reports', 'finance', 'view', 'reports.finance', 'Read the finance report family'],
            ['reports', 'tax', 'view', 'reports.tax', 'Read the VAT and tax report family'],
            ['reports', 'hr', 'view', 'reports.hr', 'Read the employee report family — attendance, leave and (once payroll posts) pay'],
            ['reports', 'marketing', 'view', 'reports.marketing', 'Read the marketing report family'],
            ['reports', 'custom', 'view', 'reports.custom', 'Read the register of custom reports and their run history'],
            ['reports', 'schedules', 'manage', 'reports.scheduled', 'Schedule a saved report to run itself, and read what the runs produced'],
            ['pettycash', 'funds', 'manage', 'pettycash.funds', 'Declare a float, name its custodian and close it'],
            ['pettycash', 'vouchers', 'create', 'pettycash.spend', 'Pay a voucher out of the float and read its register'],
            ['pettycash', 'requests', 'decide', 'pettycash.approve', 'Decide the vouchers a custodian had to ask for'],
            ['pettycash', 'replenishment', 'create', 'pettycash.replenish', 'Put money back into a float'],
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
            ['inventory', 'products', 'import', 'inventory.products.import', 'Import the product catalogue (CSV)'],
            ['inventory', 'products', 'export', 'inventory.products.export', 'Export the product catalogue (CSV)'],
            ['inventory', 'stock', 'view', 'inventory.stock.view', 'View stock overview'],
            ['inventory', 'adjustments', 'view', 'inventory.adjustments.view', 'View stock adjustments'],
            ['inventory', 'adjustments', 'create', 'inventory.adjustments.create', 'Create stock adjustments / opening'],
            ['inventory', 'adjustments', 'approve', 'inventory.adjustments.approve', 'Approve stock adjustments'],
            ['inventory', 'transfers', 'create', 'inventory.transfers.create', 'Create stock transfers'],
            ['inventory', 'transfers', 'dispatch', 'inventory.transfers.dispatch', 'Dispatch stock transfers'],
            ['inventory', 'transfers', 'receive', 'inventory.transfers.receive', 'Receive stock transfers'],
            ['inventory', 'transfers', 'approve', 'inventory.transfers.approve', 'Approve stock transfers before dispatch'],
            ['inventory', 'ledger', 'view', 'inventory.ledger.view', 'View stock movement ledger'],
            ['inventory', 'valuation', 'view', 'inventory.valuation.view', 'View stock valuation'],
            ['inventory', 'reports', 'view', 'inventory.reports.view', 'View inventory reports'],
            ['inventory', 'counts', 'view', 'inventory.counts.view', 'View stock count sheets'],
            ['inventory', 'counts', 'create', 'inventory.counts.create', 'Create cycle counts and enter counted quantities'],
            ['inventory', 'counts', 'post', 'inventory.counts.post', 'Post count variances to stock'],
            ['inventory', 'writeoffs', 'create', 'inventory.writeoffs.create', 'Create write-offs'],
            ['inventory', 'writeoffs', 'approve', 'inventory.writeoffs.approve', 'Approve write-offs'],
            ['inventory', 'damage', 'create', 'inventory.damage.create', 'Record damage'],
            ['inventory', 'loss', 'create', 'inventory.loss.create', 'Record loss'],
            ['inventory', 'reorder', 'view', 'inventory.reorder.view', 'View reorder alerts'],
            ['inventory', 'reorder', 'configure', 'inventory.reorder.configure', 'Configure reorder policies'],
            ['inventory', 'reorder', 'suggest', 'inventory.reorder.suggest', 'Write reorder proposals down and draft purchase orders from them'],
            ['inventory', 'packaging', 'view', 'inventory.packaging', 'See packaging types and what they are used for'],
            ['inventory', 'packaging', 'manage', 'inventory.packaging.manage', 'Declare, rename and retire packaging types'],
            ['inventory', 'batch', 'view', 'inventory.batch.view', 'View batches'],
            ['inventory', 'batch', 'manage', 'inventory.batch.manage', 'Record and correct batch dates and references'],
            ['inventory', 'serial', 'view', 'inventory.serial.view', 'View serials'],
            ['inventory', 'reservations', 'view', 'inventory.reservations.view', 'View stock reservations'],
            ['inventory', 'reservations', 'manage', 'inventory.reservations.manage', 'Release or expire stock holds'],
            ['inventory', 'labels', 'manage', 'inventory.labels', 'Generate and print labels'],
            ['inventory', 'products', 'print', 'inventory.products.print', 'Print a product barcode or QR code'],
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

        $duplicates = collect($rows)
            ->groupBy(fn (array $row): string => $row[3])
            ->filter(fn ($group): bool => $group->count() > 1)
            ->keys();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'This catalogue defines %s more than once: %s. A permission key is defined once — a second row overwrites the first one\'s resource and label.',
                $duplicates->count() === 1 ? 'one key' : $duplicates->count().' keys',
                $duplicates->implode(', '),
            ));
        }

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
