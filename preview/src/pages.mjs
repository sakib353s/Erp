/**
 * Static preview page bodies.
 *
 * Layout samples only: the numbers below are illustrative and exist to show
 * density, alignment and state handling. They are NOT seeded business data and
 * never reach the application (global invariant: no fake data in the app).
 */
import { previewBar, sidebar, topbar, footer } from './shell.mjs';

const money = (value) => `<span class="erp-amount">৳ ${value}</span>`;

const statusChip = (value, label = null) => {
    const key = String(value).toLowerCase().replaceAll(' ', '_').replaceAll('-', '_');
    return `<span class="erp-status erp-status-${key}">${label ?? value}</span>`;
};

/* ------------------------------------------------------------------ 1. dashboard */
export function dashboard() {
    const kpis = [
        { label: "Today's sales", value: '৳ 4,86,250', icon: 'bi-cart-check', delta: '+12.4% vs yesterday', trend: 'up', href: './orders.html' },
        { label: "Today's collection", value: '৳ 3,12,900', icon: 'bi-cash-coin', delta: '+6.1% vs yesterday', trend: 'up' },
        { label: 'Receivable (due)', value: '৳ 18,42,610', icon: 'bi-hourglass-split', delta: '৳ 2,10,000 overdue 90+', trend: 'down' },
        { label: 'Payable (due)', value: '৳ 9,75,300', icon: 'bi-wallet2', delta: '4 bills due this week', trend: 'flat' },
        { label: 'Stock value', value: '৳ 61,28,470', icon: 'bi-box-seam', delta: '142 SKUs below reorder level', trend: 'down' },
        { label: 'Open approvals', value: '7', icon: 'bi-inbox', delta: '2 above 24 hours', trend: 'down', href: '#' },
    ];

    const widgets = [
        ['Sales vs target', '৳ 26.4L / 30L', '88% of the October target — 11 selling days left.', 'bi-graph-up-arrow', true],
        ['Cash position', '৳ 12,05,880', 'Across 4 accounts: City Bank, BRAC, bKash merchant, counter cash.', 'bi-bank', false],
        ['Receivable aging', '৳ 18.42L', '0-30 ৳11.2L · 31-60 ৳4.6L · 61-90 ৳1.5L · 90+ ৳1.1L', 'bi-hourglass-split', false],
        ['Low stock alert', '142 SKUs', 'Includes 9 SKUs that are out of stock at Dhaka HQ.', 'bi-exclamation-triangle', false],
        ['Branch comparison', 'Chattogram +21%', 'Dhaka HQ flat, Sylhet outlet -4% week on week.', 'bi-diagram-3', false],
        ['Delivery performance', '92.6% on time', '38 shipments in transit · 6 failed delivery to recover.', 'bi-truck', false],
    ];

    return `
${previewBar('dashboard.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('dashboard')}
    <div class="erp-main">
${topbar({ title: 'Dashboard', trail: [{ label: 'My work' }, { label: 'Dashboard' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">My work</p>
                    <h1 class="erp-h1">Good evening, Sakib</h1>
                    <p class="erp-page-sub">Live figures for Dhaka HQ — every number is a server query, and each card opens the document list behind it.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="#"><i class="bi bi-download" aria-hidden="true"></i> Export snapshot</a>
                    <a class="btn btn-primary" href="./orders.html"><i class="bi bi-plus-lg" aria-hidden="true"></i> New sales order</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page">
                        <i class="bi bi-star" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <section class="erp-kpi-grid">
                ${kpis.map((kpi) => `
                <a class="erp-kpi text-decoration-none" href="${kpi.href ?? '#'}">
                    <p class="erp-kpi-label"><i class="bi ${kpi.icon}" aria-hidden="true"></i>${kpi.label}</p>
                    <p class="erp-kpi-value">${kpi.value}</p>
                    <span class="erp-kpi-delta ${kpi.trend === 'up' ? 'up' : kpi.trend === 'down' ? 'down' : ''}">
                        <i class="bi bi-arrow-${kpi.trend === 'up' ? 'up' : kpi.trend === 'down' ? 'down' : 'right'}" aria-hidden="true"></i>
                        ${kpi.delta}
                    </span>
                </a>`).join('')}
            </section>

            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-graph-up" aria-hidden="true"></i> Sales — last 14 days</h2>
                            <div class="erp-segmented">
                                <button class="active" type="button">Daily</button>
                                <button type="button">Weekly</button>
                                <button type="button">Monthly</button>
                            </div>
                        </header>
                        <div class="erp-spark" aria-hidden="true" style="height:120px">
                            ${[38, 44, 41, 52, 47, 61, 58, 66, 72, 64, 78, 71, 83, 88].map((h) => `<span style="height:${h}%"></span>`).join('')}
                        </div>
                        <p class="erp-widget-note mt-2 mb-0">Peak trading window 6-9 PM. Friday is excluded from the average by the Bangladesh calendar.</p>
                    </section>
                </div>
                <div class="col-lg-4">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-inbox" aria-hidden="true"></i> Waiting on you</h2>
                            <span class="erp-chip erp-chip-warn">7 pending</span>
                        </header>
                        <ul class="erp-checklist">
                            <li class="erp-checklist-item">
                                <span class="erp-checklist-mark"><i class="bi bi-circle" aria-hidden="true"></i></span>
                                <span class="erp-checklist-label">2 purchase bills above ৳ 5,00,000</span>
                                <a class="erp-checklist-link" href="#">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                            </li>
                            <li class="erp-checklist-item">
                                <span class="erp-checklist-mark"><i class="bi bi-circle" aria-hidden="true"></i></span>
                                <span class="erp-checklist-label">1 bulk price update (1,284 SKUs)</span>
                                <a class="erp-checklist-link" href="#">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                            </li>
                            <li class="erp-checklist-item">
                                <span class="erp-checklist-mark"><i class="bi bi-circle" aria-hidden="true"></i></span>
                                <span class="erp-checklist-label">4 COD settlements to reconcile</span>
                                <a class="erp-checklist-link" href="#">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                            </li>
                            <li class="erp-checklist-item done">
                                <span class="erp-checklist-mark"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                                <span class="erp-checklist-label">Morning cash drawer counted</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <section class="erp-widget-grid">
                ${widgets.map(([title, value, note, icon, span]) => `
                <article class="erp-widget${span ? ' erp-widget-span-2' : ''}">
                    <header class="erp-widget-head">
                        <h3 class="erp-widget-title">${title}</h3>
                        <span class="erp-widget-icon"><i class="bi ${icon}" aria-hidden="true"></i></span>
                    </header>
                    <div class="erp-widget-body">
                        <p class="erp-widget-metric">${value}</p>
                        <p class="erp-widget-note">${note}</p>
                        <a class="erp-widget-link" href="#">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </article>`).join('')}
            </section>
        </main>
${footer()}
    </div>
</div>`;
}

/* --------------------------------------------------------------- 2. orders list */
export function orders() {
    const rows = [
        ['SO-2026-01845', 'Nusrat Jahan', 'Dhaka · Mirpur', 'confirmed', 24580, 'Pathao', 'paid'],
        ['SO-2026-01844', 'Tanvir Ahmed', 'Chattogram · Agrabad', 'processing', 13750, 'RedX', 'cod'],
        ['SO-2026-01843', 'Rahima Begum', 'Sylhet · Zindabazar', 'pending', 8990, 'Steadfast', 'unpaid'],
        ['SO-2026-01842', 'Imran Hossain', 'Dhaka · Uttara', 'delivered', 42300, 'Own rider', 'paid'],
        ['SO-2026-01841', 'Farhana Akter', 'Khulna · Sonadanga', 'return_requested', 6250, 'Sundarban', 'refund_due'],
        ['SO-2026-01840', 'Jahangir Alam', 'Rajshahi · Shaheb Bazar', 'cancelled', 3900, '—', 'void'],
        ['SO-2026-01839', 'Shirin Sultana', 'Dhaka · Dhanmondi', 'completed', 18400, 'Pathao', 'paid'],
    ];

    const savedViews = [
        ['All orders', true], ['Pending', false], ['Confirmed', false], ['Processing', false],
        ['Ready to ship', false], ['In transit', false], ['Delivered', false], ['Return requested', false],
        ['Suspicious', false],
    ];

    return `
${previewBar('orders.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('orders')}
    <div class="erp-main">
${topbar({ title: 'Sales orders', trail: [{ label: 'Sell' }, { label: 'Sales' }, { label: 'Orders' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Sell · Sales</p>
                    <h1 class="erp-h1">Sales orders</h1>
                    <p class="erp-page-sub">Commitment documents. Stock is reserved on confirm, never on draft — the ledger only moves at invoice.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="#"><i class="bi bi-download" aria-hidden="true"></i> Export</a>
                    <a class="btn btn-outline-secondary" href="#"><i class="bi bi-printer" aria-hidden="true"></i> Print</a>
                    <a class="btn btn-primary" href="#"><i class="bi bi-plus-lg" aria-hidden="true"></i> New order</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin Sales orders">
                        <i class="bi bi-star-fill" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="erp-segmented mb-3">
                ${savedViews.map(([label, active]) => `<button class="${active ? 'active' : ''}" type="button">${label}${label === 'Return requested' ? ' <span class="erp-chip erp-chip-warn" style="margin-left:4px">3</span>' : ''}</button>`).join('')}
            </div>

            <form class="erp-filterbar" onsubmit="return false">
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="q">Search</label>
                    <div class="erp-input-group">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" id="q" placeholder="Order no, customer name or phone">
                    </div>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="courier">Courier</label>
                    <select class="form-select" id="courier"><option>Any courier</option><option>Pathao</option><option>RedX</option><option>Steadfast</option></select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="branch">Branch</label>
                    <select class="form-select" id="branch"><option>All my branches</option><option>Dhaka HQ</option><option>Chattogram branch</option></select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="from">Placed between</label>
                    <input class="form-control" type="text" id="from" value="01 Oct – 07 Oct 2026">
                </div>
                <div class="erp-filterbar-actions">
                    <button class="btn btn-outline-secondary" type="reset">Reset</button>
                    <button class="btn btn-primary" type="submit">Apply</button>
                </div>
            </form>

            <div class="erp-active-filters">
                <span class="erp-chip erp-chip-soft">Branch: Dhaka HQ <button class="erp-chip-remove" aria-label="Remove filter">×</button></span>
                <span class="erp-chip erp-chip-soft">Placed: this month <button class="erp-chip-remove" aria-label="Remove filter">×</button></span>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-bulkbar" data-erp-bulkbar>
                    <i class="bi bi-check2-square" aria-hidden="true"></i>
                    <strong><span data-erp-bulk-count>0</span></strong> selected
                    <div class="erp-bulkbar-actions">
                        <button class="btn btn-sm btn-outline-light" type="button"><i class="bi bi-check2" aria-hidden="true"></i> Confirm</button>
                        <button class="btn btn-sm btn-outline-light" type="button"><i class="bi bi-truck" aria-hidden="true"></i> Assign courier</button>
                        <button class="btn btn-sm btn-outline-light" type="button"><i class="bi bi-printer" aria-hidden="true"></i> Print invoices</button>
                        <button class="btn btn-sm btn-outline-light" type="button"><i class="bi bi-chat-dots" aria-hidden="true"></i> Notify</button>
                        <button class="btn btn-sm btn-outline-danger" type="button"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancel</button>
                    </div>
                </div>

                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th style="width:38px">
                                    <input class="form-check-input" type="checkbox" data-erp-select-all aria-label="Select all rows">
                                </th>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Area</th>
                                <th>Status</th>
                                <th class="erp-th-num">Total</th>
                                <th>Courier</th>
                                <th>Payment</th>
                                <th class="erp-th-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(([no, customer, area, status, total, courier, payment]) => `
                            <tr${status === 'pending' ? ' class="erp-row-attention"' : ''}>
                                <td data-label="Select"><input class="form-check-input" type="checkbox" data-erp-row-select aria-label="Select ${no}"></td>
                                <td data-label="Order"><a class="erp-row-link" href="./order-detail.html">${no}</a></td>
                                <td data-label="Customer">${customer}</td>
                                <td data-label="Area" class="erp-td-muted">${area}</td>
                                <td data-label="Status">${statusChip(status.replaceAll('_', ' '))}</td>
                                <td data-label="Total" class="erp-td-num">${money(total.toLocaleString('en-IN'))}</td>
                                <td data-label="Courier" class="erp-td-muted">${courier}</td>
                                <td data-label="Payment">${statusChip(payment, payment.replaceAll('_', ' '))}</td>
                                <td data-label="Actions" class="erp-td-actions">
                                    <button class="erp-icon-btn" type="button" title="Open" aria-label="Open ${no}"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>

                <div class="erp-table-foot">
                    <span>Showing <strong>1–7</strong> of <strong>1,842</strong> orders · totals shown in BDT</span>
                    <nav aria-label="Pagination">
                        <ul class="pagination">
                            <li class="page-item disabled"><span class="page-link">‹</span></li>
                            <li class="page-item active"><span class="page-link">1</span></li>
                            <li class="page-item"><a class="page-link" href="#">2</a></li>
                            <li class="page-item"><a class="page-link" href="#">3</a></li>
                            <li class="page-item"><a class="page-link" href="#">›</a></li>
                        </ul>
                    </nav>
                </div>
            </div>

            <div class="mt-3">
                <nav class="erp-card erp-card-tight" aria-label="More in this module">
                    <p class="erp-field-label">More in Sales</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Invoices</a>
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Quotations</a>
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Delivery challans</a>
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Shipments</a>
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Coupons & promotions</a>
                        <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Sales team</a>
                    </div>
                </nav>
            </div>
        </main>
${footer()}
    </div>
</div>`;
}

/* ----------------------------------------------------------- 3. order workspace */
export function orderDetail() {
    const lines = [
        ['Rice — Miniket 5kg (SKU RM-5KG)', 12, 1180, 14160],
        ['Soybean oil 2L (SKU SO-2L)', 24, 335, 8040],
        ['Sugar — fresh 1kg (SKU SG-1KG)', 30, 128, 3840],
    ];

    return `
${previewBar('order-detail.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('orders')}
    <div class="erp-main">
${topbar({ title: 'SO-2026-01845', trail: [
        { label: 'Sell' }, { label: 'Sales' }, { label: 'Orders', url: './orders.html' }, { label: 'SO-2026-01845' },
    ] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Sales order</p>
                    <h1 class="erp-h1">SO-2026-01845 ${statusChip('confirmed')}</h1>
                    <p class="erp-page-sub">Nusrat Jahan · 01711-000000 · Dhaka, Mirpur 10 · placed 07 Oct 2026, 11:42 AM by counter staff (Rakib).</p>
                    <div class="erp-meta-row">
                        <span class="erp-chip erp-chip-outline"><i class="bi bi-geo-alt" aria-hidden="true"></i>Dhaka HQ</span>
                        <span class="erp-chip erp-chip-outline"><i class="bi bi-person" aria-hidden="true"></i>Salesperson: Rakib</span>
                        <span class="erp-chip erp-chip-outline"><i class="bi bi-truck" aria-hidden="true"></i>Pathao · pickup scheduled</span>
                        <span class="erp-chip erp-chip-ok"><i class="bi bi-shield-check" aria-hidden="true"></i>Stock reserved</span>
                    </div>
                </div>
                <div class="erp-page-head-actions">
                    <button class="btn btn-outline-secondary" type="button"><i class="bi bi-printer" aria-hidden="true"></i> Print invoice</button>
                    <button class="btn btn-outline-secondary" type="button"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                    <button class="btn btn-primary" type="button"><i class="bi bi-check2" aria-hidden="true"></i> Confirm shipment</button>
                    <button class="erp-icon-btn" type="button" title="More actions" aria-label="More actions"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-xl-8">
                    <section class="erp-card mb-3">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-list-ul" aria-hidden="true"></i> Line items</h2>
                            <span class="erp-chip erp-chip-outline">3 lines · 66 units</span>
                        </header>
                        <div class="erp-table-scroll">
                            <table class="table erp-table erp-table-stack">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="erp-th-num">Qty</th>
                                        <th class="erp-th-num">Unit price</th>
                                        <th class="erp-th-num">Line total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${lines.map(([item, qty, price, total]) => `
                                    <tr>
                                        <td data-label="Item">${item}</td>
                                        <td data-label="Qty" class="erp-td-num">${qty}</td>
                                        <td data-label="Unit" class="erp-td-num">${money(price)}</td>
                                        <td data-label="Total" class="erp-td-num"><strong>${money(total.toLocaleString('en-IN'))}</strong></td>
                                    </tr>`).join('')}
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="text-end">Subtotal</td>
                                        <td class="erp-td-num">৳ 26,040</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end">Discount (customer group: retailer)</td>
                                        <td class="erp-td-num erp-amount-neg">− ৳ 1,460</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="text-end">Grand total</td>
                                        <td class="erp-td-num erp-amount-lg">৳ 24,580</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>

                    <section class="erp-card mb-3">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Activity</h2>
                            <button class="btn btn-sm btn-outline-secondary" type="button">Audit trail</button>
                        </header>
                        <ol class="erp-timeline">
                            <li class="erp-timeline-item erp-timeline-approved">
                                <span class="erp-timeline-marker"><i class="bi bi-check2" aria-hidden="true"></i></span>
                                <div class="erp-timeline-body">
                                    <p class="erp-timeline-title mb-0">Order confirmed — stock reserved (66 units)</p>
                                    <p class="erp-timeline-meta mb-0">07 Oct 2026, 11:44 AM · Rakib (counter staff) · SO-2026-01845</p>
                                </div>
                            </li>
                            <li class="erp-timeline-item erp-timeline-pending">
                                <span class="erp-timeline-marker"><i class="bi bi-hourglass" aria-hidden="true"></i></span>
                                <div class="erp-timeline-body">
                                    <p class="erp-timeline-title mb-0">Awaiting courier pickup</p>
                                    <p class="erp-timeline-meta mb-0">Pathao · consignment requested at 11:46 AM</p>
                                </div>
                            </li>
                            <li class="erp-timeline-item">
                                <span class="erp-timeline-marker"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                                <div class="erp-timeline-body">
                                    <p class="erp-timeline-title mb-0">Order created</p>
                                    <p class="erp-timeline-meta mb-0">07 Oct 2026, 11:42 AM · counter POS, session CS-00091</p>
                                </div>
                            </li>
                        </ol>
                    </section>
                </div>

                <div class="col-xl-4">
                    <section class="erp-card mb-3">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-credit-card" aria-hidden="true"></i> Payment</h2>
                            ${statusChip('paid')}
                        </header>
                        <dl class="erp-dl erp-dl-striped">
                            <dt>Method</dt><dd>bKash merchant</dd>
                            <dt>Received</dt><dd>${money('24,580')}</dd>
                            <dt>Due</dt><dd>${money('0')}</dd>
                            <dt>Reference</dt><dd class="erp-hash">TRX-9F31-8820</dd>
                        </dl>
                    </section>

                    <section class="erp-card mb-3">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-truck" aria-hidden="true"></i> Delivery</h2>
                            <span class="erp-chip erp-chip-outline">tracking</span>
                        </header>
                        <ul class="erp-stepper">
                            <li class="is-done"><span class="erp-stepper-mark"><i class="bi bi-check2"></i></span>Reserved</li>
                            <li class="is-current"><span class="erp-stepper-mark">2</span>Picked up</li>
                            <li><span class="erp-stepper-mark">3</span>In transit</li>
                            <li><span class="erp-stepper-mark">4</span>Delivered</li>
                        </ul>
                    </section>

                    <section class="erp-card">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> Controls</h2>
                        </header>
                        <ul class="erp-levels">
                            <li><strong class="d-block">Approval required above ৳ 5,00,000</strong><span class="text-body-secondary">Not triggered — this order is ৳ 24,580.</span></li>
                            <li><strong class="d-block">Credit limit</strong><span class="text-body-secondary">Customer limit ৳ 2,00,000 · used ৳ 48,300. This order is prepaid.</span></li>
                            <li><strong class="d-block">Branch scope</strong><span class="text-body-secondary">Dhaka HQ only. Chattogram staff cannot see this document.</span></li>
                        </ul>
                    </section>
                </div>
            </div>
        </main>
${footer()}
    </div>
</div>`;
}

/* --------------------------------------------------------------------- 4. POS */
export function pos() {
    const products = [
        ['RM-5KG', 'Rice — Miniket 5kg', 1180, 'A3'],
        ['SO-2L', 'Soybean oil 2L', 335, 'B1'],
        ['SG-1KG', 'Sugar — fresh 1kg', 128, 'B2'],
        ['ML-1L', 'Milk powder 1kg', 880, 'C4'],
        ['TZ-500', 'Tea — premium 500g', 410, 'A1'],
        ['LT-200', 'Lentil — masoor 1kg', 145, 'C2'],
    ];

    return `
${previewBar('pos.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('pos')}
    <div class="erp-main">
${topbar({ title: 'POS terminal', trail: [{ label: 'Sell' }, { label: 'Counter (POS)' }, { label: 'Terminal' }] })}
        <main class="erp-content erp-content-fluid" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Counter · Session CS-00091</p>
                    <h1 class="erp-h1">POS terminal ${statusChip('open')}</h1>
                    <p class="erp-page-sub">Cashier: Rakib · Dhaka HQ counter 2 · opened 09:58 AM with ৳ 5,000 float. Keyboard-first: F2 product, F4 payment, F8 hold, F9 complete.</p>
                </div>
                <div class="erp-page-head-actions">
                    <span class="erp-chip erp-chip-outline"><i class="bi bi-clock" aria-hidden="true"></i>Shift 4h 12m</span>
                    <button class="btn btn-outline-secondary" type="button">X-report</button>
                    <button class="btn btn-outline-secondary" type="button">Hold order</button>
                    <button class="btn btn-primary" type="button">Close session</button>
                </div>
            </header>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="erp-toolbar">
                        <div class="erp-toolbar-start">
                            <div class="erp-input-group" style="flex:1">
                                <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                <input class="form-control" placeholder="Scan barcode or type SKU / name…" autofocus>
                            </div>
                            <span class="erp-chip erp-chip-soft"><i class="bi bi-wifi" aria-hidden="true"></i>Online · offline queue empty</span>
                        </div>
                        <div class="erp-toolbar-end">
                            <button class="btn btn-outline-secondary btn-sm" type="button">Price check</button>
                            <button class="btn btn-outline-secondary btn-sm" type="button">Customers</button>
                        </div>
                    </div>

                    <div class="row g-2">
                        ${products.map(([sku, name, price, bin]) => `
                        <div class="col-sm-6 col-xl-4">
                            <button class="erp-card erp-card-tight w-100 text-start h-100" type="button">
                                <span class="erp-chip erp-chip-outline mb-2">${sku} · rack ${bin}</span>
                                <strong class="d-block mb-1">${name}</strong>
                                <span class="erp-amount">৳ ${price.toLocaleString('en-IN')}</span>
                                <span class="erp-chip erp-chip-ok ms-2">in stock</span>
                            </button>
                        </div>`).join('')}
                    </div>
                </div>

                <div class="col-lg-5">
                    <section class="erp-table-shell h-100">
                        <div class="erp-card-head px-3 pt-3">
                            <h2 class="erp-card-title"><i class="bi bi-basket" aria-hidden="true"></i> Current sale</h2>
                            <button class="btn btn-sm btn-outline-danger" type="button">Void</button>
                        </div>
                        <div class="erp-table-scroll">
                            <table class="table erp-table">
                                <thead>
                                    <tr><th>Item</th><th class="erp-th-num">Qty</th><th class="erp-th-num">Total</th></tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Rice — Miniket 5kg<div class="erp-td-muted small">৳1,180 × 12</div></td>
                                        <td class="erp-td-num">12</td>
                                        <td class="erp-td-num">৳14,160</td>
                                    </tr>
                                    <tr>
                                        <td>Soybean oil 2L<div class="erp-td-muted small">৳335 × 24</div></td>
                                        <td class="erp-td-num">24</td>
                                        <td class="erp-td-num">৳8,040</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr><td colspan="2" class="text-end">Subtotal</td><td class="erp-td-num">৳22,200</td></tr>
                                    <tr><td colspan="2" class="text-end">Discount (retailer)</td><td class="erp-td-num erp-amount-neg">− ৳1,460</td></tr>
                                    <tr><td colspan="2" class="text-end">VAT 5%</td><td class="erp-td-num">৳1,137</td></tr>
                                    <tr><td colspan="2" class="text-end">Grand total</td><td class="erp-td-num erp-amount-lg">৳21,877</td></tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="p-3 border-top" style="border-color:var(--line-soft) !important">
                            <div class="erp-segmented w-100 mb-2">
                                <button class="active flex-fill" type="button">Cash</button>
                                <button class="flex-fill" type="button">bKash</button>
                                <button class="flex-fill" type="button">Card</button>
                                <button class="flex-fill" type="button">Split</button>
                            </div>
                            <button class="btn btn-primary w-100 btn-lg" type="button">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Complete sale · ৳ 21,877
                            </button>
                            <p class="erp-widget-note mt-2 mb-0">Offline sales are queued with an idempotency key and reconciled on reconnect; the server stays authoritative.</p>
                        </div>
                    </section>
                </div>
            </div>
        </main>
${footer()}
    </div>
</div>`;
}

/* ------------------------------------------------------------------ 5. sign in */
export function login() {
    return `
${previewBar('login.html')}
<div class="erp-guest-wrap">
    <aside class="erp-guest-aside">
        <div class="erp-guest-brand" style="color:#fff">
            <span class="erp-brand-mark erp-brand-mark-inverse" aria-hidden="true">BE</span>
            <strong>BD ERP</strong>
        </div>
        <div>
            <h2>Run sales, stock and accounts from one ledger.</h2>
            <p>Bangladesh-first ERP: branch scope, Mushak documents, POS, courier settlement and a double-entry core that ties every number back to a source document.</p>
            <ul class="erp-guest-points">
                <li><i class="bi bi-shield-check" aria-hidden="true"></i> Permission-filtered navigation — you only see what you may open</li>
                <li><i class="bi bi-journal-check" aria-hidden="true"></i> Append-only audit trail on every posting</li>
                <li><i class="bi bi-box-seam" aria-hidden="true"></i> Stock truth from immutable movements, never edited balances</li>
            </ul>
        </div>
        <p class="erp-guest-foot" style="color:#8296a8;text-align:left">Rafshan Trading Ltd. · Asia/Dhaka</p>
    </aside>

    <main class="erp-guest-main">
        <div class="erp-guest-card">
            <h1 class="erp-auth-title">Sign in</h1>
            <p class="erp-auth-sub">Use your work account to continue to BD ERP.</p>

            <form onsubmit="return false">
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail address</label>
                    <input class="form-control" type="email" id="email" placeholder="you@company.com" autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" autocomplete="current-password">
                </div>
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="remember">
                        <label class="form-check-label" for="remember">Remember this device</label>
                    </div>
                    <a href="#" style="font-size:12.5px;font-weight:620">Forgot password?</a>
                </div>
                <button class="btn btn-primary w-100 btn-lg" type="submit">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign in
                </button>
            </form>
            <p class="form-text text-center mt-3 mb-0">Accounts lock after five failed attempts; sign-in events are audited.</p>
        </div>

        <p class="erp-guest-foot">BD ERP · 2026<span class="d-block">Need help? Contact your workspace administrator.</span></p>
    </main>
</div>`;
}

/* --------------------------------------------------------- 6. design system */
export function designSystem() {
    const swatches = [
        ['--canvas', 'Canvas', '#ffffff'],
        ['--surface-2', 'Surface 2', '#fafbfc'],
        ['--surface-3', 'Surface 3', '#f1f3f6'],
        ['--ink', 'Ink', '#0d1117'],
        ['--ink-2', 'Ink 2', '#4a5261'],
        ['--ink-3', 'Ink 3', '#818b9c'],
        ['--accent', 'Accent — deep teal', '#0f766e'],
        ['--accent-soft', 'Accent soft', '#eefaf7'],
        ['--ok', 'Success', '#0f7b4f'],
        ['--warn', 'Warning', '#b45309'],
        ['--danger', 'Danger', '#b42318'],
        ['--info', 'Info', '#0f6d8c'],
    ];

    const alternates = [
        ['teal', 'Deep teal', '#0f766e'],
        ['azure', 'Azure', '#0b5cab'],
        ['forest', 'Forest', '#1c7c4a'],
        ['graphite', 'Graphite', '#1f2937'],
    ];

    return `
${previewBar('index.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('dashboard')}
    <div class="erp-main">
${topbar({ title: 'Design system', trail: [{ label: 'Configuration' }, { label: 'Appearance' }, { label: 'Design system' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Redesign review</p>
                    <h1 class="erp-h1">Aperture — the white-first design system</h1>
                    <p class="erp-page-sub">
                        One configurable accent, layered near-white surfaces, hairline borders, restrained elevation and tabular numerals for money.
                        No purple anywhere (§18.1 forbids it) — the sidebar, tokens and components below are exactly what the Laravel views now render.
                    </p>
                </div>
                <div class="erp-page-head-actions">
                    <button class="btn btn-outline-secondary" type="button" data-erp-density-toggle>Toggle density</button>
                    <button class="btn btn-primary" type="button" data-erp-theme-toggle>Toggle dark mode</button>
                </div>
            </header>

            <section class="erp-card mb-3">
                <header class="erp-card-head"><h2 class="erp-card-title">Core tokens</h2></header>
                <div class="row g-2">
                    ${swatches.map(([, label, hex]) => `
                    <div class="col-6 col-md-4 col-xl-3">
                        <div class="erp-card erp-card-tight h-100">
                            <span style="display:block;height:38px;border-radius:8px;border:1px solid var(--line);background:${hex}"></span>
                            <strong class="d-block mt-2" style="font-size:12.5px">${label}</strong>
                            <code style="font-size:11.5px;color:var(--ink-3)">${hex}</code>
                        </div>
                    </div>`).join('')}
                </div>
            </section>

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Accent presets — all non-purple</h2></header>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            ${alternates.map(([code, label, hex]) => `
                            <button class="erp-chip erp-chip-outline" type="button"
                                    onclick="document.documentElement.dataset.accent='${code}'">
                                <span style="width:12px;height:12px;border-radius:50%;background:${hex};display:inline-block"></span>${label}
                            </button>`).join('')}
                        </div>
                        <p class="erp-widget-note mb-0">Operators choose the company accent in Settings › Appearance; each user still controls light/dark and row density locally. Try the chips — the whole UI re-themes from tokens.</p>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Status vocabulary — semantic, never rainbow</h2></header>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            ${['active', 'pending', 'approved', 'rejected', 'processing', 'cancelled', 'delivered', 'overdue'].map((s) => statusChip(s)).join('')}
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="erp-chip erp-chip-soft">soft chip</span>
                            <span class="erp-chip erp-chip-outline">outline chip</span>
                            <span class="erp-chip erp-chip-warn">needs attention</span>
                            <span class="erp-chip erp-chip-ok">settled</span>
                            <span class="erp-chip erp-chip-danger">over limit</span>
                        </div>
                    </section>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Buttons &amp; actions</h2></header>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button class="btn btn-primary" type="button"><i class="bi bi-check2" aria-hidden="true"></i> Primary</button>
                            <button class="btn btn-outline-secondary" type="button">Secondary</button>
                            <button class="btn btn-outline-danger" type="button"><i class="bi bi-x-circle" aria-hidden="true"></i> Destructive</button>
                            <button class="btn btn-success" type="button">Success</button>
                            <button class="btn btn-primary" type="button" disabled>Disabled</button>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <button class="btn btn-sm btn-outline-secondary" type="button">Small</button>
                            <button class="erp-icon-btn" type="button" aria-label="Icon action"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                            <kbd>⌘</kbd><kbd>K</kbd>
                            <span class="erp-status erp-status-approved">approved</span>
                        </div>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Form controls</h2></header>
                        <div class="mb-3">
                            <label class="form-label" for="ds-name">Customer name</label>
                            <input class="form-control" id="ds-name" placeholder="e.g. Nusrat Jahan">
                            <div class="form-text">Type-ahead resolves duplicates by phone number.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="ds-branch">Branch</label>
                                <select class="form-select" id="ds-branch"><option>Dhaka HQ</option><option>Chattogram</option></select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="ds-date">Delivery date</label>
                                <input class="form-control" id="ds-date" value="07/10/2026">
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="ds-vat" checked>
                            <label class="form-check-label" for="ds-vat">Apply VAT from the item tax rate master</label>
                        </div>
                    </section>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Page-state contract (§18.6)</h2>
                    <span class="erp-chip erp-chip-outline">loading · empty · error</span>
                </header>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="erp-card erp-card-tight h-100">
                            <p class="erp-field-label">Loading (skeleton)</p>
                            <span class="erp-skeleton erp-skeleton-lg mb-2" style="width:60%"></span>
                            <span class="erp-skeleton mb-2" style="width:100%"></span>
                            <span class="erp-skeleton mb-2" style="width:88%"></span>
                            <span class="erp-skeleton" style="width:72%"></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="erp-card erp-card-tight h-100 d-flex">
                            <div class="erp-empty">
                                <span class="erp-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                                <p>No orders match these filters</p>
                                <small>Widen the date range, or clear the branch filter to include Chattogram.</small>
                                <div class="erp-empty-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button">Clear filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="erp-card erp-card-tight h-100">
                            <p class="erp-field-label">Recoverable error</p>
                            <div class="erp-inline-error">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                <div>
                                    <strong class="d-block">Courier rate card unavailable</strong>
                                    Pathao did not respond. The order is saved; charges will resolve on retry.
                                    <button class="btn btn-sm btn-outline-secondary mt-2" type="button">Retry</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <p class="erp-page-sub">
                Static preview rendered from the shipped stylesheet and behaviour layer. Screens:
                <a href="./dashboard.html">Dashboard</a>,
                <a href="./orders.html">Sales orders</a>,
                <a href="./order-detail.html">Order workspace</a>,
                <a href="./pos.html">POS terminal</a>,
                <a href="./login.html">Sign in</a>.
            </p>
        </main>
${footer()}
    </div>
</div>`;
}

/* ------------------------------------------------------------ 7. 404 example */
export function notFound() {
    return `
<div class="erp-body erp-error-body">
    <main class="erp-error-wrap">
        <div class="erp-error-card">
            <p class="erp-error-code">404</p>
            <h1 class="erp-error-title">Page not found</h1>
            <p class="erp-error-text">The page you requested does not exist, or you are not allowed to see it.</p>
            <div class="erp-error-actions">
                <a class="btn btn-primary" href="./dashboard.html"><i class="bi bi-house" aria-hidden="true"></i> Back to dashboard</a>
                <button class="btn btn-outline-secondary" type="button">Go back</button>
            </div>
        </div>
    </main>
</div>`;
}

/* -------------------------------------------------------------- 8. customers (CRM) */
export function customers() {
    const buckets = [
        ['Not yet due', '৳ 7,42,180', '38 invoices', 1],
        ['Overdue 1-30 days', '৳ 4,10,900', '19 invoices', 2],
        ['Overdue 31-60 days', '৳ 2,18,450', '11 invoices', 3],
        ['Overdue 61-90 days', '৳ 1,02,300', '6 invoices', 4],
        ['Overdue 90+ days', '৳ 88,610', '4 invoices', 7],
    ];

    const rows = [
        ['Rahman Traders', 'CUST-00001', 'Business', 'Dhaka · Mirpur', 'Retailer', '৳ 50,000 / 15d', '৳ 24,580', '৳ 4,120', 'active', false],
        ['Nusrat Jahan', 'CUST-00002', 'Individual', 'Dhaka · Dhanmondi', '—', 'Cash only', '৳ 0', '৳ 0', 'active', false],
        ['Sylhet Grocers', 'CUST-00003', 'Business', 'Sylhet · Zindabazar', 'Wholesaler', '৳ 1,50,000 / 30d', '৳ 1,86,400', '৳ 96,400', 'over limit', true],
        ['Agrabad Hardware', 'CUST-00004', 'Business', 'Chattogram · Agrabad', 'Retailer', '৳ 75,000 / 20d', '৳ 12,300', '৳ 0', 'active', false],
        ['Karim Store', 'CUST-00005', 'Individual', 'Khulna · Sonadanga', '—', 'Cash only', '৳ 0', '৳ 0', 'blacklisted', true],
    ];

    const tableRows = rows.map(([name, code, type, place, group, limit, due, overdue, status, warn]) => `
        <tr>
            <td data-label="Customer">
                <a class="erp-row-link" href="./customer-profile.html">${name}</a>
                <span class="erp-td-muted d-block small">${code}${type === 'Business' ? ' · business' : ''}</span>
            </td>
            <td data-label="Contact" class="erp-td-muted">${place}</td>
            <td data-label="Group">${group === '—' ? '<span class="erp-td-muted">—</span>' : `<span class="erp-chip erp-chip-soft">${group}</span>`}</td>
            <td data-label="Credit limit" class="erp-td-num">${limit}</td>
            <td data-label="Due" class="erp-td-num">${due}</td>
            <td data-label="Overdue" class="erp-td-num">${overdue === '৳ 0' ? '<span class="erp-td-muted">—</span>' : `<span class="erp-amount erp-amount-danger">${overdue}</span>`}</td>
            <td data-label="Status">${status === 'over limit' ? '<span class="erp-status erp-status-pending">over limit</span>' : statusChip(status)}</td>
            <td data-label="Open" class="erp-td-actions">
                <a class="btn btn-sm btn-outline-secondary" href="./customer-profile.html">Profile</a>
                <a class="btn btn-sm btn-light" href="./customer-profile.html#ledger">Ledger</a>
            </td>
        </tr>`).join('');

    return `
${previewBar('customers.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('customers')}
<div class="erp-main">
${topbar({ trail: ['Sales & CRM', 'Customers'], title: 'Customers' })}
<main class="erp-content" id="erpContent">
    <header class="erp-page-head">
        <div class="erp-page-head-main">
            <p class="erp-eyebrow">Sales &amp; CRM</p>
            <h1 class="erp-h1">Customers</h1>
            <p class="erp-page-sub">Every party you sell to, with the money they owe derived from the ledgers — never from a hand-edited due column.</p>
        </div>
        <div class="erp-page-head-actions">
            <a class="btn btn-outline-secondary" href="#"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
            <a class="btn btn-outline-secondary" href="#"><i class="bi bi-alarm" aria-hidden="true"></i> Due &amp; ageing</a>
            <a class="btn btn-outline-secondary" href="#"><i class="bi bi-collection" aria-hidden="true"></i> Groups</a>
            <a class="btn btn-primary" href="#"><i class="bi bi-person-plus" aria-hidden="true"></i> New customer</a>
        </div>
    </header>

    <div class="erp-kpi-grid mb-3">
        ${buckets.map(([label, amount, hint, hue]) => `
        <div class="erp-kpi" style="--hue: var(--c${hue})">
            <p class="erp-kpi-label"><span class="erp-hue-dot" aria-hidden="true"></span>${label}</p>
            <p class="erp-kpi-value">${amount}</p>
            <p class="erp-kpi-foot">${hint}</p>
        </div>`).join('')}
    </div>

    <form class="erp-filterbar" onsubmit="return false">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" placeholder="Name, code, phone or e-mail…">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="group">Group</label>
            <select class="form-select" id="group"><option>All groups</option><option>Retailer</option><option>Wholesaler</option><option>Corporate</option></select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="district">District</label>
            <select class="form-select" id="district"><option>All districts</option><option>Dhaka</option><option>Chattogram</option><option>Sylhet</option><option>Khulna</option></select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status"><option>Any</option><option>Blacklisted</option><option>Inactive</option><option>Over credit limit</option></select>
        </div>
        <div class="erp-filterbar-actions">
            <a class="btn btn-link" href="./customers.html">Reset</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <div class="erp-table-shell" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">Customers <span class="erp-chip erp-chip-outline">5 customers</span></h2>
        </div>
        <div class="erp-table-scroll">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <th>Group</th>
                        <th class="erp-th-num">Credit limit</th>
                        <th class="erp-th-num">Due</th>
                        <th class="erp-th-num">Overdue</th>
                        <th>Status</th>
                        <th class="erp-th-actions">Open</th>
                    </tr>
                </thead>
                <tbody>${tableRows}</tbody>
            </table>
        </div>
        <div class="erp-table-foot">
            <span>5 customers</span>
            <span class="erp-td-muted">Page 1 of 1</span>
        </div>
    </div>

    <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
        <p class="erp-field-label">More in this module</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="erp-chip erp-chip-outline" href="./orders.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Sales orders</a>
            <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Sales invoices</a>
            <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Coupons &amp; promotions</a>
            <a class="erp-chip erp-chip-outline" href="#"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Sales team</a>
        </div>
    </nav>
</main>
${footer()}
</div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* -------------------------------------------------------- 9. customer 360 profile */
export function customerProfile() {
    const docs = [
        ['INV-2026-00771', '04 Oct 2026', 'paid', '৳ 18,240', '৳ 0'],
        ['INV-2026-00812', '07 Oct 2026', 'partial', '৳ 24,580', '৳ 4,120'],
        ['INV-2026-00825', '08 Oct 2026', 'issued', '৳ 9,780', '৳ 9,780'],
    ];

    const creditHistory = [
        ['৳ 20,000 → ৳ 50,000', 'Six months of on-time settlement', '12 Sep 2026'],
        ['৳ 0 → ৳ 20,000', 'First wholesale order', '02 Jun 2026'],
    ];

    return `
${previewBar('customer-profile.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('customers')}
<div class="erp-main">
${topbar({ trail: ['Sales & CRM', 'Customers', 'Rahman Traders'], title: 'Rahman Traders' })}
<main class="erp-content" id="erpContent">
    <header class="erp-page-head">
        <div class="erp-page-head-main">
            <p class="erp-eyebrow">Customer · CUST-00001</p>
            <h1 class="erp-h1">Rahman Traders</h1>
            <p class="erp-page-sub">Business · 01711-222333 · Dhaka · Group: Retailer</p>
        </div>
        <div class="erp-page-head-actions">
            <a class="btn btn-outline-secondary" href="#ledger"><i class="bi bi-journal-text" aria-hidden="true"></i> Ledger</a>
            <a class="btn btn-outline-secondary" href="#"><i class="bi bi-printer" aria-hidden="true"></i> Statement</a>
            <a class="btn btn-primary" href="#"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
        </div>
    </header>

    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <div>
            <strong>Exposure is close to the approved credit limit.</strong>
            Due ৳ 24,580 against a limit of ৳ 50,000 — 49% used, with 15-day terms.
        </div>
    </div>

    <div class="erp-kpi-grid mb-3">
        <div class="erp-kpi"><p class="erp-kpi-label">Lifetime invoiced</p><p class="erp-kpi-value">৳ 12,84,930</p><p class="erp-kpi-foot">48 invoices</p></div>
        <div class="erp-kpi"><p class="erp-kpi-label">Received</p><p class="erp-kpi-value">৳ 12,60,350</p><p class="erp-kpi-foot">Posted receipts</p></div>
        <div class="erp-kpi"><p class="erp-kpi-label">Outstanding</p><p class="erp-kpi-value">৳ 24,580</p><p class="erp-kpi-foot">2 open invoices</p></div>
        <div class="erp-kpi"><p class="erp-kpi-label">Overdue</p><p class="erp-kpi-value">৳ 4,120</p><p class="erp-kpi-foot">Oldest due 29 Sep 2026</p></div>
    </div>

    <div class="erp-split">
        <div class="erp-split-main">
            <section class="erp-card mb-3">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Recent documents</h2>
                    <div class="erp-card-actions"><a class="btn btn-sm btn-light" href="./orders.html">All invoices</a></div>
                </div>
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-0">
                        <thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="erp-th-num">Total</th><th class="erp-th-num">Due</th></tr></thead>
                        <tbody>
                            ${docs.map(([no, date, status, total, due]) => `
                            <tr>
                                <td><span class="erp-row-link">${no}</span></td>
                                <td class="erp-td-muted">${date}</td>
                                <td>${statusChip(status)}</td>
                                <td class="erp-td-num">${total}</td>
                                <td class="erp-td-num ${due === '৳ 0' ? 'erp-td-muted' : 'erp-amount-warn'}">${due}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card" id="ledger">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Ledger extract <span class="erp-chip erp-chip-outline">derived</span></h2>
                    <div class="erp-card-actions"><a class="btn btn-sm btn-light" href="#">Full ledger</a></div>
                </div>
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-0">
                        <thead><tr><th>Date</th><th>Reference</th><th>Particulars</th><th class="erp-th-num">Debit</th><th class="erp-th-num">Credit</th><th class="erp-th-num">Balance</th></tr></thead>
                        <tbody>
                            <tr class="erp-table-opening"><td class="erp-td-muted">01 Oct 2026</td><td>—</td><td><strong>Opening balance</strong></td><td colspan="3" class="erp-td-num erp-amount">16,460.00</td></tr>
                            <tr><td class="erp-td-muted">04 Oct 2026</td><td>INV-2026-00771</td><td>Sales invoice</td><td class="erp-td-num">18,240.00</td><td class="erp-td-num">—</td><td class="erp-td-num erp-amount">34,700.00</td></tr>
                            <tr><td class="erp-td-muted">05 Oct 2026</td><td>MR-2026-00318</td><td>Receipt (Cash)</td><td class="erp-td-num">—</td><td class="erp-td-num">18,240.00</td><td class="erp-td-num erp-amount">16,460.00</td></tr>
                            <tr><td class="erp-td-muted">07 Oct 2026</td><td>INV-2026-00812</td><td>Sales invoice</td><td class="erp-td-num">24,580.00</td><td class="erp-td-num">—</td><td class="erp-td-num erp-amount">41,040.00</td></tr>
                            <tr><td class="erp-td-muted">08 Oct 2026</td><td>MR-2026-00331</td><td>Receipt (bKash)</td><td class="erp-td-num">—</td><td class="erp-td-num">20,460.00</td><td class="erp-td-num erp-amount">20,580.00</td></tr>
                        </tbody>
                        <tfoot>
                            <tr><td colspan="3" class="erp-td-muted">Period totals</td><td class="erp-td-num">42,820.00</td><td class="erp-td-num">38,700.00</td><td class="erp-td-num erp-amount">20,580.00</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>

        <aside class="erp-split-side">
            <section class="erp-card mb-3">
                <h2 class="erp-card-title mb-3">Credit control</h2>
                <dl class="erp-dl erp-dl-tight">
                    <dt>Credit limit</dt><dd>৳ 50,000.00</dd>
                    <dt>Credit days</dt><dd>15 days</dd>
                    <dt>Exposure</dt><dd>৳ 24,580.00</dd>
                    <dt>Segment</dt><dd>Regular</dd>
                    <dt>Loyalty points</dt><dd>1,240</dd>
                </dl>

                <form class="mt-3" onsubmit="return false">
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label" for="limit">New limit (৳)</label><input class="form-control" id="limit" value="50000.00"></div>
                        <div class="col-6"><label class="form-label" for="days">Credit days</label><input class="form-control" id="days" value="15"></div>
                        <div class="col-12"><label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" placeholder="e.g. 6 months of on-time settlement"></div>
                    </div>
                    <button class="btn btn-outline-secondary w-100 mt-2" type="submit">Record new limit</button>
                </form>

                <div class="erp-timeline mt-3">
                    ${creditHistory.map(([change, reason, when]) => `
                    <div class="erp-timeline-item">
                        <span class="erp-timeline-marker" aria-hidden="true"></span>
                        <div class="erp-timeline-body"><strong>${change}</strong><p class="mb-0 small">${reason} · ${when}</p></div>
                    </div>`).join('')}
                </div>
            </section>

            <section class="erp-card mb-3">
                <h2 class="erp-card-title mb-3">Relationship health <span class="erp-chip erp-chip-ok">NPS 67</span></h2>
                <div class="erp-timeline">
                    <div class="erp-timeline-item"><span class="erp-timeline-marker erp-timeline-promoter" aria-hidden="true"></span>
                        <div class="erp-timeline-body"><strong>9/10 · promoter</strong><p class="mb-0 small">Delivery on time, invoice printed correctly · 28 Sep 2026</p></div></div>
                    <div class="erp-timeline-item"><span class="erp-timeline-marker erp-timeline-passive" aria-hidden="true"></span>
                        <div class="erp-timeline-body"><strong>8/10 · passive</strong><p class="mb-0 small">Wants earlier dispatch on Fridays · 12 Sep 2026</p></div></div>
                    <div class="erp-timeline-item"><span class="erp-timeline-marker erp-timeline-promoter" aria-hidden="true"></span>
                        <div class="erp-timeline-body"><strong>10/10 · promoter</strong><p class="mb-0 small">Referred Agrabad Hardware · 05 Sep 2026</p></div></div>
                </div>
            </section>

            <section class="erp-card">
                <h2 class="erp-card-title mb-3">Blacklist</h2>
                <p class="erp-td-muted small">A blacklist entry requires a reason and refuses new documents until it is lifted.</p>
                <form onsubmit="return false">
                    <label class="form-label" for="blacklist_reason">Reason (required)</label>
                    <textarea class="form-control" id="blacklist_reason" rows="2" placeholder="Recorded against the audit trail"></textarea>
                    <button class="btn btn-danger w-100 mt-2" type="submit">Blacklist customer</button>
                </form>
            </section>
        </aside>
    </div>
</main>
${footer()}
</div>
</div>
<script>window.erpNavIndex = [];</script>`;
}
