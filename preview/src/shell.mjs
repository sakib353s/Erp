/**
 * Preview shell renderer.
 *
 * Mirrors resources/views/partials/{sidebar,topbar,command-palette}.blade.php
 * so the static preview shows EXACTLY the structure the Blade partials emit.
 * Navigation data below mirrors what NavigationBuilder + the §47 catalog
 * resolve to for a super-admin on a seeded instance (routes verified against
 * routes/web.php — nothing here is an invented screen).
 */

export const SECTIONS = [
    {
        code: 'pinned', label: 'Pinned', icon: 'bi-star',
        groups: [{
            label: 'Pinned pages', icon: 'bi-star', items: [
                { label: 'Sales orders', url: './orders.html', icon: 'bi-receipt', pinned: true },
                { label: 'POS terminal', url: './pos.html', icon: 'bi-upc-scan', pinned: true },
                { label: 'Trial balance', url: '#', icon: 'bi-clipboard-data', pinned: true },
            ],
        }],
    },
    {
        code: 'work', label: 'My work', icon: 'bi-lightning-charge', hue: 8,
        groups: [{
            label: 'Work queue', icon: 'bi-inbox', items: [
                { label: 'Dashboard', url: './dashboard.html', icon: 'bi-grid-1x2', target: 'dashboard' },
                { label: 'Approval inbox', url: '#', icon: 'bi-inbox', badge: 7, badgeVariant: 'warn' },
                { label: 'Notifications', url: '#', icon: 'bi-bell' },
                { label: 'Audit log', url: '#', icon: 'bi-shield-lock' },
            ],
        }],
    },
    {
        code: 'crm', label: 'Sales & CRM', icon: 'bi-cart3', hue: 1,
        groups: [
            {
                label: 'Sales', icon: 'bi-cart3', items: [
                    { label: 'Orders', url: './orders.html', icon: 'bi-receipt', target: 'orders' },
                    { label: 'Invoices', url: './order-detail.html', icon: 'bi-file-earmark-text', target: 'invoice' },
                    { label: 'Quotations', url: '#', icon: 'bi-file-earmark-ruled' },
                    { label: 'Delivery challans', url: '#', icon: 'bi-truck' },
                    { label: 'Shipments', url: '#', icon: 'bi-box-seam' },
                    { label: 'Delivery riders', url: '#', icon: 'bi-person-badge' },
                    { label: 'Coupons & promotions', url: '#', icon: 'bi-ticket-perforated' },
                    { label: 'Sales team', url: '#', icon: 'bi-people' },
                ],
                overflow: 34,
            },
            {
                label: 'Counter (POS)', icon: 'bi-upc-scan', items: [
                    { label: 'Terminal', url: './pos.html', icon: 'bi-upc-scan', target: 'pos' },
                    { label: 'Sessions', url: '#', icon: 'bi-clock-history' },
                    { label: 'Returns', url: '#', icon: 'bi-arrow-counterclockwise' },
                    { label: 'Exchange', url: '#', icon: 'bi-arrow-left-right' },
                    { label: 'Cash drawer', url: '#', icon: 'bi-cash-stack' },
                ],
            },
            { label: 'Customers', icon: 'bi-people', url: '#', items: [] },
            { label: 'Returns', icon: 'bi-arrow-counterclockwise', url: '#', items: [] },
        ],
    },
    {
        code: 'stock', label: 'Inventory & warehouse', icon: 'bi-box-seam', hue: 2,
        groups: [
            {
                label: 'Inventory', icon: 'bi-box-seam', items: [
                    { label: 'Products', url: '#', icon: 'bi-boxes' },
                    { label: 'Stock overview', url: '#', icon: 'bi-clipboard-check' },
                    { label: 'Adjustments', url: '#', icon: 'bi-sliders' },
                    { label: 'Transfers', url: '#', icon: 'bi-arrow-left-right' },
                    { label: 'Stock movements', url: '#', icon: 'bi-list-columns' },
                ],
                overflow: 21,
            },
            { label: 'Purchase', icon: 'bi-bag-check', url: '#', items: [] },
            { label: 'Suppliers', icon: 'bi-truck', url: '#', items: [] },
            { label: 'Warehouses', icon: 'bi-building', url: '#', items: [] },
        ],
    },
    {
        code: 'finance', label: 'Accounts & finance', icon: 'bi-cash-stack', hue: 5,
        groups: [
            {
                label: 'Accounting', icon: 'bi-journal-text', items: [
                    { label: 'Chart of accounts', url: '#', icon: 'bi-diagram-3' },
                    { label: 'Journals', url: '#', icon: 'bi-journal-text' },
                    { label: 'General ledger', url: '#', icon: 'bi-list-columns' },
                    { label: 'Trial balance', url: '#', icon: 'bi-clipboard-data' },
                    { label: 'Opening balances', url: '#', icon: 'bi-hourglass-split' },
                ],
            },
            { label: 'Cash & bank', icon: 'bi-bank', url: '#', items: [] },
        ],
    },
    {
        code: 'hr', label: 'People & payroll', icon: 'bi-people', hue: 6,
        groups: [{ label: 'Employee', icon: 'bi-person-badge', url: '#', items: [] }],
    },
    {
        code: 'cash', label: 'Cash & bank', icon: 'bi-bank', hue: 2,
        groups: [
            {
                label: 'Cash Management', icon: 'bi-cash-stack', items: [
                    { label: 'Cash in Hand', url: './cash-bank.html', icon: 'bi-wallet2', target: 'cash' },
                    { label: 'Cash Receipts', url: './cash-bank.html', icon: 'bi-box-arrow-in-down' },
                    { label: 'Cash Payments', url: './cash-bank.html', icon: 'bi-box-arrow-up' },
                    { label: 'Cash Transfer', url: './cash-bank.html', icon: 'bi-arrow-left-right' },
                    { label: 'Cash Count', url: './cash-counts.html', icon: 'bi-clipboard-check' },
                ],
            },
            {
                label: 'Bank Accounts', icon: 'bi-bank', items: [
                    { label: 'All Bank Accounts', url: './cash-bank.html', icon: 'bi-bank' },
                    { label: 'Bank Transactions', url: './cash-bank.html', icon: 'bi-journal-text' },
                    { label: 'Bank Reconciliation', url: './bank-recon.html', icon: 'bi-shield-check', target: 'cash' },
                    { label: 'Bank Statement Import', url: './bank-recon.html', icon: 'bi-file-earmark-spreadsheet' },
                    { label: 'Bank Charge Auto-Posting', url: './bank-charges.html', icon: 'bi-cash-coin' },
                ],
            },
            {
                label: 'Cheque Management', icon: 'bi-journal-bookmark', items: [
                    { label: 'Received Cheques', url: './cheques.html', icon: 'bi-journal-arrow-down', target: 'cash' },
                    { label: 'Issued Cheques', url: './cheques.html', icon: 'bi-journal-arrow-up' },
                    { label: 'Cleared Cheques', url: './cheques.html', icon: 'bi-check2-circle' },
                    { label: 'Bounced Cheques', url: './cheques.html', icon: 'bi-x-octagon' },
                    { label: 'Post-Dated Cheques', url: './cheques.html', icon: 'bi-calendar-event' },
                    { label: 'Cheque Print', url: './cheques.html', icon: 'bi-printer' },
                ],
            },
            {
                label: 'Expenses', icon: 'bi-receipt', items: [
                    { label: 'All Expenses', url: './expenses.html', icon: 'bi-receipt', target: 'cash' },
                    { label: 'Add Expense', url: './expenses.html', icon: 'bi-plus-lg' },
                    { label: 'Expense Categories', url: './expenses.html', icon: 'bi-diagram-3' },
                    { label: 'Pending Approval', url: './expenses.html', icon: 'bi-hourglass-split' },
                    { label: 'Recurring Expenses', url: './expense-recurring.html', icon: 'bi-arrow-repeat' },
                    { label: 'Expense Reports', url: './expense-report.html', icon: 'bi-bar-chart-line' },
                ],
            },
            {
                label: 'Petty Cash', icon: 'bi-cash-coin', items: [
                    { label: 'Petty Cash Overview', url: './petty-cash.html', icon: 'bi-cash-coin' },
                    { label: 'Petty Cash Requests', url: './petty-cash-requests.html', icon: 'bi-question-circle' },
                    { label: 'Petty Cash Expenses', url: './petty-cash-expenses.html', icon: 'bi-receipt' },
                    { label: 'Petty Cash Replenishment', url: './petty-cash-replenishment.html', icon: 'bi-arrow-down-up' },
                ],
            },
            {
                label: 'Cash Reports', icon: 'bi-list-columns', items: [
                    { label: 'Cash Reports', url: './cash-reports.html', icon: 'bi-list-columns' },
                ],
            },
            {
                label: 'Mobile Banking', icon: 'bi-phone', items: [
                    { label: 'bKash Account', url: './cash-bank.html', icon: 'bi-phone' },
                    { label: 'Nagad Account', url: './cash-bank.html', icon: 'bi-phone' },
                    { label: 'Mobile Reconciliation', url: './bank-recon.html', icon: 'bi-phone-vibrate' },
                ],
            },
        ],
    },
    {
        code: 'insight', label: 'Reports & insight', icon: 'bi-graph-up-arrow', hue: 3,
        groups: [{
            label: 'Reports', icon: 'bi-graph-up-arrow', target: 'insight', items: [
                { label: 'Bengali Settings', url: './settings-localization.html', icon: 'bi-translate', section: 'Configuration', group: 'Settings' },
    { label: 'VAT & Tax Settings', url: './settings-tax.html', icon: 'bi-percent', section: 'Configuration', group: 'Settings' },
    { label: 'Report Centre', url: './reports.html', icon: 'bi-grid', target: 'insight' },
                { label: 'Sales Reports', url: './reports-family.html', icon: 'bi-cart3' },
                { label: 'Finance Reports', url: './reports-family.html', icon: 'bi-journal-text' },
                { label: 'Inventory Reports', url: './reports-family.html', icon: 'bi-boxes' },
                { label: 'Customer Reports', url: './reports-family.html', icon: 'bi-people' },
                { label: 'Custom Reports', url: './reports-custom.html', icon: 'bi-sliders2' },
                { label: 'Scheduled Reports', url: './reports-scheduled.html', icon: 'bi-clock-history' },
            ],
        }],
    },
    {
        code: 'govern', label: 'Governance', icon: 'bi-shield-check', hue: 7,
        groups: [{
            label: 'Business management', icon: 'bi-building', items: [
                { label: 'Notice board', url: './notices.html', icon: 'bi-megaphone' },
                { label: 'Tasks & projects', url: './tasks.html', icon: 'bi-kanban' },
                { label: 'Business registers', url: './records.html', icon: 'bi-journal-text' },
                { label: 'Compliance calendar', url: './compliance.html', icon: 'bi-calendar-event' },
                { label: 'Meetings', url: './meetings.html', icon: 'bi-calendar-event' },
                { label: 'Meeting minutes', url: './minutes.html', icon: 'bi-journal-check' },
                { label: 'Action items', url: './action-items.html', icon: 'bi-list-check' },
                { label: 'Assets & vehicles', url: './assets.html', icon: 'bi-hdd-stack' },
                { label: 'Vehicle trip log', url: './trips.html', icon: 'bi-signpost-split' },
                { label: 'Equipment', url: './equipment.html', icon: 'bi-gear' },
                { label: 'Depreciation', url: './depreciation.html', icon: 'bi-graph-down-arrow' },
                { label: 'Disposals', url: './disposal.html', icon: 'bi-archive' },
                { label: 'Workflows', url: '#', icon: 'bi-diagram-3' },
                { label: 'Documents', url: '#', icon: 'bi-folder2-open' },
            ],
        }],
    },
    {
        code: 'configure', label: 'Settings & masters', icon: 'bi-sliders', hue: 8,
        groups: [
            {
                label: 'Settings', icon: 'bi-sliders', target: 'configure', items: [
                    { label: 'Settings Desk', url: './settings.html', icon: 'bi-sliders', target: 'configure' },
                    { label: 'General Settings', url: './settings.html', icon: 'bi-sliders2' },
                    { label: 'Bengali Settings', url: './settings-localization.html', icon: 'bi-translate' },
                    { label: 'VAT & Tax Settings', url: './settings-tax.html', icon: 'bi-percent' },
                    { label: 'Security Settings', url: './settings.html', icon: 'bi-shield-lock' },
                    { label: 'Branch Settings', url: './settings-branch.html', icon: 'bi-diagram-3' },
                    { label: 'Company profile', url: '#', icon: 'bi-building' },
                    { label: 'Users', url: '#', icon: 'bi-person-badge' },
                    { label: 'Roles & permissions', url: '#', icon: 'bi-shield-lock' },
                    { label: 'System maintenance', url: './maintenance.html', icon: 'bi-tools' },
                    { label: 'Error log', url: './maintenance-logs.html', icon: 'bi-journal-code' },
                ],
                overflow: 38,
            },
            { label: 'Master data', icon: 'bi-list-check', url: '#', items: [] },
        ],
    },
];

export const UTILITY = [
    { label: 'Company profile', url: '#', icon: 'bi-building' },
    { label: 'Master data', url: '#', icon: 'bi-list-check' },
    { label: 'Employees', url: '#', icon: 'bi-person-badge' },
    { label: 'Approval inbox', url: '#', icon: 'bi-inbox', active: true },
    { label: 'Audit log', url: '#', icon: 'bi-shield-lock' },
];

/** Every routable destination the palette can reach — the sidebar is a lens, not a gate. */
export const PALETTE = [
    { label: 'Dashboard', url: './dashboard.html', icon: 'bi-grid-1x2', section: 'My work', group: 'Dashboard' },
    { label: 'Approval inbox', url: '#', icon: 'bi-inbox', section: 'My work', group: 'Workflow' },
    { label: 'Sales orders', url: './orders.html', icon: 'bi-receipt', section: 'Sales & CRM', group: 'Sales' },
    { label: 'Create order', url: './orders.html', icon: 'bi-plus-circle', section: 'Sales & CRM', group: 'Sales', hint: 'action' },
    { label: 'Bulk confirm orders', url: './orders.html', icon: 'bi-check2-square', section: 'Sales & CRM', group: 'Sales', hint: 'action' },
    { label: 'Bulk print invoice', url: './orders.html', icon: 'bi-printer', section: 'Sales & CRM', group: 'Sales', hint: 'action' },
    { label: 'Invoices', url: './order-detail.html', icon: 'bi-file-earmark-text', section: 'Sales & CRM', group: 'Sales' },
    { label: 'Mushak 9.1 tax invoice', url: '#', icon: 'bi-file-earmark-ruled', section: 'Sales & CRM', group: 'Sales' },
    { label: 'POS terminal', url: './pos.html', icon: 'bi-upc-scan', section: 'Sales & CRM', group: 'Counter (POS)' },
    { label: 'Cash in / cash out', url: '#', icon: 'bi-cash-stack', section: 'Sales & CRM', group: 'Counter (POS)' },
    { label: 'Coupon analytics', url: '#', icon: 'bi-ticket-perforated', section: 'Sales & CRM', group: 'Sales' },
    { label: 'Products', url: '#', icon: 'bi-boxes', section: 'Inventory & warehouse', group: 'Inventory' },
    { label: 'Stock overview', url: '#', icon: 'bi-clipboard-check', section: 'Inventory & warehouse', group: 'Inventory' },
    { label: 'Stock movement ledger', url: '#', icon: 'bi-list-columns', section: 'Inventory & warehouse', group: 'Inventory' },
    { label: 'Packaging types', url: './packaging.html', icon: 'bi-box-seam', section: 'Inventory & warehouse', group: 'Packaging' },
    { label: 'Cash in Hand', url: './cash-bank.html', icon: 'bi-wallet2', section: 'Cash & bank', group: 'Cash Management' },
    { label: 'Cash Receipts', url: './cash-bank.html', icon: 'bi-box-arrow-in-down', section: 'Cash & bank', group: 'Cash Management' },
    { label: 'Cash Payments', url: './cash-bank.html', icon: 'bi-box-arrow-up', section: 'Cash & bank', group: 'Cash Management' },
    { label: 'Cash Transfer', url: './cash-bank.html', icon: 'bi-arrow-left-right', section: 'Cash & bank', group: 'Cash Management' },
    { label: 'Cash Count', url: './cash-counts.html', icon: 'bi-clipboard-check', section: 'Cash & bank', group: 'Cash Management' },
    { label: 'All Bank Accounts', url: './cash-bank.html', icon: 'bi-bank', section: 'Cash & bank', group: 'Bank Accounts' },
    { label: 'Bank Reconciliation', url: './bank-recon.html', icon: 'bi-shield-check', section: 'Cash & bank', group: 'Bank Accounts' },
    { label: 'Bank Statement Import', url: './bank-recon.html', icon: 'bi-file-earmark-spreadsheet', section: 'Cash & bank', group: 'Bank Accounts' },
    { label: 'Bank Charge Auto-Posting', url: './bank-charges.html', icon: 'bi-cash-coin', section: 'Cash & bank', group: 'Bank Accounts' },
    { label: 'Report Centre', url: './reports.html', icon: 'bi-grid', section: 'Reports & insight', group: 'Reports' },
    { label: 'Finance Reports', url: './reports-family.html', icon: 'bi-journal-text', section: 'Reports & insight', group: 'Reports' },
    { label: 'Custom Reports', url: './reports-custom.html', icon: 'bi-sliders2', section: 'Reports & insight', group: 'Reports' },
    { label: 'Scheduled Reports', url: './reports-scheduled.html', icon: 'bi-clock-history', section: 'Reports & insight', group: 'Reports' },
    { label: 'Mobile Reconciliation', url: './bank-recon.html', icon: 'bi-phone-vibrate', section: 'Cash & bank', group: 'Mobile Banking' },
    { label: 'Received Cheques', url: './cheques.html', icon: 'bi-journal-arrow-down', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'Issued Cheques', url: './cheques.html', icon: 'bi-journal-arrow-up', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'Cleared Cheques', url: './cheques.html', icon: 'bi-check2-circle', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'Bounced Cheques', url: './cheques.html', icon: 'bi-x-octagon', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'Post-Dated Cheques', url: './cheques.html', icon: 'bi-calendar-event', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'Cheque Print', url: './cheques.html', icon: 'bi-printer', section: 'Cash & bank', group: 'Cheque Management' },
    { label: 'All Expenses', url: './expenses.html', icon: 'bi-receipt', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Add Expense', url: './expenses.html', icon: 'bi-plus-lg', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Expense Categories', url: './expenses.html', icon: 'bi-diagram-3', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Pending Approval', url: './expenses.html', icon: 'bi-hourglass-split', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Recurring Expenses', url: './expense-recurring.html', icon: 'bi-arrow-repeat', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Expense Reports', url: './expense-report.html', icon: 'bi-bar-chart-line', section: 'Cash & bank', group: 'Expenses' },
    { label: 'Petty Cash Overview', url: './petty-cash.html', icon: 'bi-cash-coin', section: 'Cash & bank', group: 'Petty Cash' },
    { label: 'Petty Cash Requests', url: './petty-cash-requests.html', icon: 'bi-question-circle', section: 'Cash & bank', group: 'Petty Cash' },
    { label: 'Petty Cash Expenses', url: './petty-cash-expenses.html', icon: 'bi-receipt', section: 'Cash & bank', group: 'Petty Cash' },
    { label: 'Petty Cash Replenishment', url: './petty-cash-replenishment.html', icon: 'bi-arrow-down-up', section: 'Cash & bank', group: 'Petty Cash' },
    { label: 'Cash Reports', url: './cash-reports.html', icon: 'bi-list-columns', section: 'Cash & bank', group: 'Cash Reports' },
    { label: 'Print labels', url: './labels.html', icon: 'bi-printer', section: 'Inventory & warehouse', group: 'Barcode & QR' },
    { label: 'Generate barcode', url: './labels.html', icon: 'bi-upc-scan', section: 'Inventory & warehouse', group: 'Barcode & QR' },
    { label: 'Generate QR code', url: './labels.html', icon: 'bi-qr-code', section: 'Inventory & warehouse', group: 'Barcode & QR' },
    { label: 'Barcode scanner setup', url: './labels.html', icon: 'bi-broadcast', section: 'Inventory & warehouse', group: 'Barcode & QR' },
    { label: 'Purchase orders', url: '#', icon: 'bi-bag-check', section: 'Inventory & warehouse', group: 'Purchase' },
    { label: 'Chart of accounts', url: '#', icon: 'bi-diagram-3', section: 'Accounts & finance', group: 'Accounting' },
    { label: 'Journal entries', url: '#', icon: 'bi-journal-text', section: 'Accounts & finance', group: 'Accounting' },
    { label: 'Trial balance', url: '#', icon: 'bi-clipboard-data', section: 'Accounts & finance', group: 'Accounting' },
    { label: 'General ledger', url: '#', icon: 'bi-list-columns', section: 'Accounts & finance', group: 'Accounting' },
    { label: 'Employees', url: '#', icon: 'bi-person-badge', section: 'People & payroll', group: 'Employee' },
    { label: 'Sales summary report', url: '#', icon: 'bi-bar-chart', section: 'Reports & insight', group: 'Reports' },
    { label: 'Invoice aging report', url: '#', icon: 'bi-calendar-check', section: 'Reports & insight', group: 'Reports' },
    { label: 'Custom report builder', url: '#', icon: 'bi-sliders2', section: 'Reports & insight', group: 'Reports' },
    { label: 'Workflows', url: '#', icon: 'bi-diagram-3', section: 'Governance', group: 'Business management' },
    { label: 'Users', url: '#', icon: 'bi-person-badge', section: 'Configuration', group: 'Settings' },
    { label: 'Roles & permissions', url: '#', icon: 'bi-shield-lock', section: 'Configuration', group: 'Settings' },
    { label: 'Bengali Settings', url: './settings-localization.html', icon: 'bi-translate', section: 'Configuration', group: 'Settings' },
    { label: 'VAT & Tax Settings', url: './settings-tax.html', icon: 'bi-percent', section: 'Configuration', group: 'Settings' },
    { label: 'Appearance', url: '#', icon: 'bi-palette', section: 'Configuration', group: 'Settings' },
    { label: 'Notice board', url: './notices.html', icon: 'bi-megaphone', section: 'Governance', group: 'Business management' },
    { label: 'Tasks & projects', url: './tasks.html', icon: 'bi-kanban', section: 'Governance', group: 'Business management' },
    { label: 'Business registers', url: './records.html', icon: 'bi-journal-text', section: 'Governance', group: 'Business management' },
    { label: 'Compliance calendar', url: './compliance.html', icon: 'bi-calendar-event', section: 'Governance', group: 'Business management' },
    { label: 'Meetings', url: './meetings.html', icon: 'bi-calendar-event', section: 'Governance', group: 'Business management' },
    { label: 'Meeting minutes', url: './minutes.html', icon: 'bi-journal-check', section: 'Governance', group: 'Business management' },
    { label: 'Action items', url: './action-items.html', icon: 'bi-list-check', section: 'Governance', group: 'Business management' },
    { label: 'Assets & vehicles', url: './assets.html', icon: 'bi-hdd-stack', section: 'Governance', group: 'Business management' },
    { label: 'Vehicle management', url: './vehicles.html', icon: 'bi-truck', section: 'Governance', group: 'Business management' },
    { label: 'Equipment', url: './equipment.html', icon: 'bi-gear', section: 'Governance', group: 'Business management' },
    { label: 'Vehicle trip log', url: './trips.html', icon: 'bi-signpost-split', section: 'Governance', group: 'Business management' },
    { label: 'Depreciation', url: './depreciation.html', icon: 'bi-graph-down-arrow', section: 'Governance', group: 'Business management' },
    { label: 'Disposals', url: './disposal.html', icon: 'bi-archive', section: 'Governance', group: 'Business management' },
    { label: 'System maintenance', url: './maintenance.html', icon: 'bi-tools', section: 'Configuration', group: 'Settings' },
    { label: 'Error log', url: './maintenance-logs.html', icon: 'bi-journal-code', section: 'Configuration', group: 'Settings' },
];

const esc = (value) => String(value)
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

function navLink(item, activeTarget) {
    const isActive = item.target && item.target === activeTarget;
    return `
        <a class="erp-nav-link${isActive ? ' active' : ''}" href="${item.url}"${isActive ? ' aria-current="page"' : ''}>
            <span>${esc(item.label)}</span>
        </a>`;
}

function groupMarkup(group, activeTarget) {
    const items = (group.items ?? []).map((item) => navLink(item, activeTarget)).join('');

    // A group with a single destination renders as a plain link — never a
    // one-item accordion.
    if (items && (group.items ?? []).length === 1 && !group.overflow) {
        const item = group.items[0];
        const isActive = item.target && item.target === activeTarget;
        return `
        <a class="erp-nav-link${isActive ? ' active' : ''}" href="${item.url}">
            <i class="bi ${item.icon} erp-nav-icon" aria-hidden="true"></i>
            <span>${esc(group.label)}</span>
        </a>`;
    }

    if (!items) {
        return `
        <a class="erp-nav-link" href="${group.url ?? '#'}">
            <i class="bi ${group.icon} erp-nav-icon" aria-hidden="true"></i>
            <span>${esc(group.label)}</span>
        </a>`;
    }

    const trail = (group.items ?? []).some((item) => item.target === activeTarget) || group.forceOpen;
    const overflow = group.overflow
        ? `<button class="erp-nav-link" type="button" data-palette-open title="Search every page in this module">
                <i class="bi bi-plus-circle erp-nav-icon" aria-hidden="true"></i>
                <span>${group.overflow} more — search</span>
           </button>`
        : '';

    return `
    <div class="erp-nav-group">
        <button class="erp-nav-group-btn${trail ? ' is-active has-active-trail' : ''}" type="button"
                data-bs-toggle="collapse" data-bs-target="#nav-${group.id}" aria-expanded="${trail ? 'true' : 'false'}">
            <i class="bi ${group.icon} erp-nav-icon" aria-hidden="true"></i>
            <span>${esc(group.label)}</span>
            <i class="bi bi-chevron-right erp-nav-caret" aria-hidden="true"></i>
        </button>
        <div class="collapse${trail ? ' show' : ''}" id="nav-${group.id}">
            <div class="erp-nav-sub">${items}${overflow}</div>
        </div>
    </div>`;
}

export function sidebar(activeTarget = 'dashboard') {
    const sectionsHtml = SECTIONS.map((section) => {
        const groups = section.groups
            .map((group, index) => {
                const withId = { ...group, id: `${section.code}-${index}` };
                return groupMarkup(withId, activeTarget);
            })
            .join('');

        return `
        <div class="erp-nav-section" data-hue="${section.hue ?? 0}">
            <p class="erp-nav-section-label">
                <i class="bi ${section.icon}" aria-hidden="true"></i>
                <span>${esc(section.label)}</span>
            </p>
            ${groups}
        </div>`;
    }).join('');

    const utility = UTILITY.map((entry) => `
        <a class="erp-util-link${entry.active ? ' active' : ''}" href="${entry.url}">
            <i class="bi ${entry.icon}" aria-hidden="true"></i>
            <span>${esc(entry.label)}</span>
        </a>`).join('');

    return `
<aside class="erp-sidebar" id="erpSidebar" aria-label="Primary navigation">
    <div class="erp-sidebar-head">
        <a class="erp-brand" href="./dashboard.html">
            <span class="erp-brand-mark" aria-hidden="true">BE</span>
            <span class="erp-brand-text">
                <strong>BD ERP</strong>
                <small>Rafshan Trading Ltd.</small>
            </span>
        </a>
        <button class="erp-icon-btn d-lg-none" type="button" data-erp-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="erp-sidebar-context">
        <button class="erp-nav-search" type="button" data-palette-open>
            <i class="bi bi-search" aria-hidden="true"></i>
            <span>Search or jump to…</span>
            <kbd>⌘K</kbd>
        </button>
    </div>

    <nav class="erp-sidebar-nav" aria-label="Sections">${sectionsHtml}</nav>

    <div class="erp-sidebar-foot">
        ${utility}
        <div class="erp-sidebar-foot-row">
            <button class="erp-rail-toggle d-none d-lg-flex" type="button" data-erp-rail-toggle aria-expanded="true"
                    title="Collapse navigation">
                <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                <span>Collapse</span>
            </button>
        </div>
        <span class="erp-version">Build 2026.10 · v2</span>
    </div>
</aside>`;
}

export function topbar(args = {}) {
    // Pages written before this signature passed the title as a plain string.
    // Accepting both keeps nineteen pages' titles honest instead of silently
    // rendering the default.
    const { trail = [], title = 'Dashboard', branch = 'Dhaka HQ', warehouse = 'Main warehouse' } =
        typeof args === 'string' ? { title: args } : args;
    const crumbs = trail.map((crumb) => {
        // Two pages were written before this signature and pass plain strings
        // ('Sales & CRM', 'Customers'). Both shapes are real: a crumb without a
        // URL is the page you are on, and one with a URL is the way back.
        const label = typeof crumb === 'string' ? crumb : crumb.label;
        const url = typeof crumb === 'string' ? null : crumb.url;
        return `
        <li>
            ${url
                ? `<a href="${url}">${esc(label)}</a><i class="bi bi-chevron-right" aria-hidden="true"></i>`
                : `<span class="is-current" aria-current="page">${esc(label)}</span>`}
        </li>`;
    }).join('');

    return `
<header class="erp-topbar">
    <button class="erp-icon-btn d-lg-none" type="button" data-erp-sidebar-open aria-label="Open navigation">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <div class="erp-topbar-crumbs">
        <nav aria-label="Breadcrumb"><ol class="erp-breadcrumb">${crumbs}</ol></nav>
        <div class="erp-topbar-title">
            <strong class="d-none d-md-inline">${esc(title)}</strong>
            <span class="erp-chip erp-chip-outline d-none d-xl-inline-flex" title="Current branch context">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>${esc(branch)}
            </span>
            <span class="erp-chip erp-chip-outline d-none d-xxl-inline-flex" title="Current warehouse context">
                <i class="bi bi-box-seam" aria-hidden="true"></i>${esc(warehouse)}
            </span>
        </div>
    </div>

    <button class="erp-global-search d-none d-md-flex" type="button" data-palette-open aria-label="Search the workspace">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span>Search pages, orders, invoices…</span>
        <kbd>⌘K</kbd>
    </button>

    <div class="erp-topbar-actions">
        <form class="erp-switcher d-none d-lg-flex" onsubmit="return false">
            <label class="erp-visually-hidden" for="branchSwitch">Branch</label>
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <select class="erp-switcher-select" id="branchSwitch">
                <option>Dhaka HQ</option>
                <option>Chattogram branch</option>
                <option>Sylhet outlet</option>
            </select>
        </form>

        <button class="erp-icon-btn d-none d-md-inline-flex" type="button" data-erp-theme-toggle
                aria-pressed="false" title="Switch appearance">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>

        <button class="erp-icon-btn d-none d-xl-inline-flex" type="button" data-erp-density-toggle
                aria-pressed="false" title="Compact rows">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>

        <button class="erp-lang-btn" type="button" title="Switch language">বাংলা</button>

        <div class="dropdown">
            <button class="erp-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="erp-badge-count">4</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end erp-notif-menu">
                <div class="dropdown-header d-flex justify-content-between align-items-center">
                    <span>Notifications</span>
                    <span class="erp-chip erp-chip-outline">4 unread</span>
                </div>
                <a class="dropdown-item fw-semibold" href="#">Order SO-2026-01845 needs confirmation
                    <small class="d-block fw-normal text-body-secondary">unread · high</small></a>
                <a class="dropdown-item fw-semibold" href="#">Courier settlement ready to reconcile
                    <small class="d-block fw-normal text-body-secondary">unread · normal</small></a>
                <a class="dropdown-item" href="#">Stock adjustment ADJ-00031 approved
                    <small class="d-block fw-normal text-body-secondary">read · normal</small></a>
                <a class="dropdown-item text-center fw-semibold" href="#">View all notifications</a>
            </div>
        </div>

        <div class="dropdown">
            <button class="erp-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">S</button>
            <div class="dropdown-menu dropdown-menu-end">
                <div class="dropdown-header">
                    <strong>Sakib Rahman</strong>
                    <small class="d-block text-body-secondary">sakib@rafshtrading.bd</small>
                </div>
                <a class="dropdown-item" href="#"><i class="bi bi-person me-2" aria-hidden="true"></i>My profile</a>
                <a class="dropdown-item" href="#"><i class="bi bi-bell me-2" aria-hidden="true"></i>Notifications</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="#"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</a>
            </div>
        </div>
    </div>
</header>`;
}

export function palette() {
    return `
<div class="erp-palette" id="erpPalette" hidden role="dialog" aria-modal="true" aria-label="Command palette">
    <div class="erp-palette-panel">
        <div class="erp-palette-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="erp-visually-hidden" for="erpPaletteInput">Search the workspace</label>
            <input class="erp-palette-input" id="erpPaletteInput" type="text" autocomplete="off" spellcheck="false"
                   placeholder="Search pages, reports, and actions…" data-palette-input>
            <kbd>esc</kbd>
        </div>
        <div class="erp-palette-list" data-palette-list role="listbox" aria-label="Results"></div>
        <div class="erp-palette-foot">
            <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
            <span><kbd>↵</kbd> open</span>
            <span class="ms-auto">${PALETTE.length} destinations available to you</span>
        </div>
    </div>
</div>`;
}

export function footer() {
    return `
<footer class="erp-footer">
    <span>BD ERP · Rafshan Trading Ltd.</span>
    <span class="erp-footer-meta">
        <span>07 Oct 2026, 06:20 PM · Asia/Dhaka</span>
        <span class="d-none d-md-inline">Press <kbd>⌘</kbd><kbd>K</kbd> to jump anywhere</span>
    </span>
</footer>`;
}

export function shellHeader(title) {
    return `<!DOCTYPE html>
<html lang="en" data-accent="teal" data-density="comfortable">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff">
    <title>${esc(title)} · BD ERP — UI redesign preview</title>
    <link rel="stylesheet" href="./assets/preview.css">
</head>
<body class="erp-body">
<a class="erp-skip-link" href="#erpContent">Skip to main content</a>`;
}

export function shellFooter(activeTarget) {
    return `
<div class="erp-backdrop" data-erp-backdrop hidden></div>

${palette()}

<div class="erp-toast-stack" data-erp-toasts role="status" aria-live="polite"></div>

<script>window.erpNavIndex = ${JSON.stringify(PALETTE)};</script>
<script type="module" src="./assets/preview.js"></script>
</body>
</html>`;
}

export function previewBar(current) {
    const links = [
        ['index.html', 'Design system'],
        ['dashboard.html', 'Dashboard'],
        ['orders.html', 'Sales orders'],
        ['order-detail.html', 'Order workspace'],
        ['customers.html', 'Customers (CRM)'],
        ['customer-profile.html', 'Customer 360'],
        ['pos.html', 'POS terminal'],
        ['packaging.html', 'Packaging'],
        ['labels.html', 'Labels'],
        ['cash-bank.html', 'Cash & bank'],
        ['bank-recon.html', 'Reconciliation'],
        ['cash-counts.html', 'Cash count'],
        ['bank-charges.html', 'Bank charges'],
        ['expense-report.html', 'Expense report'],
        ['cash-reports.html', 'Cash reports'],
        ['cheques.html', 'Cheques'],
        ['expenses.html', 'Expenses'],
        ['expense-recurring.html', 'Recurring'],
        ['petty-cash.html', 'Petty cash'],
        ['login.html', 'Sign in'],
    ];

    return `
<div class="erp-preview-bar">
    <i class="bi bi-easel2" aria-hidden="true"></i>
    <strong>UI redesign preview</strong>
    ${links.map(([href, label]) => `<a href="./${href}"${href === current ? ' style="color:#fff;text-decoration:underline"' : ''}>${label}</a>`).join('<span style="opacity:.4">·</span>')}
    <span class="erp-preview-note">Static render of the new shell — same CSS + JS the Laravel app ships. Press ⌘K / Ctrl+K.</span>
</div>`;
}
