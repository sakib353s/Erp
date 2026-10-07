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
        code: 'work', label: 'My work', icon: 'bi-lightning-charge',
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
        code: 'sell', label: 'Sell', icon: 'bi-cart3',
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
        code: 'operate', label: 'Buy & stock', icon: 'bi-box-seam',
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
        code: 'money', label: 'Money', icon: 'bi-cash-stack',
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
        code: 'people', label: 'People', icon: 'bi-people',
        groups: [{ label: 'Employee', icon: 'bi-person-badge', url: '#', items: [] }],
    },
    {
        code: 'insight', label: 'Insight', icon: 'bi-graph-up-arrow',
        groups: [{
            label: 'Reports', icon: 'bi-graph-up-arrow', items: [
                { label: 'Sales summary', url: '#', icon: 'bi-bar-chart' },
                { label: 'Sales trend', url: '#', icon: 'bi-graph-up' },
                { label: 'Invoice aging', url: '#', icon: 'bi-calendar-check' },
                { label: 'Peak hours', url: '#', icon: 'bi-clock' },
                { label: 'Custom report', url: '#', icon: 'bi-sliders2' },
                { label: 'Documents', url: '#', icon: 'bi-folder2-open' },
            ],
            overflow: 26,
        }],
    },
    {
        code: 'govern', label: 'Governance', icon: 'bi-shield-check',
        groups: [{
            label: 'Business management', icon: 'bi-building', items: [
                { label: 'Workflows', url: '#', icon: 'bi-diagram-3' },
                { label: 'Documents', url: '#', icon: 'bi-folder2-open' },
            ],
        }],
    },
    {
        code: 'configure', label: 'Configuration', icon: 'bi-sliders',
        groups: [
            {
                label: 'Settings', icon: 'bi-sliders', items: [
                    { label: 'Company profile', url: '#', icon: 'bi-building' },
                    { label: 'Users', url: '#', icon: 'bi-person-badge' },
                    { label: 'Roles & permissions', url: '#', icon: 'bi-shield-lock' },
                    { label: 'Branches', url: '#', icon: 'bi-diagram-3' },
                    { label: 'Appearance', url: '#', icon: 'bi-palette' },
                    { label: 'Courier partners', url: '#', icon: 'bi-truck' },
                    { label: 'POS settings', url: '#', icon: 'bi-upc-scan' },
                    { label: 'System maintenance', url: '#', icon: 'bi-tools' },
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
    { label: 'Sales orders', url: './orders.html', icon: 'bi-receipt', section: 'Sell', group: 'Sales' },
    { label: 'Create order', url: './orders.html', icon: 'bi-plus-circle', section: 'Sell', group: 'Sales', hint: 'action' },
    { label: 'Bulk confirm orders', url: './orders.html', icon: 'bi-check2-square', section: 'Sell', group: 'Sales', hint: 'action' },
    { label: 'Bulk print invoice', url: './orders.html', icon: 'bi-printer', section: 'Sell', group: 'Sales', hint: 'action' },
    { label: 'Invoices', url: './order-detail.html', icon: 'bi-file-earmark-text', section: 'Sell', group: 'Sales' },
    { label: 'Mushak 9.1 tax invoice', url: '#', icon: 'bi-file-earmark-ruled', section: 'Sell', group: 'Sales' },
    { label: 'POS terminal', url: './pos.html', icon: 'bi-upc-scan', section: 'Sell', group: 'Counter (POS)' },
    { label: 'Cash in / cash out', url: '#', icon: 'bi-cash-stack', section: 'Sell', group: 'Counter (POS)' },
    { label: 'Coupon analytics', url: '#', icon: 'bi-ticket-perforated', section: 'Sell', group: 'Sales' },
    { label: 'Products', url: '#', icon: 'bi-boxes', section: 'Buy & stock', group: 'Inventory' },
    { label: 'Stock overview', url: '#', icon: 'bi-clipboard-check', section: 'Buy & stock', group: 'Inventory' },
    { label: 'Stock movement ledger', url: '#', icon: 'bi-list-columns', section: 'Buy & stock', group: 'Inventory' },
    { label: 'Purchase orders', url: '#', icon: 'bi-bag-check', section: 'Buy & stock', group: 'Purchase' },
    { label: 'Chart of accounts', url: '#', icon: 'bi-diagram-3', section: 'Money', group: 'Accounting' },
    { label: 'Journal entries', url: '#', icon: 'bi-journal-text', section: 'Money', group: 'Accounting' },
    { label: 'Trial balance', url: '#', icon: 'bi-clipboard-data', section: 'Money', group: 'Accounting' },
    { label: 'General ledger', url: '#', icon: 'bi-list-columns', section: 'Money', group: 'Accounting' },
    { label: 'Employees', url: '#', icon: 'bi-person-badge', section: 'People', group: 'Employee' },
    { label: 'Sales summary report', url: '#', icon: 'bi-bar-chart', section: 'Insight', group: 'Reports' },
    { label: 'Invoice aging report', url: '#', icon: 'bi-calendar-check', section: 'Insight', group: 'Reports' },
    { label: 'Custom report builder', url: '#', icon: 'bi-sliders2', section: 'Insight', group: 'Reports' },
    { label: 'Workflows', url: '#', icon: 'bi-diagram-3', section: 'Governance', group: 'Business management' },
    { label: 'Users', url: '#', icon: 'bi-person-badge', section: 'Configuration', group: 'Settings' },
    { label: 'Roles & permissions', url: '#', icon: 'bi-shield-lock', section: 'Configuration', group: 'Settings' },
    { label: 'Appearance', url: '#', icon: 'bi-palette', section: 'Configuration', group: 'Settings' },
    { label: 'System maintenance', url: '#', icon: 'bi-tools', section: 'Configuration', group: 'Settings' },
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
        <div class="erp-nav-section">
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

export function topbar({ trail = [], title = 'Dashboard', branch = 'Dhaka HQ', warehouse = 'Main warehouse' } = {}) {
    const crumbs = trail.map((crumb) => `
        <li>
            ${crumb.url
                ? `<a href="${crumb.url}">${esc(crumb.label)}</a><i class="bi bi-chevron-right" aria-hidden="true"></i>`
                : `<span class="is-current" aria-current="page">${esc(crumb.label)}</span>`}
        </li>`).join('');

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
</div>

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
        ['pos.html', 'POS terminal'],
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
