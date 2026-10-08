/**
 * Static preview page bodies.
 *
 * Layout samples only: the numbers below are illustrative and exist to show
 * density, alignment and state handling. They are NOT seeded business data and
 * never reach the application (global invariant: no fake data in the app).
 */
import { previewBar, sidebar, topbar, footer } from './shell.mjs';
import { SAMPLE_BARCODE, SAMPLE_QR } from './samples.mjs';

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

/* ------------------------------------------- 10. packaging: types, stock, cost */
export function packaging() {
    const types = [
        ['BOX-12', 'Corrugated box, 12 inch', 'BOX-SKU-1 · Corrugated Box', '1,248.0000', '15,600.00', '12.5000', '420.0000', '08 Oct 2026', 'active', 'Usable', null],
        ['BAG-KR', 'Kraft paper bag, large', 'BAG-SKU-1 · Kraft bag', '86.0000', '2,408.00', '28.0000', '120.0000', '07 Oct 2026', 'active', 'Usable', null],
        ['TAPE-2', 'Packing tape, 2 inch', 'TAPE-SKU-1 · Tape roll', '14.0000', '322.00', '23.0000', '36.0000', '08 Oct 2026', 'active', 'Usable', 'Lasts about 12 d at the last 30 days\u2019 rate'],
        ['FOIL-500', 'Aluminium foil roll, 500 m', 'FOIL-SKU-1 · Foil roll', '0.0000', '0.00', null, '0.0000', 'never', 'suspended', 'Cannot be used', 'Its product is no longer stock-managed or active, so it cannot be consumed.'],
        ['CRATE-W', 'Wooden crate, wholesale', 'CRATE-SKU-1 · Crate', '64.0000', '19,200.00', '300.0000', '0.0000', '22 Sep 2026', 'inactive', 'Retired', 'Retired — history kept, consumption refused'],
    ];

    const rows = types.map(([code, name, product, onHand, value, avg, used, last, state, label, note]) => `
                    <tr>
                        <td data-label="Code" class="erp-cell-strong">${code}</td>
                        <td data-label="Packaging">${name}</td>
                        <td data-label="Stock product"><span class="erp-cell-strong">${product.split(' · ')[1]}</span><span class="d-block erp-td-muted">${product.split(' · ')[0]}</span></td>
                        <td data-label="On the shelf" class="erp-td-num">${onHand}</td>
                        <td data-label="Value now" class="erp-td-num">৳ ${value}</td>
                        <td data-label="Avg unit cost" class="erp-td-num erp-td-muted">${avg ?? '—'}</td>
                        <td data-label="Used, 30 d" class="erp-td-num erp-td-muted">${used}</td>
                        <td data-label="Last used" class="erp-td-muted">${last}</td>
                        <td data-label="State">${statusChip(state, label)}${note ? `<span class="d-block erp-td-muted">${note}</span>` : ''}</td>
                        <td data-label="Manage" class="erp-td-actions">
                            <div class="d-flex gap-1 justify-content-end">
                                <button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</button>
                                <button class="btn btn-sm btn-outline-secondary" type="button">${state === 'inactive' ? 'Bring back' : 'Retire'}</button>
                            </div>
                        </td>
                    </tr>`).join('');

    return `
${previewBar('packaging.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('inventory')}
    <div class="erp-main">
${topbar({ title: 'Packaging types', trail: [{ label: 'Inventory & warehouse' }, { label: 'Packaging' }, { label: 'Packaging types' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Inventory &middot; Packaging</p>
                    <h1 class="erp-h1">What the goods travel in</h1>
                    <p class="erp-page-sub">A packaging type is not a price list — it is a promise that stock can be taken out of the warehouse under that name. So every type points at a real stock-managed product, its shelf comes from the ledger, and the money comes from the valuation layers that priced it.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./packaging.html"><i class="bi bi-boxes" aria-hidden="true"></i> Packaging stock</a>
                    <a class="btn btn-outline-secondary" href="./packaging.html"><i class="bi bi-cash-stack" aria-hidden="true"></i> Packaging cost</a>
                    <a class="btn btn-outline-secondary" href="./packaging.html"><i class="bi bi-clipboard-data" aria-hidden="true"></i> Monthly report</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <section class="erp-kpi-grid mb-3">
                <div class="erp-kpi" style="--hue: var(--c2)">
                    <p class="erp-kpi-label"><span class="erp-hue-dot" aria-hidden="true"></span>Types declared</p>
                    <p class="erp-kpi-value">5</p>
                    <p class="erp-kpi-foot">Products this company packs with</p>
                </div>
                <div class="erp-kpi" style="--hue: var(--c1)">
                    <p class="erp-kpi-label"><span class="erp-hue-dot" aria-hidden="true"></span>Usable</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Not retired, and their product still stocked</p>
                </div>
                <div class="erp-kpi" style="--hue: var(--c3)">
                    <p class="erp-kpi-label"><span class="erp-hue-dot" aria-hidden="true"></span>On the shelf</p>
                    <p class="erp-kpi-value">1,412.0000</p>
                    <p class="erp-kpi-foot">Summed across warehouses, from the ledger</p>
                </div>
                <div class="erp-kpi" style="--hue: var(--c4)">
                    <p class="erp-kpi-label"><span class="erp-hue-dot" aria-hidden="true"></span>Consumed, 30 days</p>
                    <p class="erp-kpi-value">৳ 18,420.00</p>
                    <p class="erp-kpi-foot">Layer-valued cost of what went out</p>
                </div>
            </section>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    A used type is <strong>retired, never deleted</strong> — the movements that priced it would otherwise point at
                    nothing — and once a type has consumed anything its code and product are <strong>frozen</strong>: the
                    consumption already priced what it took.
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false">
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="q">Search</label>
                    <div class="erp-input-group">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" type="search" id="q" placeholder="Code, name or the product behind it…">
                    </div>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="state">State</label>
                    <select class="form-select" id="state"><option>Any state</option><option>Usable</option><option>Retired</option><option>Cannot be used</option></select>
                </div>
                <div class="erp-filterbar-actions">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Declared types <span class="erp-chip erp-chip-outline">5 types</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Packaging</th>
                                <th>Stock product</th>
                                <th class="erp-th-num">On the shelf</th>
                                <th class="erp-th-num">Value now</th>
                                <th class="erp-th-num">Avg unit cost</th>
                                <th class="erp-th-num">Used, 30 d</th>
                                <th>Last used</th>
                                <th>State</th>
                                <th class="erp-th-actions">Manage</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div class="erp-table-foot">
                    <span class="erp-td-muted">Consumption is priced by the valuation layers at the moment it happens — never by a figure typed on the type.</span>
                    <span class="erp-td-muted">Page 1 of 1</span>
                </div>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Declare a packaging type</h2>
                    <span class="erp-chip erp-chip-soft">stock-managed products only</span>
                </header>
                <form onsubmit="return false">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="new_code">Code</label>
                            <input class="form-control" type="text" id="new_code" placeholder="BOX-12">
                            <div class="form-text">What the floor calls it. Letters, digits, dot, dash and underscore.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="new_name">Name</label>
                            <input class="form-control" type="text" id="new_name" placeholder="Corrugated box, 12 inch">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="new_product">Stock product</label>
                            <select class="form-select" id="new_product">
                                <option>Choose the product this packaging is…</option>
                                <option>BOX-SKU-1 — Corrugated Box</option>
                                <option>BAG-SKU-1 — Kraft bag</option>
                                <option>TAPE-SKU-1 — Tape roll</option>
                            </select>
                            <div class="form-text">A product cannot be consumed as packaging unless it is stock-managed and active — the ledger refuses anything else.</div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3" type="button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Declare type</button>
                </form>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./packaging.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Packaging stock</a>
                    <a class="erp-chip erp-chip-outline" href="./packaging.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Packaging cost</a>
                    <a class="erp-chip erp-chip-outline" href="./packaging.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Packaging report</a>
                    <a class="erp-chip erp-chip-outline" href="./dashboard.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Stock overview</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* -------------------------------------- 11. label desk: barcodes and QR codes */
export function labels() {
    const sheets = [
        ['labels-20261008-0915-4b7c1a2e.html', '24 labels · A4 sheet, 24 labels (3 × 8)', '18.4 KB', '08 Oct 2026, 09:15', 'Dhaka HQ', '4 people printed it'],
        ['labels-20261007-1740-c19e6d84.html', '12 labels · Thermal roll, 50 × 25 mm', '9.1 KB', '07 Oct 2026, 17:40', 'Dhaka HQ', '2 people printed it'],
        ['labels-20261007-1102-77aa0f31.html', '240 labels · A4 sheet, 21 labels (3 × 7)', '96.7 KB', '07 Oct 2026, 11:02', 'company-wide', 'not printed yet'],
    ];

    const rows = sheets.map(([name, what, size, when, branch, prints]) => `
                    <tr>
                        <td data-label="Sheet"><span class="erp-cell-strong">${name}</span><span class="d-block erp-td-muted" style="font-family:var(--font-mono, monospace)">sha256 9f2c…${name.slice(-8, -5)}</span></td>
                        <td data-label="What it is">${what}</td>
                        <td data-label="Size" class="erp-td-num erp-td-muted">${size}</td>
                        <td data-label="Filed" class="erp-td-muted">${when}<span class="d-block erp-td-muted">${branch}</span></td>
                        <td data-label="Prints" class="erp-td-muted">${prints}</td>
                        <td data-label="" class="erp-td-actions">
                            <div class="d-flex flex-wrap align-items-center gap-1 justify-content-end">
                                <button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open</button>
                                <form class="d-flex align-items-center gap-1" method="POST" action="./labels.html">
                                    <label class="visually-hidden" for="print_copies_x">Copies printed</label>
                                    <input class="form-control form-control-sm" style="width:4.5rem" type="number" id="print_copies_x" name="copies" value="1" min="1" max="200">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" title="Record a print run of this sheet"><i class="bi bi-printer" aria-hidden="true"></i> Record print</button>
                                </form>
                            </div>
                        </td>
                    </tr>`).join('');

    return `
${previewBar('labels.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('inventory')}
    <div class="erp-main">
${topbar({ title: 'Label desk', trail: [{ label: 'Inventory & warehouse' }, { label: 'Barcode & QR' }, { label: 'Print labels' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Inventory &middot; Labels &amp; barcodes</p>
                    <h1 class="erp-h1">What goes on the sticker</h1>
                    <p class="erp-page-sub">A label is a promise to a scanner: it carries the product's own code, printed wide enough that a hand scanner reads it first time. Nothing is filed from this screen until you ask for it, and every sheet you do file keeps its own checksum and its own print log.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-upc-scan" aria-hidden="true"></i> One barcode</a>
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-qr-code" aria-hidden="true"></i> One QR code</a>
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-broadcast" aria-hidden="true"></i> Scanner test</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <div>Filed 24 label(s) on 1 page(s) — 0.35 mm per bar at the narrowest. <a class="fw-semibold" href="./labels.html">Open the filed sheet</a></div>
            </div>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-rulers" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">2 things to look at before this sheet is printed</strong>
                    <ul class="mb-1 ps-3">
                        <li>A very long code: a 484-module code needs 121.0 mm at the smallest printable bar, but this label leaves 32.0 mm. Use a wider label, or encode a shorter code for this product.</li>
                        <li>Corrugated box, 12 inch: there is no room for a readable barcode and a QR on a 38 mm label, so the QR was left off — the bars are what the warehouse scanner reads.</li>
                    </ul>
                    <span class="d-block mt-1">A narrower bar than 0.25 mm may still scan on a good desk scanner and will fail on a cheap one. Change the paper and generate again — the previous sheet stays filed and can be ignored.</span>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">What is being labelled</h2>
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">A4 sheet, 24 labels (3 × 8, 70 × 37 mm)</span></div>
                </header>

                <p class="erp-field-label mb-2">This run labels</p>
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <div class="form-check"><input class="form-check-input" type="radio" name="subject_type" id="st_product" checked><label class="form-check-label" for="st_product">Products — a shelf label or a shelf-sticker for the catalogue row</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="subject_type" id="st_batch"><label class="form-check-label" for="st_batch">Batches — a sticker for one received lot, with its expiry</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="subject_type" id="st_order"><label class="form-check-label" for="st_order">Sales orders — a pick/parcel sticker for the order</label></div>
                    <div class="form-check"><input class="form-check-input" type="radio" name="subject_type" id="st_invoice"><label class="form-check-label" for="st_invoice">Invoices — a sticker for the parcel that carries an invoice</label></div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="template">Sheet</label>
                        <select class="form-select" id="template">
                            <option>A4 sheet, 24 labels (3 × 8, 70 × 37 mm)</option>
                            <option>A4 sheet, 21 labels (3 × 7, 63.5 × 38.1 mm)</option>
                            <option>Thermal roll, 50 × 25 mm</option>
                            <option>Thermal roll, 38 × 25 mm</option>
                        </select>
                        <div class="form-text">4 papers are wired in. The sheet is laid out to the millimetre — a run is generated, filed, and printed as one document.</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="copies">Copies each</label>
                        <input class="form-control" type="number" min="1" max="200" id="copies" value="1">
                        <div class="form-text">One run may produce 500 labels.</div>
                    </div>
                    <div class="col-md-6">
                        <p class="erp-field-label mb-2">On every label</p>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="lb_company" checked><label class="form-check-label" for="lb_company">Company name</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="lb_price" checked><label class="form-check-label" for="lb_price">Price and unit</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="lb_code" checked><label class="form-check-label" for="lb_code">The code in text</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="lb_qr"><label class="form-check-label" for="lb_qr">A QR beside the bars</label></div>
                            <div>
                                <label class="form-label" for="qr_level">QR level</label>
                                <select class="form-select form-select-sm" id="qr_level">
                                    <option>L — 7 % recoverable (densest)</option>
                                    <option selected>M — 15 % (the usual choice)</option>
                                    <option>Q — 25 %</option>
                                    <option>H — 30 % (survives a scuff)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Products <span class="erp-chip erp-chip-outline">3 shown</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr><th scope="col" style="width:2.4rem;"><input class="form-check-input" type="checkbox" aria-label="Tick every product"></th><th>Product</th><th>What the label would carry</th><th>Unit</th><th>State</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td data-label="Tick"><input class="form-check-input" type="checkbox" checked></td>
                                <td data-label="Product"><span class="erp-cell-strong">Corrugated box, 12 inch</span><span class="d-block erp-td-muted">PKD-BOX · PKD-BOX-12</span></td>
                                <td data-label="Payload"><span class="font-monospace">8801234567890</span><span class="d-block erp-td-muted">its barcode</span></td>
                                <td data-label="Unit">pc</td>
                                <td data-label="State">${statusChip('active', 'stock-managed')}</td>
                            </tr>
                            <tr>
                                <td data-label="Tick"><input class="form-check-input" type="checkbox" checked></td>
                                <td data-label="Product"><span class="erp-cell-strong">Kraft paper bag, large</span><span class="d-block erp-td-muted">BAG-KR · BAG-KR-L</span></td>
                                <td data-label="Payload"><span class="font-monospace">BAG-KR-L</span><span class="d-block erp-td-muted">no barcode — the SKU stands in</span></td>
                                <td data-label="Unit">pc</td>
                                <td data-label="State">${statusChip('active', 'stock-managed')}</td>
                            </tr>
                            <tr>
                                <td data-label="Tick"><input class="form-check-input" type="checkbox"></td>
                                <td data-label="Product"><span class="erp-cell-strong">Packing tape, 2 inch</span><span class="d-block erp-td-muted">TAPE-2 · TAPE-SKU-1</span></td>
                                <td data-label="Payload"><span class="font-monospace">8801234567890</span><span class="d-block erp-td-muted">its barcode</span></td>
                                <td data-label="Unit">roll</td>
                                <td data-label="State">${statusChip('active', 'stock-managed')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="erp-split mt-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">One QR code, with the whole payload</h2>
                        <div class="erp-card-actions"><a class="btn btn-sm btn-outline-secondary" href="./labels.html"><i class="bi bi-download" aria-hidden="true"></i> Save SVG</a></div>
                    </header>
                    <div class="erp-label-preview">${SAMPLE_QR}</div>
                    <p class="text-muted mb-0 mt-2">Product 12 · https://erp.example.com/app/inventory/products/12</p>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head"><h2 class="erp-card-title">One barcode, shown honestly</h2></header>
                        <div class="erp-label-preview" style="padding:16px 12px;">${SAMPLE_BARCODE}</div>
                        <dl class="erp-dl erp-dl-tight erp-dl-striped mt-3">
                            <dt>Encoded</dt><dd><span class="font-monospace">8801234567890</span></dd>
                            <dt>Symbol</dt><dd>Code 128 · subset B · 16 symbols (start, 13 data, check, stop)</dd>
                            <dt>Check digit</dt><dd>47 — weighted mod 103, so a scanner can tell a misread from a read</dd>
                            <dt>Worth</dt><dd>132 modules of bars, plus a 10-module quiet zone at each end: 152 modules across</dd>
                        </dl>
                    </div>
                </aside>
            </div>

            <div class="erp-table-shell mt-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Sheets filed <span class="erp-chip erp-chip-outline">3 most recent</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead><tr><th>Sheet</th><th>What it is</th><th class="erp-th-num">Size</th><th>Filed</th><th>Prints</th><th></th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./labels.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>One barcode</a>
                    <a class="erp-chip erp-chip-outline" href="./labels.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>One QR code</a>
                    <a class="erp-chip erp-chip-outline" href="./labels.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Scanner test</a>
                    <a class="erp-chip erp-chip-outline" href="./packaging.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Packaging types</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* ------------------------------------------------ 12. cash & bank: the money desk */
export function cashBank() {
    const positions = [
        ['1110', 'Cash in Hand', 'Cash · the drawer in the office', '318,450.00', '412 lines', '08 Oct 2026', true],
        ['1121', 'Islami Bank — current account', 'Bank · Islami Bank Bangladesh', '1,246,800.50', '188 lines', '08 Oct 2026', true],
        ['1122', 'City Bank — collection account', 'Bank · City Bank', '96,120.00', '64 lines', '07 Oct 2026', false],
        ['1123', 'bKash merchant float', 'Mobile wallet · bKash', '42,780.00', '310 lines', '08 Oct 2026', false],
        ['1124', 'Nagad float — counter 2', 'Mobile wallet · Nagad', '8,940.00', '96 lines', '06 Oct 2026', false],
    ];

    const rows = positions.map(([code, name, kind, balance, lines, last]) => `
                    <tr>
                        <td data-label="Account"><span class="erp-cell-strong">${name}</span><span class="d-block erp-td-muted" style="font-family:var(--font-mono,monospace)">${code}</span></td>
                        <td data-label="Kind">${kind}</td>
                        <td data-label="Balance" class="erp-td-num"><span class="erp-cell-strong">৳ ${balance}</span></td>
                        <td data-label="Last movement" class="erp-td-muted">${last}<span class="d-block erp-td-muted">${lines} in the ledger</span></td>
                        <td data-label="" class="erp-td-actions"><button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-journal-text" aria-hidden="true"></i> Book</button></td>
                    </tr>`).join('');

    const day = [
        ['08 Oct', '86,400.00', '41,250.00'], ['07 Oct', '124,900.00', '96,300.00'],
        ['06 Oct', '18,240.00', '22,480.00'], ['05 Oct', '240,000.00', '188,600.00'],
    ].map(([d, moneyIn, moneyOut]) => {
        const net = Number(moneyIn.replace(/,/g, '')) - Number(moneyOut.replace(/,/g, ''));
        return `
                            <tr>
                                <td data-label="Day" class="erp-td-muted">${d}</td>
                                <td data-label="In" class="erp-td-num">${moneyIn}</td>
                                <td data-label="Out" class="erp-td-num">${moneyOut}</td>
                                <td data-label="Net" class="erp-td-num"><span class="erp-cell-strong${net < 0 ? ' erp-money-out' : ''}">${net.toLocaleString('en-US', { minimumFractionDigits: 2 })}</span></td>
                            </tr>`;
    }).join('');

    const book = [
        ['01 Oct 2026', 'CT-2026-00031', 'Opening balance', '', '', '286,700.00'],
        ['02 Oct 2026', 'MR-2026-00418', 'Deposit — Rahmania Store, order 4412', '42,000.00', '', '328,700.00'],
        ['03 Oct 2026', 'EX-2026-00214', 'October rent', '', '96,000.00', '232,700.00'],
        ['04 Oct 2026', 'CT-2026-00032', 'Transfer to City Bank collection', '', '50,000.00', '182,700.00'],
        ['06 Oct 2026', 'MR-2026-00419', 'Courier COD settlement', '135,750.00', '', '318,450.00'],
    ].map(([d, entry, what, moneyIn, moneyOut, balance]) => `
                            <tr>
                                <td data-label="Date" class="erp-td-muted">${d}</td>
                                <td data-label="Entry" class="font-monospace">${entry}</td>
                                <td data-label="What it was">${what}</td>
                                <td data-label="In" class="erp-td-num">${moneyIn ? `<span class="erp-money-in">${moneyIn}</span>` : '<span class="erp-td-muted">—</span>'}</td>
                                <td data-label="Out" class="erp-td-num">${moneyOut ? `<span class="erp-money-out">${moneyOut}</span>` : '<span class="erp-td-muted">—</span>'}</td>
                                <td data-label="Balance" class="erp-td-num"><span class="erp-cell-strong">${balance}</span></td>
                            </tr>`).join('');

    return `
${previewBar('cash-bank.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('cash_bank')}
    <div class="erp-main">
${topbar({ title: 'Cash & bank', trail: [{ label: 'Cash & Bank' }, { label: 'Cash Management' }, { label: 'Cash in Hand' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Cash &amp; Bank &middot; Cash Management</p>
                    <h1 class="erp-h1">Where the money is</h1>
                    <p class="erp-page-sub">Every figure on this page is the sum of posted journal lines on the account — a drawer is not a spreadsheet of its own, it is an account in the books that money can sit in. Count the till, call the bank, and if the two disagree the disagreement is a movement nobody has entered here yet.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Record a receipt</a>
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Record a payment</a>
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Move money</a>
                    <a class="btn btn-outline-secondary" href="./labels.html"><i class="bi bi-bank" aria-hidden="true"></i> Money accounts</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <div>Transfer CT-2026-00033 posted — 50,000.00 from Cash in Hand to City Bank — collection account.</div>
            </div>

            <section class="erp-kpi-grid mb-3">
                <div class="erp-kpi"><span class="erp-kpi-label">Cash in hand</span><span class="erp-kpi-value">৳ 318,450.00</span><span class="erp-kpi-foot">Tills and cash drawers, from the books</span></div>
                <div class="erp-kpi"><span class="erp-kpi-label">In the bank</span><span class="erp-kpi-value">৳ 1,342,920.50</span><span class="erp-kpi-foot">Every bank account added up</span></div>
                <div class="erp-kpi"><span class="erp-kpi-label">Mobile wallets</span><span class="erp-kpi-value">৳ 51,720.00</span><span class="erp-kpi-foot">bKash, Nagad, Rocket and Upay balances</span></div>
                <div class="erp-kpi"><span class="erp-kpi-label">Money in the company</span><span class="erp-kpi-value">৳ 1,713,090.50</span><span class="erp-kpi-foot">The three totals above, added up</span></div>
            </section>

            <form class="erp-filterbar" method="GET" action="./cash-bank.html">
                <div class="erp-filter">
                    <label class="form-label" for="branch">Branch</label>
                    <select class="form-select" id="branch" name="branch">
                        <option>Every branch</option>
                        <option>Dhaka HQ (head office)</option>
                        <option>Chattogram depot</option>
                    </select>
                </div>
                <div class="erp-filter-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    A branch sees the accounts its movements were posted in. Money entered at head office and spent at a counter is one position until somebody says otherwise.
                </div>
            </form>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Money accounts <span class="erp-chip erp-chip-outline">5 account(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr><th>Account</th><th>Kind</th><th class="erp-th-num">Balance</th><th>Last movement</th><th></th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>

            <div class="erp-split mt-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Money in and out, last 14 days</h2>
                        <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">taken from the movement documents</span></div>
                    </header>
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead><tr><th>Day</th><th class="erp-th-num">In</th><th class="erp-th-num">Out</th><th class="erp-th-num">Net</th></tr></thead>
                            <tbody>${day}</tbody>
                        </table>
                    </div>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head"><h2 class="erp-card-title">Latest movements</h2></header>
                        <div class="erp-dl erp-dl-tight erp-dl-striped">
                            <dt>MR-2026-00419</dt>
                            <dd><span class="erp-money-in">+ 135,750.00</span> into Cash in Hand<span class="d-block erp-td-muted">06 Oct 2026 · Courier COD settlement</span></dd>
                            <dt>EX-2026-00215</dt>
                            <dd><span class="erp-money-out">− 12,480.00</span> from bKash merchant float<span class="d-block erp-td-muted">06 Oct 2026 · Packaging supplier, no bill</span></dd>
                            <dt>CT-2026-00032</dt>
                            <dd>50,000.00 moved<span class="d-block erp-td-muted">04 Oct 2026 · Cash in Hand → City Bank — collection account</span></dd>
                        </div>
                    </div>
                </aside>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Cash in Hand — the book</h2>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-outline">opening brought forward, running balance per row</span>
                        <button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-download" aria-hidden="true"></i> CSV</button>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead><tr><th>Date</th><th>Entry</th><th>What it was</th><th class="erp-th-num">In</th><th class="erp-th-num">Out</th><th class="erp-th-num">Balance</th></tr></thead>
                        <tbody>${book}</tbody>
                    </table>
                </div>
                <div class="erp-note erp-note-info m-3">
                    <i class="bi bi-bank" aria-hidden="true"></i>
                    <div>
                        <strong class="d-block mb-1">Matching this against a statement</strong>
                        Tick the lines the bank also shows. What is left on this page is money recorded here that has not cleared yet. What is on the statement and not here is a movement nobody has entered — that is the number to hunt, because the books are only as good as the movements they were told about.
                    </div>
                </div>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash Receipts</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash Payments</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash Transfer</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Bank Accounts</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/**
 * §08-08/09/12 — the reconciliation desk, mirrored from
 * resources/views/cash-bank/reconciliation.blade.php. Same markup the Blade
 * view renders: the proof on the left, the signature on the right, and the
 * leftovers the bank's arithmetic cannot explain spelled out underneath.
 */
export function bankRecon() {
    const leftovers = [
        {
            date: '28 Sep 2026', what: 'Cheque 004512 — rent, not yet presented', reference: '004512',
            amount: '26,000.00', out: true, pair: true,
        },
    ];

    const onStatement = [
        {
            date: '30 Sep 2026', what: 'Bank charge — quarterly account fee', reference: '',
            amount: '1,250.00', out: true,
        },
        {
            date: '30 Sep 2026', what: 'Transfer received — Rahmania Store', reference: 'SO-2026-00412',
            amount: '42,000.00', out: false,
        },
    ];

    const matched = [
        ['02 Sep 2026', '004512', 'Chq 004511 cleared — Meghna Traders', '118,500.00', 'same amount, close in date', true],
        ['04 Sep 2026', 'SO-2026-00411', 'Deposit — Meghna Traders', '118,500.00', 'by hand', false],
    ];

    return `
${previewBar('bank-recon.html')}
<div class="erp-shell" data-rail="expanded">
${sidebar('cash_bank')}
    <div class="erp-main">
${topbar({ title: 'Bank reconciliation', trail: [{ label: 'Cash & Bank' }, { label: 'Bank Accounts' }, { label: 'Bank Reconciliation' }] })}
        <main class="erp-content" id="erpContent">
            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Cash &amp; Bank &middot; Bank accounts &middot; 1121</p>
                    <h1 class="erp-h1">Islami Bank — current account</h1>
                    <p class="erp-page-sub">A reconciliation is an argument, not a formality: it lines the bank's statement up against the posted journal lines on this account and names what is left over on each side. The figures below are frozen on the day it was opened, so this page reads the same next year as it does now — and it cannot be signed off until the leftovers explain the whole gap.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-journal-text" aria-hidden="true"></i> The book</a>
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Statement desk</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-ok mb-3">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <div>Everything is explained: the bank and the books agree to the paisa.</div>
            </div>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-text" aria-hidden="true"></i> Book balance at period end</p>
                    <p class="erp-kpi-value">৳ 1,246,800.50</p>
                    <p class="erp-kpi-foot">Posted lines up to 2026-09-30</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-bank" aria-hidden="true"></i> Statement closing</p>
                    <p class="erp-kpi-value">৳ 1,264,050.50</p>
                    <p class="erp-kpi-foot">The bank's own figure for 2026-09-30</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calculator" aria-hidden="true"></i> Expected closing</p>
                    <p class="erp-kpi-value">৳ 1,264,050.50</p>
                    <p class="erp-kpi-foot">Books plus what the bank moved, less what the bank has not seen</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-question-circle" aria-hidden="true"></i> Unexplained</p>
                    <p class="erp-kpi-value">৳ 0.00</p>
                    <p class="erp-kpi-foot">Must reach zero before anybody may sign it off</p>
                </div>
            </div>

            <div class="erp-split">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">The proof <span class="erp-status erp-status-balanced">balanced</span></h2>
                    </header>
                    <div class="p-3">
                        <div class="erp-dl erp-dl-tight erp-dl-striped">
                            <dt>Book balance at period end</dt>
                            <dd class="erp-money-flat">1,246,800.50</dd>

                            <dt>Add: statement lines not in the books</dt>
                            <dd>
                                <span class="erp-money-in">+43,250.00</span>
                                <span class="d-block erp-td-muted">2 line(s) the bank moved that these books have not been told about</span>
                            </dd>

                            <dt>Less: book lines the bank has not processed</dt>
                            <dd>
                                <span class="erp-money-out">−26,000.00</span>
                                <span class="d-block erp-td-muted">1 line in these books the bank has not seen</span>
                            </dd>

                            <dt>Expected statement closing</dt>
                            <dd class="erp-cell-strong">1,264,050.50</dd>

                            <dt>Statement closing, as the bank states it</dt>
                            <dd class="erp-cell-strong">1,264,050.50</dd>

                            <dt>Unexplained</dt>
                            <dd>
                                <span class="erp-money-flat">0.00</span>
                                <span class="d-block erp-td-muted">Every paisa of the gap is accounted for by the leftover lines on both sides.</span>
                            </dd>
                        </div>

                        <div class="erp-filter-note mt-3">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span>The bank's opening figure for this period was 1,220,050.50; the books carried 1,220,050.50 at the same moment. When those two agree, the leftovers below are the whole story — when they do not, the difference is the number to chase and no amount of pairing moves it.</span>
                        </div>
                    </div>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head"><h2 class="erp-card-title">Sign-off</h2></header>
                        <div class="p-3">
                            <div class="erp-dl erp-dl-tight">
                                <dt>Prepared by</dt>
                                <dd>Nusrat Jahan<span class="d-block erp-td-muted">30 Sep 2026 17:42</span></dd>
                                <dt>Signed off</dt>
                                <dd>Abdul Karim<span class="d-block erp-td-muted">01 Oct 2026 09:15</span></dd>
                            </div>

                            <div class="erp-note erp-note-ok mt-3">
                                <i class="bi bi-lock" aria-hidden="true"></i>
                                <div>Signed off. The lines below are history — nothing here can be re-matched afterwards.</div>
                            </div>
                        </div>
                    </div>

                    <div class="erp-card mt-3">
                        <header class="erp-card-head"><h2 class="erp-card-title">While it is still open</h2></header>
                        <div class="p-3">
                            <label class="form-label" for="statement_closing">The bank's closing figure</label>
                            <div class="erp-input-group">
                                <input class="form-control" id="statement_closing" name="statement_closing" type="number" step="0.01" value="1264050.50" disabled>
                                <button class="btn btn-outline-secondary" type="button" disabled>Correct it</button>
                            </div>
                            <p class="erp-td-muted mt-2 mb-0">Read it straight off the statement's closing row — reading it off the wrong row is the commonest way this desk fails, which is why it can be corrected while the period is open and never afterwards.</p>
                        </div>
                    </div>
                </aside>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">In the books, not on the statement <span class="erp-chip erp-chip-outline">1 line</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead><tr><th>Date</th><th>Reference</th><th>What it was</th><th class="erp-th-num">Amount</th></tr></thead>
                        <tbody>
${leftovers.map((row) => `                            <tr>
                                <td class="erp-td-muted">${row.date}</td>
                                <td class="font-monospace">${row.reference}</td>
                                <td>${row.what}</td>
                                <td class="erp-td-num"><span class="erp-money-out">${row.amount}</span></td>
                            </tr>`).join('\n')}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">On the statement, not in the books <span class="erp-chip erp-chip-outline">2 lines</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead><tr><th>Value date</th><th>What the bank says it was</th><th>Reference</th><th class="erp-th-num">Amount</th><th>Match by hand</th></tr></thead>
                        <tbody>
${onStatement.map((row, index) => `                            <tr>
                                <td class="erp-td-muted">${row.date}</td>
                                <td>${row.what}</td>
                                <td class="font-monospace">${row.reference || '—'}</td>
                                <td class="erp-td-num"><span class="${row.out ? 'erp-money-out' : 'erp-money-in'}">${row.amount}</span></td>
                                <td>${index === 0
                                    ? '<span class="erp-td-muted">Nothing left in the books to pair with this line</span>'
                                    : '<span class="d-flex gap-2"><select class="form-select form-select-sm" aria-label="Book line" disabled><option>26 Sep 2026 · EX-2026-00214 · 26,000.00</option></select><button class="btn btn-sm btn-outline-secondary" type="button" disabled>Match</button></span>'}</td>
                            </tr>`).join('\n')}
                        </tbody>
                    </table>
                </div>
                <div class="erp-filter-note p-3">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>Only lines of the same amount can be paired, and a pair made by the rule cannot be un-picked one line at a time — re-open the period with a wider date range instead.</span>
                </div>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Matched pairs <span class="erp-chip erp-chip-outline">2</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead><tr><th>Statement date</th><th>Reference</th><th>What the bank says it was</th><th class="erp-th-num">Amount</th><th>Matched how</th><th></th></tr></thead>
                        <tbody>
${matched.map(([date, reference, what, amount, how, isIn]) => `                            <tr>
                                <td class="erp-td-muted">${date}</td>
                                <td class="font-monospace">${reference}</td>
                                <td>${what}</td>
                                <td class="erp-td-num"><span class="${isIn ? 'erp-money-in' : 'erp-money-out'}">${amount}</span></td>
                                <td><span class="erp-chip erp-chip-outline">${how}</span></td>
                                <td class="erp-td-actions"><span class="erp-td-muted">signed off — history</span></td>
                            </tr>`).join('\n')}
                        </tbody>
                    </table>
                </div>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Bank Accounts</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Bank Statement Import</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Mobile Reconciliation</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/**
 * §08-13/08-14 — the cheque register, mirrored from
 * resources/views/cash-bank/cheques.blade.php and cheque.blade.php. The promise
 * life-cycle is the whole page: what is in the drawer, what is with a bank, what
 * has been paid — and what came back.
 */
export function cheques() {
    const register = [
        { no: '004512', date: '02 Oct 2026', party: 'Rahman Traders', bank: 'Islami Bank', through: '1121 — Current account', settles: '1130 — Accounts Receivable', amount: '26,000.00', in: true, state: 'outstanding', label: 'Received — in hand', entry: '', print: false },
        { no: '004513', date: '28 Sep 2026', party: 'Meghna Enterprise', bank: 'City Bank', through: '1121 — Current account', settles: '1130 — Accounts Receivable', amount: '44,500.00', in: true, state: 'deposited', label: 'Deposited, awaiting clearing', entry: '', print: false },
        { no: '339001', date: '15 Oct 2026', party: 'Alam Store', bank: 'BRAC Bank', through: '1121 — Current account', settles: '1130 — Accounts Receivable', amount: '8,000.00', in: true, state: 'outstanding', label: 'Post-dated · Received — in hand', entry: '', print: false },
        { no: '334455', date: '30 Sep 2026', party: 'Dhaka WASA', bank: 'Dutch-Bangla Bank', through: '1121 — Current account', settles: '5230 — Utilities Expense', amount: '12,640.00', in: false, state: 'issued', label: 'Issued — not yet presented', entry: '', print: true },
        { no: '334456', date: '26 Sep 2026', party: 'Nahar Fabrics', bank: 'Dutch-Bangla Bank', through: '1121 — Current account', settles: '2110 — Accounts Payable', amount: '31,900.00', in: false, state: 'presented', label: 'Presented, awaiting clearing', entry: '', print: true },
        { no: '338001', date: '18 Sep 2026', party: 'Rahmania Store', bank: 'Islami Bank', through: '1121 — Current account', settles: '1130 — Accounts Receivable', amount: '61,500.00', in: true, state: 'cleared', label: 'Cleared', entry: 'JV-2026-004418', print: false },
        { no: '338002', date: '12 Sep 2026', party: 'Sonali Traders', bank: 'Sonali Bank', through: '1121 — Current account', settles: '1130 — Accounts Receivable', amount: '5,000.00', in: true, state: 'failed', label: 'Bounced', entry: 'JV-2026-004401 → reversed', print: false },
    ];

    const outstanding = [
        { no: '004512', date: '02 Oct 2026', party: 'Rahman Traders', amount: '26,000.00', in: true, state: 'outstanding', label: 'Received — in hand' },
        { no: '004513', date: '28 Sep 2026', party: 'Meghna Enterprise', amount: '44,500.00', in: true, state: 'deposited', label: 'Deposited, awaiting clearing' },
        { no: '339001', date: '15 Oct 2026', party: 'Alam Store', amount: '8,000.00', in: true, state: 'outstanding', label: 'Post-dated · Received — in hand' },
        { no: '334455', date: '30 Sep 2026', party: 'Dhaka WASA', amount: '12,640.00', in: false, state: 'issued', label: 'Issued — not yet presented' },
    ];

    return `
${previewBar('cheques.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('The cheque register')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-bank" aria-hidden="true"></i> Cash &amp; bank · Cheque management</p>
                    <h1 class="erp-h1">The cheque register</h1>
                    <p class="erp-page-sub">A cheque is a promise, and this is the book of promises: what is in the drawer, what is with a bank, what has been paid — and what came back. Nothing here reaches the ledger until a bank actually pays it, because money that has been promised is not money that has arrived.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is</a>
                    <a class="btn btn-outline-secondary" href="./bank-recon.html"><i class="bi bi-shield-check" aria-hidden="true"></i> Bank reconciliation</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <div>Received · 004512 · Islami Bank · 26,000.00 is in the register. Nothing reaches the ledger until the bank pays it.</div>
            </div>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-bookmark" aria-hidden="true"></i> In hand</p>
                    <p class="erp-kpi-value">৳ 26,000.00</p>
                    <p class="erp-kpi-foot">1 customer cheque in the drawer — the books have not heard about it yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-bank" aria-hidden="true"></i> With the bank</p>
                    <p class="erp-kpi-value">৳ 44,500.00</p>
                    <p class="erp-kpi-foot">1 deposited, none cleared yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-pencil-square" aria-hidden="true"></i> We wrote, not presented</p>
                    <p class="erp-kpi-value">৳ 12,640.00</p>
                    <p class="erp-kpi-foot">1 of our own cheques is still outstanding</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calendar-event" aria-hidden="true"></i> Post-dated</p>
                    <p class="erp-kpi-value">৳ 8,000.00</p>
                    <p class="erp-kpi-foot">1 dated ahead — the desk refuses to bank it early</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Cleared this month</p>
                    <p class="erp-kpi-value">৳ 121,300.00</p>
                    <p class="erp-kpi-foot">3 cheque(s) the bank actually paid</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Failed this month</p>
                    <p class="erp-kpi-value">৳ 5,000.00</p>
                    <p class="erp-kpi-foot">1 bounced or returned</p>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Still hanging over Islami Bank — current account</h2>
                        <p class="erp-card-sub">Promises written against this account that no bank has settled yet — the footnote a bank book needs before anybody signs it off.</p>
                    </div>
                    <div class="erp-card-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-journal-text" aria-hidden="true"></i> Its book</a>
                    </div>
                </header>
                <div class="p-3">
                    <div class="erp-dl erp-dl-tight erp-dl-striped">
                        <dt>Outstanding cheques</dt>
                        <dd>4</dd>
                        <dt>Promised money</dt>
                        <dd class="erp-money-flat">91,140.00</dd>
                        <dt>Of that, post-dated</dt>
                        <dd class="erp-money-flat">8,000.00</dd>
                    </div>
                    <div class="erp-table-scroll mt-2">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr><th>Cheque</th><th>Party</th><th class="erp-th-num">Amount</th><th>State</th></tr>
                            </thead>
                            <tbody>
                                ${outstanding.map((row) => `
                                <tr>
                                    <td data-label="Cheque"><span class="erp-cell-strong font-monospace">${row.no}</span><span class="d-block erp-td-muted">${row.date}</span></td>
                                    <td data-label="Party">${row.party}</td>
                                    <td data-label="Amount" class="erp-td-num"><span class="${row.in ? 'erp-money-in' : 'erp-money-out'}">${row.amount}</span></td>
                                    <td data-label="State"><span class="erp-status erp-status-${row.state}">${row.label}</span></td>
                                </tr>`).join('\n')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <form class="erp-filterbar" onsubmit="return false" role="search">
                <div class="erp-filter">
                    <label class="form-label" for="state">State</label>
                    <select class="form-select" id="state">
                        <option>Everything</option>
                        <option>Received — in hand</option>
                        <option>Issued — not yet presented</option>
                        <option>Deposited, awaiting clearing</option>
                        <option>Presented, awaiting clearing</option>
                        <option>Cleared</option>
                        <option>Bounced</option>
                        <option>Returned unpaid</option>
                        <option>Outstanding</option>
                        <option selected>Post-dated</option>
                        <option>Bounced or returned</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="direction">Direction</label>
                    <select class="form-select" id="direction">
                        <option>Received and issued</option>
                        <option>Received from a customer</option>
                        <option>Issued to a supplier or payee</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="account">Clears through</label>
                    <select class="form-select" id="account">
                        <option>Any money account</option>
                        <option selected>1121 — Islami Bank, current account</option>
                        <option>1110 — Cash in Hand</option>
                    </select>
                </div>
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="q">Find</label>
                    <input class="form-control" type="search" id="q" placeholder="Cheque number, party or bank">
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="#">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <section class="erp-card erp-card-flush">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">The register <span class="erp-chip erp-chip-outline">7 cheque(s) shown</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Cheque</th><th>Date written</th><th>Party</th><th>Clears through</th>
                                <th>Settles</th><th class="erp-th-num">Amount</th><th>State</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${register.map((row) => `
                            <tr>
                                <td data-label="Cheque">
                                    <span class="erp-cell-strong font-monospace">${row.no}</span>
                                    <span class="d-block erp-td-muted">${row.in ? 'Received from a customer' : 'Issued by us'}</span>
                                </td>
                                <td data-label="Date written" class="erp-td-muted">
                                    ${row.date}
                                    ${row.no === '339001' ? '<span class="d-block"><span class="erp-chip erp-chip-outline">Post-dated</span></span>' : ''}
                                </td>
                                <td data-label="Party">${row.party}<span class="d-block erp-td-muted">${row.bank}</span></td>
                                <td data-label="Clears through" class="erp-td-muted">${row.through}</td>
                                <td data-label="Settles" class="erp-td-muted">${row.settles}</td>
                                <td data-label="Amount" class="erp-td-num"><span class="${row.in ? 'erp-money-in' : 'erp-money-out'}">${row.amount}</span></td>
                                <td data-label="State">
                                    <span class="erp-status erp-status-${row.state}">${row.label}</span>
                                    ${row.entry ? `<span class="d-block erp-td-muted font-monospace mt-1">${row.entry}</span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="#"><i class="bi bi-eye" aria-hidden="true"></i> Open</a>
                                    ${row.print ? '<a class="btn btn-sm btn-outline-secondary" href="#"><i class="bi bi-printer" aria-hidden="true"></i> Print</a>' : ''}
                                </td>
                            </tr>`).join('\n')}
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="erp-split mt-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">One cheque, start to finish</h2>
                            <p class="erp-card-sub">338001 · Rahmania Store · cleared on 22 Sep 2026 — the four steps this desk records, and the only one of them the ledger hears about.</p>
                        </div>
                        <div class="erp-card-actions"><span class="erp-status erp-status-cleared erp-status-lg">Cleared</span></div>
                    </header>
                    <ul class="erp-steps">
                        <li class="erp-step erp-step-done">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i></span><span class="erp-step-line"></span></div>
                            <div class="erp-step-body"><p class="erp-step-title">Recorded as received</p><p class="erp-step-meta">18 Sep 2026</p></div>
                        </li>
                        <li class="erp-step erp-step-done">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i></span><span class="erp-step-line"></span></div>
                            <div class="erp-step-body"><p class="erp-step-title">Deposited with the bank</p><p class="erp-step-meta">19 Sep 2026</p></div>
                        </li>
                        <li class="erp-step erp-step-done">
                            <div class="erp-step-mark"><span class="erp-step-dot"><i class="bi bi-check-lg" aria-hidden="true"></i></span></div>
                            <div class="erp-step-body"><p class="erp-step-title">Cleared — the bank paid it</p><p class="erp-step-meta">22 Sep 2026 · posted once, as JV-2026-004418</p></div>
                        </li>
                    </ul>

                    <div class="erp-dl erp-dl-striped mt-2">
                        <dt>In English</dt>
                        <dd class="erp-cell-strong">Taka sixty-one thousand five hundred only</dd>
                        <dt>In Bangla</dt>
                        <dd class="erp-cell-strong">টাকা একষট্টি হাজার পাঁচশ মাত্র</dd>
                        <dt>Figures, Bangla</dt>
                        <dd>৬১,৫০০.০০</dd>
                    </div>

                    <div class="erp-table-scroll mt-3">
                        <table class="erp-table erp-table-compact">
                            <thead><tr><th>Account</th><th>What it was</th><th class="erp-th-num">Debit</th><th class="erp-th-num">Credit</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><code>1121</code> <span class="erp-cell-strong">Islami Bank — current account</span></td>
                                    <td class="erp-td-muted">Cheque 338001 from Rahmania Store cleared into Islami Bank — current account</td>
                                    <td class="erp-td-num">61,500.00</td>
                                    <td class="erp-td-num"></td>
                                </tr>
                                <tr>
                                    <td><code>1130</code> <span class="erp-cell-strong">Accounts Receivable</span></td>
                                    <td class="erp-td-muted">—</td>
                                    <td class="erp-td-num"></td>
                                    <td class="erp-td-num">61,500.00</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr><th colspan="2" class="text-end">Total</th><th class="erp-th-num">61,500.00</th><th class="erp-th-num">61,500.00</th></tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head"><h2 class="erp-card-title">How this desk posts</h2></header>
                        <div class="p-3">
                            <ul class="erp-list small mb-0">
                                <li class="d-flex gap-2 border-top py-2">
                                    <i class="bi bi-1-circle" aria-hidden="true"></i>
                                    <span><strong>Nothing posts when it is written.</strong> A cheque in the register is a promise, and a ledger carrying promises is a ledger nobody can reconcile.</span>
                                </li>
                                <li class="d-flex gap-2 border-top py-2">
                                    <i class="bi bi-2-circle" aria-hidden="true"></i>
                                    <span><strong>Depositing is not paying.</strong> A handed-in cheque stays outstanding until the bank clears it — the desk records the movement, not the money.</span>
                                </li>
                                <li class="d-flex gap-2 border-top py-2">
                                    <i class="bi bi-3-circle" aria-hidden="true"></i>
                                    <span><strong>Clearing posts once.</strong> The money account against what the cheque settled, dated the day the bank paid it.</span>
                                </li>
                                <li class="d-flex gap-2 border-top py-2 border-bottom">
                                    <i class="bi bi-4-circle" aria-hidden="true"></i>
                                    <span><strong>Bouncing after clearing reverses it.</strong> The original entry stays where it is and a reversal answers it, because the original really did happen.</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </aside>
            </div>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash in Hand</a>
                    <a class="erp-chip erp-chip-outline" href="./bank-recon.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Bank Reconciliation</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Bank Accounts</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/**
 * §08-15…§08-18 — the expense desk, mirrored from
 * resources/views/cash-bank/expenses.blade.php and expense.blade.php. The one
 * promise the whole page is built on: a category is a ledger account, and above
 * the limit nothing reaches the books until somebody else signs it off.
 */
export function expenses() {
    const register = [
        { no: 'EXP-2026-00031', date: '02 Oct 2026', category: 'Utilities', account: '5230 — Utilities Expense', payee: 'Dhaka WASA · March water bill', money: 'paid', moneyLabel: 'Paid from an account', source: '1110 — Cash in Hand', amount: '6,400.00', owed: false, state: 'posted', stateLabel: 'Posted', entry: 'JV-2026-004418', wait: '' },
        { no: 'EXP-2026-00032', date: '03 Oct 2026', category: 'Office rent', account: '5220 — Rent Expense', payee: 'Khan Properties · October rent', money: 'owed', moneyLabel: 'Owed to a supplier', source: '', amount: '35,000.00', owed: true, state: 'pending_approval', stateLabel: 'Waiting for approval', entry: '', wait: 'limit ৳ 20,000.00' },
        { no: 'EXP-2026-00033', date: '04 Oct 2026', category: 'Salaries & wages', account: '5210 — Salaries & Wages', payee: 'Night shift allowance', money: 'paid', moneyLabel: 'Paid from an account', source: '1120 — Islami Bank, current', amount: '12,640.00', owed: false, state: 'posted', stateLabel: 'Posted', entry: 'JV-2026-004421', wait: '' },
        { no: 'EXP-2026-00034', date: '05 Oct 2026', category: 'Utilities', account: '5230 — Utilities Expense', payee: 'Rickshaw fare, Uttara run', money: 'paid', moneyLabel: 'Paid from an account', source: '1110 — Cash in Hand', amount: '60.00', owed: false, state: 'posted', stateLabel: 'Posted', entry: 'JV-2026-004425', wait: '' },
        { no: 'EXP-2026-00029', date: '28 Sep 2026', category: 'Office rent', account: '5220 — Rent Expense', payee: 'Khan Properties · September rent', money: 'paid', moneyLabel: 'Paid from an account', source: '1120 — Islami Bank, current', amount: '35,000.00', owed: false, state: 'reversed', stateLabel: 'Reversed', entry: 'JV-2026-004401 → reversed', wait: '' },
        { no: 'EXP-2026-00030', date: '30 Sep 2026', category: 'Utilities', account: '5230 — Utilities Expense', payee: 'Unitemised agency bill', money: 'owed', moneyLabel: 'Owed to a supplier', source: '', amount: '4,200.00', owed: true, state: 'rejected', stateLabel: 'Rejected', entry: '', wait: '' },
    ];

    const byCategory = [
        { category: 'Office rent', account: '5220', rows: 2, total: '70,000.00' },
        { category: 'Salaries & wages', account: '5210', rows: 1, total: '12,640.00' },
        { category: 'Utilities', account: '5230', rows: 2, total: '6,460.00' },
    ];

    return `
${previewBar('expenses.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Expense desk')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-receipt" aria-hidden="true"></i> Cash &amp; bank · Expenses</p>
                    <h1 class="erp-h1">What the company spent</h1>
                    <p class="erp-page-sub">Every expense names what it was for and where the money went — a category that is a real ledger account, and either the account the money left or the supplier it is owed to. Above the approval limit nothing posts until somebody else signs it off.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-primary" href="#"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add expense</a>
                    <a class="btn btn-outline-secondary" href="#"><i class="bi bi-diagram-3" aria-hidden="true"></i> Categories</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">1 expense is waiting on this desk</strong>
                    It is worth ৳ 35,000.00 and none of it is in the books. The person who recorded the expense cannot approve it, so somebody else has to look at it.
                </div>
            </div>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-check" aria-hidden="true"></i> Posted in this window</p>
                    <p class="erp-kpi-value">৳ 89,100.00</p>
                    <p class="erp-kpi-foot">4 expense(s) between 2026-10-01 and 2026-10-08</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Waiting for a signature</p>
                    <p class="erp-kpi-value">৳ 35,000.00</p>
                    <p class="erp-kpi-foot">1 expense — and it has not touched the ledger yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-sliders" aria-hidden="true"></i> Approval limit</p>
                    <p class="erp-kpi-value">৳ 20,000.00</p>
                    <p class="erp-kpi-foot">At or above this an expense waits; below it, it posts as it is recorded</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Given back this window</p>
                    <p class="erp-kpi-value">৳ 35,000.00</p>
                    <p class="erp-kpi-foot">1 refused before posting; the figure above was posted and then reversed</p>
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false" role="search">
                <div class="erp-filter">
                    <label class="form-label" for="state">State</label>
                    <select class="form-select" id="state">
                        <option>Everything</option>
                        <option selected>Waiting for approval</option>
                        <option>Posted</option>
                        <option>Rejected</option>
                        <option>Reversed</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" id="category">
                        <option>Every category</option>
                        <option>Office rent</option>
                        <option>Salaries &amp; wages</option>
                        <option>Utilities</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="settled">Money</label>
                    <select class="form-select" id="settled">
                        <option>Paid and owed</option>
                        <option>Paid from an account</option>
                        <option>Owed to a supplier</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="from">From</label>
                    <input class="form-control" type="date" id="from" value="2026-10-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="to">To</label>
                    <input class="form-control" type="date" id="to" value="2026-10-08">
                </div>
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="q">Find</label>
                    <input class="form-control" type="search" id="q" placeholder="Number, payee or narration">
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="#">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <section class="erp-card erp-card-flush">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">The expense register <span class="erp-chip erp-chip-outline">6 expense(s) shown</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Expense</th><th>Category</th><th>Payee</th><th>Money</th>
                                <th class="erp-th-num">Amount</th><th>State</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${register.map((row) => `
                            <tr>
                                <td data-label="Expense">
                                    <span class="erp-cell-strong font-monospace">${row.no}</span>
                                    <span class="d-block erp-td-muted">${row.date}</span>
                                </td>
                                <td data-label="Category">
                                    ${row.category}
                                    <span class="d-block erp-td-muted">${row.account}</span>
                                </td>
                                <td data-label="Payee">${row.payee}</td>
                                <td data-label="Money">
                                    <span class="erp-status erp-status-${row.owed ? 'unpaid' : 'paid'}">${row.moneyLabel}</span>
                                    ${row.source ? `<span class="d-block erp-td-muted">${row.source}</span>` : ''}
                                </td>
                                <td data-label="Amount" class="erp-td-num"><span class="${row.owed ? 'erp-money-out' : 'erp-money-flat'}">${row.amount}</span></td>
                                <td data-label="State">
                                    <span class="erp-status erp-status-${row.state}">${row.stateLabel}</span>
                                    ${row.entry ? `<span class="d-block erp-td-muted font-monospace">${row.entry}</span>` : ''}
                                    ${row.wait ? `<span class="d-block erp-td-muted">${row.wait}</span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="#"><i class="bi bi-eye" aria-hidden="true"></i> Open</a>
                                </td>
                            </tr>`).join('\n')}
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="erp-split mt-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Where it went</h2>
                            <p class="erp-card-sub">Posted expenses in this window, by the account they were booked to.</p>
                        </div>
                    </header>
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr><th>Category</th><th>Account</th><th class="erp-th-num">Expenses</th><th class="erp-th-num">Total</th></tr>
                            </thead>
                            <tbody>
                                ${byCategory.map((row) => `
                                <tr>
                                    <td class="erp-cell-strong">${row.category}</td>
                                    <td class="erp-td-muted"><code>${row.account}</code></td>
                                    <td class="erp-td-num">${row.rows}</td>
                                    <td class="erp-td-num erp-num">${row.total}</td>
                                </tr>`).join('\n')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <aside>
                    <div class="erp-card">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title">Decide <span class="erp-chip erp-chip-outline">EXP-2026-00032</span></h2>
                        </header>
                        <div class="p-3">
                            <div class="erp-dl erp-dl-tight mb-3">
                                <dt>Debit</dt>
                                <dd>5220 — Rent Expense (the category's own account)</dd>
                                <dt>Credit</dt>
                                <dd>payables, through the company posting rules</dd>
                                <dt>Approval limit</dt>
                                <dd>৳ 20,000.00 when this was recorded</dd>
                            </div>
                            <p class="mb-2">Approving posts it now, exactly once. Refusing keeps the record and posts nothing — ever.</p>
                            <label class="form-label" for="note">Note</label>
                            <input class="form-control mb-3" type="text" id="note" placeholder="Optional when approving or refusing">
                            <div class="d-flex flex-wrap gap-2">
                                <a class="btn btn-primary" href="#"><i class="bi bi-check2-circle" aria-hidden="true"></i> Approve and post</a>
                                <a class="btn btn-outline-secondary" href="#"><i class="bi bi-x-octagon" aria-hidden="true"></i> Refuse</a>
                            </div>
                            <p class="erp-td-muted mt-3 mb-0">You recorded this expense yourself, so this panel is what somebody else sees — the desk refuses a decision from its own author.</p>
                        </div>
                    </div>

                    <div class="erp-card mt-3">
                        <header class="erp-card-head"><h2 class="erp-card-title">Owed, not paid <span class="erp-chip erp-chip-outline">2</span></h2></header>
                        <ul class="erp-list px-3 pb-3">
                            <li class="d-flex justify-content-between align-items-start gap-2 border-top py-2">
                                <span>
                                    <span class="erp-cell-strong">Khan Properties</span>
                                    <span class="d-block erp-td-muted">2026-10-03 · Office rent</span>
                                    <span class="d-block"><span class="erp-status erp-status-pending_approval">Waiting for approval</span></span>
                                </span>
                                <span class="erp-money-out">35,000.00</span>
                            </li>
                            <li class="d-flex justify-content-between align-items-start gap-2 border-top py-2">
                                <span>
                                    <span class="erp-cell-strong">Unitemised agency bill</span>
                                    <span class="d-block erp-td-muted">2026-09-30 · Utilities</span>
                                </span>
                                <span class="erp-money-out">4,200.00</span>
                            </li>
                        </ul>
                    </div>
                </aside>
            </div>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash in Hand</a>
                    <a class="erp-chip erp-chip-outline" href="./cheques.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cheque Register</a>
                    <a class="erp-chip erp-chip-outline" href="./bank-recon.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Bank Reconciliation</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* ------------------------------------------------- 14. recurring expenses */
export function recurringExpenses() {
    const schedules = [
        { payee: 'Khan Properties', what: 'Monthly rent — Uttara warehouse', category: 'Office rent', account: '5220 — Rent Expense', money: 'paid — 1120 — Islami Bank, current', amount: '35,000.00', rhythm: 'every month on the 3rd', day: 3, next: '2026-11-03', state: 'active', late: 'last ran 2026-10-03', count: '7 generated' },
        { payee: 'Aarong Dairy', what: 'Office milk and tea', category: 'Office supplies', account: '5240 — Office Supplies', money: 'paid — 1110 — Cash in Hand', amount: '2,500.00', rhythm: 'every month on the 5th', day: 5, next: '2026-10-05', state: 'active', late: 'due — 3 day(s) late', count: '12 generated' },
        { payee: 'Link3 Technologies', what: 'Internet line, 40 Mbps', category: 'Utilities', account: '5230 — Utilities Expense', money: 'paid — 1130 — Dutch-Bangla Bank', amount: '4,200.00', rhythm: 'every quarter on the 10th', day: 10, next: '2026-12-10', state: 'active', late: 'last ran 2026-09-10', count: '4 generated' },
        { payee: 'DESCO', what: 'Warehouse electricity', category: 'Utilities', account: '5230 — Utilities Expense', money: 'owed to a supplier', amount: '18,400.00', rhythm: 'every month on the 28th', day: 28, next: '2026-10-28', state: 'active', late: 'never generated yet', count: '0 generated' },
        { payee: 'Security guard, night shift', what: 'Security wages', category: 'Salaries & wages', account: '5210 — Salaries & Wages', money: 'paid — 1110 — Cash in Hand', amount: '9,000.00', rhythm: 'every week', day: '', next: '2026-10-15', state: 'paused', late: 'paused by the owner — nothing generated for it', count: '3 generated' },
    ];

    return `
${previewBar('expense-recurring.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Recurring expenses')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Cash &amp; bank · Expenses</p>
                    <h1 class="erp-h1">The expenses that come round again</h1>
                    <p class="erp-page-sub">Rent, salaries and the internet line do not need discovering — they are known in advance, and a desk that retypes them every month eventually forgets one. A schedule here records nothing by itself: on its own day it produces an expense through the ordinary desk, approval limit and all.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./expenses.html"><i class="bi bi-receipt" aria-hidden="true"></i> The register</a>
                    <a class="btn btn-outline-secondary" href="./expenses.html"><i class="bi bi-diagram-3" aria-hidden="true"></i> Categories</a>
                    <button class="erp-icon-btn" type="button" title="Pin this page" aria-label="Pin this page"><i class="bi bi-star" aria-hidden="true"></i></button>
                </div>
            </header>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">1 schedule has come due</strong>
                    The oldest is Aarong Dairy — every month on the 5th, due 2026-10-05, 3 day(s) late. The scheduled run does this every morning; a schedule refused there keeps its date, so nothing is skipped silently.
                </div>
            </div>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Active schedules</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">1 paused</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Due today or earlier</p>
                    <p class="erp-kpi-value">1</p>
                    <p class="erp-kpi-foot">Nothing posts until the day arrives — and then the approval limit still applies</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-check" aria-hidden="true"></i> Generated this month</p>
                    <p class="erp-kpi-value">৳ 62,600.00</p>
                    <p class="erp-kpi-foot">3 expense(s) produced by a schedule</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calendar-event" aria-hidden="true"></i> Next to run</p>
                    <p class="erp-kpi-value">2026-10-15</p>
                    <p class="erp-kpi-foot">Security guard, night shift</p>
                </div>
            </div>

            <section class="erp-card erp-card-flush">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Schedules <span class="erp-chip erp-chip-outline">5 schedule(s)</span></h2>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>What it is</th><th>Account it books to</th><th class="erp-th-num">Amount</th>
                                <th>Rhythm</th><th>Next due</th><th>State</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${schedules.map((s) => `
                            <tr>
                                <td data-label="What it is">
                                    <span class="erp-cell-strong">${s.payee}</span>
                                    <span class="d-block erp-td-muted">${s.category} · ${s.money}</span>
                                </td>
                                <td data-label="Account" class="erp-td-muted">${s.account}</td>
                                <td data-label="Amount" class="erp-td-num">
                                    <input class="form-control form-control-sm erp-num text-end" type="number" step="0.01" value="${s.amount.replaceAll(',', '')}" aria-label="Amount for ${s.payee}">
                                </td>
                                <td data-label="Rhythm">
                                    <select class="form-select form-select-sm" aria-label="Frequency for ${s.payee}">
                                        <option${s.rhythm.includes('month') ? ' selected' : ''}>Every month</option>
                                        <option${s.rhythm.includes('week') ? ' selected' : ''}>Every week</option>
                                        <option${s.rhythm.includes('quarter') ? ' selected' : ''}>Every quarter</option>
                                        <option${s.rhythm.includes('year') ? ' selected' : ''}>Every year</option>
                                    </select>
                                    <input class="form-control form-control-sm erp-num mt-1" type="number" min="1" max="31" value="${s.day}" placeholder="day" aria-label="Day of the month for ${s.payee}">
                                    <span class="d-block erp-td-muted mt-1">${s.rhythm}</span>
                                </td>
                                <td data-label="Next due">
                                    <span class="erp-cell-strong">${s.next}</span>
                                    <span class="d-block erp-td-muted">${s.late}</span>
                                    <input class="form-control form-control-sm mt-1" type="date" placeholder="no end date" aria-label="Last date for ${s.payee}">
                                </td>
                                <td data-label="State">
                                    ${statusChip(s.state, s.state === 'paused' ? 'Paused' : 'Active')}
                                    <span class="d-block erp-td-muted">${s.count}</span>
                                </td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-check2" aria-hidden="true"></i> Save</button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button">
                                        ${s.state === 'paused' ? '<i class="bi bi-play" aria-hidden="true"></i> Resume' : '<i class="bi bi-pause" aria-hidden="true"></i> Pause'}
                                    </button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <span class="erp-td-muted">The scheduled run does this every morning at 06:20. This button is for when today's rent cannot wait for tomorrow.</span>
                                        <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Generate what is due</button>
                                    </div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div class="erp-filter-note mt-2">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>A schedule never posts anything itself. On its day it produces an expense through the ordinary desk — so the approval limit applies, the category decides the account, and the person who created the schedule is the one who cannot approve what it generated.</span>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Schedule an expense</h2>
                        <p class="erp-card-sub">The category decides which account it books to; the account below is where the money leaves from. Nothing is generated before the first date.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="r-category">Category</label>
                            <select class="form-select" id="r-category">
                                <option>Office rent — books to 5220 — Rent Expense</option>
                                <option>Utilities — books to 5230 — Utilities Expense</option>
                                <option>Salaries &amp; wages — books to 5210 — Salaries &amp; Wages</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-payee">Paid to</label>
                            <input class="form-control" type="text" id="r-payee" placeholder="Khan Properties">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-amount">Amount each time</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control erp-num" type="number" step="0.01" id="r-amount" placeholder="35,000.00">
                            </div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-frequency">How often</label>
                            <select class="form-select" id="r-frequency">
                                <option>Every month</option><option>Every week</option>
                                <option>Every quarter</option><option>Every year</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-day">Day of the month</label>
                            <input class="form-control erp-num" type="number" min="1" max="31" id="r-day" placeholder="blank — the day it starts">
                            <div class="form-text">A month that is too short clamps to its last day: the 31st means the 28th in February and the 31st again in March.</div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-start">First on</label>
                            <input class="form-control" type="date" id="r-start" value="2026-10-08">
                            <div class="form-text">The first run is this date itself, not one period later.</div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-end">Last on (optional)</label>
                            <input class="form-control" type="date" id="r-end">
                            <div class="form-text">A lease with an end date stops itself rather than generating into a period nobody agreed to.</div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-settled">Paid, or owed?</label>
                            <select class="form-select" id="r-settled">
                                <option>Paid from an account</option>
                                <option>Owed to a supplier</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-money">Paid from</label>
                            <select class="form-select" id="r-money">
                                <option>1110 — Cash in Hand</option>
                                <option>1120 — Islami Bank, current</option>
                                <option>1130 — Dutch-Bangla Bank</option>
                                <option>1140 — bKash merchant wallet</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="r-supplier">Owed to (optional)</label>
                            <select class="form-select" id="r-supplier">
                                <option>— no supplier on the books —</option>
                                <option>Aarong Dairy</option>
                            </select>
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="r-narration">What it is</label>
                            <input class="form-control" type="text" id="r-narration" placeholder="Monthly rent — Uttara warehouse">
                        </div>
                        <div class="erp-form-field">
                            <span class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="r-active" checked>
                                <label class="form-check-label" for="r-active">Start it immediately</label>
                            </span>
                            <div class="form-text">Switched off, the schedule is kept and fires nothing — the same state as pausing it later.</div>
                        </div>
                    </div>
                    <div class="erp-card-tight d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 pb-3">
                        <span class="erp-td-muted">Every generated expense is an ordinary expense: it can be approved, refused or reversed like any other, and it carries the schedule that produced it.</span>
                        <button class="btn btn-primary" type="button"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Schedule it</button>
                    </div>
                </form>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Expenses</a>
                    <a class="erp-chip erp-chip-outline" href="./cheques.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cheque Register</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash in Hand</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* -------------------------------------------------------------- 16. petty cash */
export function pettyCash() {
    const funds = [
        { code: 'PC-HEAD', name: 'Head office tin', custodian: 'Rakib Hasan', where: '1115-PC-HEAD — Head office tin (petty cash)', branch: 'Head office', holds: '6,250.00', level: '10,000.00', back: '3,750.00', state: 'active', note: '1 request waiting' },
        { code: 'PC-DEPOT', name: 'Depot tin', custodian: 'Sumaiya Akter', where: '1115-PC-DEPOT — Depot tin (petty cash)', branch: 'Uttara depot', holds: '4,200.00', level: '8,000.00', back: '3,800.00', state: 'active', note: '' },
        { code: 'PC-CNTR', name: 'Counter till', custodian: 'Jamal Uddin', where: '1115-PC-CNTR — Counter till (petty cash)', branch: 'Dhanmondi outlet', holds: '8,000.00', level: '7,000.00', back: '0.00', state: 'active', note: 'over its level' },
        { code: 'PC-OLD', name: 'Old warehouse tin', custodian: 'Nazmul Islam', where: '1115-PC-OLD — Old warehouse tin (petty cash)', branch: 'Tejgaon warehouse', holds: '0.00', level: '5,000.00', back: '5,000.00', state: 'closed', note: 'closed 2026-09-30' },
    ];

    const vouchers = [
        { date: '2026-10-08', no: 'EX-2026-00418', fund: 'Head office tin', payee: 'Rickshaw', what: 'Two rides to the courier office', spent: 'Local travel — 5220 — Travelling Expense', amount: '250.00', by: 'Rakib Hasan', note: 'below the limit of 1,000.00' },
        { date: '2026-10-07', no: 'EX-2026-00417', fund: 'Counter till', payee: 'Shahin Store', what: 'Packing tape and markers', spent: 'Packaging materials — 5255 — Packing Materials', amount: '840.00', by: 'Jamal Uddin', note: 'below the limit of 1,000.00' },
        { date: '2026-10-06', no: 'EX-2026-00412', fund: 'Depot tin', payee: 'Paper shop', what: 'Reams of paper for the counter', spent: 'Office supplies — 5240 — Office Supplies', amount: '2,000.00', by: 'Sumaiya Akter', note: 'against request #31, approved by Md. Faruk' },
    ];

    return `
${previewBar('petty-cash.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Petty cash')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-cash-coin" aria-hidden="true"></i> Cash &amp; bank · Petty cash</p>
                    <h1 class="erp-h1">The float in the drawer</h1>
                    <p class="erp-page-sub">A float is real money in a real place, held by a named person. It is its own account in the chart of accounts, so the balance below is the ledger's own figure rather than a number this desk keeps. Above the company's limit a voucher is asked for before it is paid, and whoever asked cannot be the person who approves it.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./petty-cash-expenses.html"><i class="bi bi-receipt" aria-hidden="true"></i> Vouchers</a>
                    <a class="btn btn-outline-secondary" href="./petty-cash-requests.html"><i class="bi bi-question-circle" aria-hidden="true"></i> Requests <span class="erp-chip erp-chip-warn ms-1">1 waiting</span></a>
                    <a class="btn btn-primary" href="./petty-cash-replenishment.html"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Replenish</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-stack" aria-hidden="true"></i> In the tins</p>
                    <p class="erp-kpi-value">৳ 18,450.00</p>
                    <p class="erp-kpi-foot">3 open float(s) of 4 declared</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-bullseye" aria-hidden="true"></i> Meant to be there</p>
                    <p class="erp-kpi-value">৳ 25,000.00</p>
                    <p class="erp-kpi-foot">The level the open floats are replenished back to</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Waiting to be put back</p>
                    <p class="erp-kpi-value">৳ 6,550.00</p>
                    <p class="erp-kpi-foot">What it would take to restore every float to its level</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Asked for, not yet paid</p>
                    <p class="erp-kpi-value">৳ 2,000.00</p>
                    <p class="erp-kpi-foot">1 request — nothing here has reached the ledger</p>
                </div>
            </div>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>At or above <strong>৳ 1,000.00</strong> a voucher is asked for rather than paid, and the answer has to come from somebody other than the person who asked. Below it the custodian pays and records it in one step.</div>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The floats<span class="erp-chip erp-chip-outline">4 float(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Float</th>
                                <th>Custodian</th>
                                <th>Where it is kept</th>
                                <th class="erp-th-num">Holds</th>
                                <th class="erp-th-num">Level</th>
                                <th class="erp-th-num">To put back</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${funds.map((f) => `
                            <tr>
                                <td>
                                    <span class="erp-cell-strong">${f.code}</span>
                                    <span class="d-block erp-td-muted">${f.name}</span>
                                </td>
                                <td>
                                    ${f.custodian}
                                    <span class="d-block erp-td-muted">answerable for the cash</span>
                                </td>
                                <td>
                                    <a href="./cash-bank.html">${f.where}</a>
                                    <span class="d-block erp-td-muted">${f.branch}</span>
                                </td>
                                <td class="erp-td-num">৳ ${f.holds}</td>
                                <td class="erp-td-num">৳ ${f.level}</td>
                                <td class="erp-td-num">
                                    ৳ ${f.back}
                                    ${f.state === 'active' && f.back !== '0.00' ? '<span class="d-block erp-td-muted"><a href="./petty-cash-replenishment.html">Put it back</a></span>' : ''}
                                </td>
                                <td>
                                    ${statusChip(f.state, f.state === 'active' ? 'Open' : 'Closed')}
                                    ${f.note ? `<span class="d-block erp-td-muted">${f.note}</span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button">Vouchers</button>
                                    <button class="btn btn-sm btn-outline-secondary" type="button">Re-describe</button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="erp-table-shell mt-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Paid out lately<span class="erp-chip erp-chip-outline">3 voucher(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Voucher</th>
                                <th>Float</th>
                                <th>Payee</th>
                                <th>Spent on</th>
                                <th class="erp-th-num">Amount</th>
                                <th>Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${vouchers.map((v) => `
                            <tr>
                                <td>${v.date}</td>
                                <td>
                                    <span class="erp-cell-strong">${v.no}</span>
                                    <span class="d-block erp-td-muted">posted to the ledger</span>
                                </td>
                                <td>${v.fund}</td>
                                <td>
                                    ${v.payee}
                                    <span class="d-block erp-td-muted">${v.what}</span>
                                </td>
                                <td>
                                    ${v.spent.split(' — ')[0]}
                                    <span class="d-block erp-td-muted">${v.spent.split(' — ').slice(1).join(' — ')}</span>
                                </td>
                                <td class="erp-td-num">৳ ${v.amount}</td>
                                <td>
                                    ${v.by}
                                    <span class="d-block erp-td-muted">${v.note}</span>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <footer class="erp-table-foot">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="erp-td-muted">Every voucher is a payment out of the float's own account: Dr the category, Cr the tin. The float cannot pay more than it holds.</span>
                        <a class="btn btn-outline-secondary btn-sm" href="./petty-cash-expenses.html">The voucher register</a>
                    </div>
                </footer>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Declare a float</h2>
                        <p class="erp-card-sub">This creates the float's own account in the chart of accounts under Current Assets and names the person answerable for what is in it. Nothing is posted — declaring a tin is not spending money.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-code">Code</label>
                            <input class="form-control" type="text" id="pc-code" placeholder="PC-HEAD">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-name">Name</label>
                            <input class="form-control" type="text" id="pc-name" placeholder="Head office tin">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-custodian">Custodian</label>
                            <select class="form-select" id="pc-custodian">
                                <option>Rakib Hasan</option>
                                <option>Sumaiya Akter</option>
                                <option>Jamal Uddin</option>
                            </select>
                            <small class="form-text">The person answerable for the cash in the tin.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-branch">Kept at</label>
                            <select class="form-select" id="pc-branch">
                                <option>Head office</option>
                                <option>Uttara depot</option>
                                <option>Dhanmondi outlet</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-level">Level it is meant to hold</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control erp-num" type="number" step="0.01" id="pc-level" placeholder="10,000.00">
                            </div>
                            <small class="form-text">The amount the replenishment screen will offer to put back.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pc-account">Ledger account code</label>
                            <input class="form-control" type="text" id="pc-account" placeholder="Filled in for you">
                            <small class="form-text">Leave blank to keep the code derived from the float's own code.</small>
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="pc-agenda">What it is for</label>
                            <input class="form-control" type="text" id="pc-agenda" placeholder="Couriers, tea, rickshaws and the small hardware nobody raises a purchase order for">
                        </div>
                    </div>
                    <label class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" checked>
                        <span class="form-check-label">Open it now</span>
                    </label>
                    <div class="mt-2">
                        <button class="btn btn-primary" type="button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Declare the float</button>
                    </div>
                </form>
            </section>

            <div class="erp-filter-note mt-2">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>A voucher leaves through the same payment service every other payment uses, and a top-up is a transfer into the float — never a second record of the spending it already recorded. That is why the tin's balance on this screen and the balance in the books cannot drift apart.</span>
            </div>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Expenses</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-requests.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Requests</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-replenishment.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Replenishment</a>
                    <a class="erp-chip erp-chip-outline" href="./expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Expenses</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* --------------------------------------------------- 17. petty cash requests */
export function pettyCashRequests() {
    const requests = [
        { needed: '2026-10-08', fund: 'Head office tin', code: 'PC-HEAD', payee: 'Paper shop', what: 'Reams of paper for the counter', category: 'Office supplies', account: '5240 — Office Supplies', amount: '2,000.00', by: 'Rakib Hasan', asked: '2026-10-08', state: 'pending_approval', label: 'Waiting for approval', note: '' },
        { needed: '2026-10-05', fund: 'Depot tin', code: 'PC-DEPOT', payee: 'Shahin Store', what: 'Packing tape, markers', category: 'Packaging materials', account: '5255 — Packing Materials', amount: '1,450.00', by: 'Sumaiya Akter', asked: '2026-10-05', state: 'approved', label: 'Approved and paid', note: 'paid out — EX-2026-00415' },
        { needed: '2026-10-03', fund: 'Head office tin', code: 'PC-HEAD', payee: 'Furniture mart', what: 'A chair for the counter', category: 'Office supplies', account: '5240 — Office Supplies', amount: '9,800.00', by: 'Rakib Hasan', asked: '2026-10-03', state: 'rejected', label: 'Rejected', note: 'Raise a purchase order for that' },
    ];

    return `
${previewBar('petty-cash-requests.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Petty cash requests')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-question-circle" aria-hidden="true"></i> Cash &amp; bank · Petty cash · Requests</p>
                    <h1 class="erp-h1">Money asked for before it is spent</h1>
                    <p class="erp-page-sub">Above the company's limit nothing comes out of a float until somebody asks and somebody else agrees. A request is not a voucher: while it waits there is no payment, no number and nothing in the ledger — so a waiting request can never be mistaken for money that has moved.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./petty-cash.html"><i class="bi bi-cash-coin" aria-hidden="true"></i> The floats</a>
                    <a class="btn btn-primary" href="./petty-cash-expenses.html"><i class="bi bi-receipt" aria-hidden="true"></i> Vouchers</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Waiting for an answer</p>
                    <p class="erp-kpi-value">৳ 2,000.00</p>
                    <p class="erp-kpi-foot">1 request — none of them has reached the ledger yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-receipt" aria-hidden="true"></i> Paid out of the floats this month</p>
                    <p class="erp-kpi-value">৳ 14,240.00</p>
                    <p class="erp-kpi-foot">9 voucher(s), each through the payment book</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-sliders" aria-hidden="true"></i> Approval limit</p>
                    <p class="erp-kpi-value">৳ 1,000.00</p>
                    <p class="erp-kpi-foot">At or above this a voucher is asked for; below it the custodian pays it</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-stack" aria-hidden="true"></i> In the tins now</p>
                    <p class="erp-kpi-value">৳ 18,450.00</p>
                    <p class="erp-kpi-foot">3 open float(s); ৳ 6,550.00 short of their level</p>
                </div>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">What people have asked for<span class="erp-chip erp-chip-outline">3 request(s) shown</span></h2>
                    <div class="erp-card-actions">
                        <select class="form-select form-select-sm" aria-label="Filter by state">
                            <option>Everything</option>
                            <option selected>Waiting for approval</option>
                            <option>Approved and paid</option>
                            <option>Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Needed on</th>
                                <th>Float</th>
                                <th>Payee</th>
                                <th>Category</th>
                                <th class="erp-th-num">Amount</th>
                                <th>Asked by</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${requests.map((r) => `
                            <tr>
                                <td>${r.needed}</td>
                                <td>
                                    <span class="erp-cell-strong">${r.fund}</span>
                                    <span class="d-block erp-td-muted">${r.code}</span>
                                </td>
                                <td>
                                    ${r.payee}
                                    <span class="d-block erp-td-muted">${r.what}</span>
                                </td>
                                <td>
                                    ${r.category}
                                    <span class="d-block erp-td-muted">${r.account}</span>
                                </td>
                                <td class="erp-td-num">৳ ${r.amount}</td>
                                <td>
                                    ${r.by}
                                    <span class="d-block erp-td-muted">${r.asked}</span>
                                </td>
                                <td>
                                    ${statusChip(r.state, r.label)}
                                    ${r.note ? `<span class="d-block erp-td-muted">${r.note}</span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    ${r.state === 'pending_approval' ? `
                                    <div class="d-flex flex-column gap-1">
                                        <input class="form-control form-control-sm" type="text" placeholder="Why (kept with the decision)">
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-primary" type="button">Pay it</button>
                                            <button class="btn btn-sm btn-outline-secondary" type="button">Refuse</button>
                                        </div>
                                    </div>` : r.state === 'approved' ? `
                                    <span class="erp-cell-strong">EX-2026-00415</span>
                                    <span class="d-block erp-td-muted">paid out</span>` : `
                                    <span class="erp-td-muted">Raise a purchase order for that</span>`}
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <footer class="erp-table-foot">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span class="erp-td-muted">The person who asked for the money cannot be the person who answers — that rule lives in the service, not in a hidden button.</span>
                        <span class="erp-chip erp-chip-outline">Maker ≠ checker</span>
                    </div>
                </footer>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Ask for money out of a float</h2>
                        <p class="erp-card-sub">At or above ৳ 1,000.00 this waits for somebody else's answer. Below it, the same form pays the money and records the voucher in one step.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="pr-fund">Float</label>
                            <select class="form-select" id="pr-fund">
                                <option>PC-HEAD — Head office tin</option>
                                <option>PC-DEPOT — Depot tin</option>
                                <option>PC-CNTR — Counter till</option>
                            </select>
                            <small class="form-text">The money leaves the float's own account, so the tin has to be holding it.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pr-category">What it is for</label>
                            <select class="form-select" id="pr-category">
                                <option>Office supplies (5240)</option>
                                <option>Local travel (5220)</option>
                                <option>Packaging materials (5255)</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pr-payee">Payee</label>
                            <input class="form-control" type="text" id="pr-payee" placeholder="Who is to be paid">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pr-amount">Amount</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control erp-num" type="number" step="0.01" id="pr-amount" placeholder="2,000.00">
                            </div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pr-needed">Needed on</label>
                            <input class="form-control" type="date" id="pr-needed" value="2026-10-08">
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="pr-what">What it is</label>
                            <input class="form-control" type="text" id="pr-what" placeholder="Courier to Uttara — three parcels">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-2" type="button"><i class="bi bi-send" aria-hidden="true"></i> Send the request</button>
                </form>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./petty-cash.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Overview</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Expenses</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-replenishment.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Replenishment</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* --------------------------------------------------- 18. petty cash expenses */
export function pettyCashExpenses() {
    const vouchers = [
        { date: '2026-10-08', no: 'EX-2026-00418', fund: 'Head office tin', payee: 'Rickshaw', what: 'Two rides to the courier office', spent: 'Local travel', account: '5220 — Travelling Expense', amount: '250.00', by: 'Rakib Hasan', note: 'below the limit of 1,000.00' },
        { date: '2026-10-07', no: 'EX-2026-00417', fund: 'Counter till', payee: 'Shahin Store', what: 'Packing tape and markers', spent: 'Packaging materials', account: '5255 — Packing Materials', amount: '840.00', by: 'Jamal Uddin', note: 'below the limit of 1,000.00' },
        { date: '2026-10-06', no: 'EX-2026-00412', fund: 'Depot tin', payee: 'Paper shop', what: 'Reams of paper for the counter', spent: 'Office supplies', account: '5240 — Office Supplies', amount: '2,000.00', by: 'Sumaiya Akter', note: 'against request #31, approved by Md. Faruk' },
        { date: '2026-10-04', no: 'EX-2026-00406', fund: 'Head office tin', payee: 'Nur Electric', what: 'Replacement lock for the shutter', spent: 'Repairs & maintenance', account: '5260 — Repairs & Maintenance', amount: '1,150.00', by: 'Rakib Hasan', note: 'against request #29, approved by Md. Faruk' },
    ];

    return `
${previewBar('petty-cash-expenses.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Petty cash expenses')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-receipt" aria-hidden="true"></i> Cash &amp; bank · Petty cash · Expenses</p>
                    <h1 class="erp-h1">What came out of the float</h1>
                    <p class="erp-page-sub">Every voucher here is a real payment: the money left the float's own account and the ledger knows about it — debited to the category it was spent on, credited to the tin. The float cannot pay more than it holds, which is why the register and the ledger always agree.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./petty-cash.html"><i class="bi bi-cash-coin" aria-hidden="true"></i> The floats</a>
                    <a class="btn btn-primary" href="./petty-cash-replenishment.html"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Replenish</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-receipt" aria-hidden="true"></i> Paid out this month</p>
                    <p class="erp-kpi-value">৳ 14,240.00</p>
                    <p class="erp-kpi-foot">9 voucher(s) out of the floats</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-stack" aria-hidden="true"></i> In the tins now</p>
                    <p class="erp-kpi-value">৳ 18,450.00</p>
                    <p class="erp-kpi-foot">Across 3 open float(s) of 4</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Short of their level</p>
                    <p class="erp-kpi-value">৳ 6,550.00</p>
                    <p class="erp-kpi-foot">What a replenishment would put back</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Put back this month</p>
                    <p class="erp-kpi-value">৳ 12,000.00</p>
                    <p class="erp-kpi-foot">Transfers into the floats, never a second record of the spending</p>
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false" role="search">
                <div class="erp-filter">
                    <label class="form-label" for="pe-fund">Float</label>
                    <select class="form-select" id="pe-fund">
                        <option>Every float</option>
                        <option>Head office tin</option>
                        <option>Depot tin</option>
                        <option>Counter till</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="pe-category">Category</label>
                    <select class="form-select" id="pe-category">
                        <option>Every category</option>
                        <option>Local travel</option>
                        <option>Office supplies</option>
                        <option>Packaging materials</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="pe-from">From</label>
                    <input class="form-control" type="date" id="pe-from" value="2026-10-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="pe-to">To</label>
                    <input class="form-control" type="date" id="pe-to" value="2026-10-31">
                </div>
                <div class="erp-filterbar-actions">
                    <button class="btn btn-link" type="button">Reset</button>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Vouchers paid out of the floats<span class="erp-chip erp-chip-outline">4 voucher(s) shown</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Voucher</th>
                                <th>Float</th>
                                <th>Payee</th>
                                <th>Spent on</th>
                                <th class="erp-th-num">Amount</th>
                                <th>Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${vouchers.map((v) => `
                            <tr>
                                <td>${v.date}</td>
                                <td>
                                    <span class="erp-cell-strong">${v.no}</span>
                                    <span class="d-block erp-td-muted">posted to the ledger</span>
                                </td>
                                <td>
                                    ${v.fund}
                                    <span class="d-block erp-td-muted"><a href="./cash-bank.html">the tin's ledger</a></span>
                                </td>
                                <td>
                                    ${v.payee}
                                    <span class="d-block erp-td-muted">${v.what}</span>
                                </td>
                                <td>
                                    ${v.spent}
                                    <span class="d-block erp-td-muted">${v.account}</span>
                                </td>
                                <td class="erp-td-num">৳ ${v.amount}</td>
                                <td>
                                    ${v.by}
                                    <span class="d-block erp-td-muted">${v.note}</span>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Pay a voucher out of a float</h2>
                        <p class="erp-card-sub">The float has to be holding the money — the desk checks the ledger before it lets the voucher through, because a custodian cannot hand over cash the tin does not have.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="pv-fund">Float</label>
                            <select class="form-select" id="pv-fund">
                                <option>PC-HEAD — Head office tin</option>
                                <option>PC-DEPOT — Depot tin</option>
                                <option>PC-CNTR — Counter till</option>
                            </select>
                            <small class="form-text">Holds 6,250.00 of a 10,000.00 level.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pv-category">Spent on</label>
                            <select class="form-select" id="pv-category">
                                <option>Local travel (5220)</option>
                                <option>Office supplies (5240)</option>
                                <option>Packaging materials (5255)</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pv-payee">Payee</label>
                            <input class="form-control" type="text" id="pv-payee" placeholder="Who was handed the cash">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pv-amount">Amount</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control erp-num" type="number" step="0.01" id="pv-amount" placeholder="250.00">
                            </div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pv-date">Date paid</label>
                            <input class="form-control" type="date" id="pv-date" value="2026-10-08">
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="pv-what">What it was for</label>
                            <input class="form-control" type="text" id="pv-what" placeholder="Two rickshaws to the courier office">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-2" type="button"><i class="bi bi-cash-coin" aria-hidden="true"></i> Pay and record the voucher</button>
                </form>
            </section>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./petty-cash.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Overview</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-requests.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Requests</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-replenishment.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Replenishment</a>
                    <a class="erp-chip erp-chip-outline" href="./expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>All Expenses</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

/* ---------------------------------------------- 19. petty cash replenishment */
export function pettyCashReplenishment() {
    const floats = [
        { code: 'PC-HEAD', name: 'Head office tin', custodian: 'Rakib Hasan', holds: '6,250.00', level: '10,000.00', back: '3,750.00', state: 'active', label: 'Open' },
        { code: 'PC-DEPOT', name: 'Depot tin', custodian: 'Sumaiya Akter', holds: '4,200.00', level: '8,000.00', back: '3,800.00', state: 'active', label: 'Open' },
        { code: 'PC-CNTR', name: 'Counter till', custodian: 'Jamal Uddin', holds: '8,000.00', level: '7,000.00', back: '0.00', state: 'active', label: 'Open' },
        { code: 'PC-OLD', name: 'Old warehouse tin', custodian: 'Nazmul Islam', holds: '0.00', level: '5,000.00', back: '5,000.00', state: 'closed', label: 'Closed' },
    ];

    const topUps = [
        { date: '2026-10-06', no: 'CT-2026-00041', fund: 'Head office tin', from: '1120 — Islami Bank, current', amount: '3,750.00', note: 'October top-up of the head office tin', by: 'Md. Faruk' },
        { date: '2026-10-02', no: 'CT-2026-00039', fund: 'Depot tin', from: '1110 — Cash in Hand', amount: '6,000.00', note: '', by: 'Md. Faruk' },
        { date: '2026-09-30', no: 'CT-2026-00036', fund: 'Counter till', from: '1110 — Cash in Hand', amount: '2,250.00', note: 'Topped up for the weekend', by: 'Md. Faruk' },
    ];

    return `
${previewBar('petty-cash-replenishment.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Petty cash replenishment')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Cash &amp; bank · Petty cash · Replenishment</p>
                    <h1 class="erp-h1">Putting the float back</h1>
                    <p class="erp-page-sub">A replenishment is a transfer, not an expense: the spending was recorded voucher by voucher, when each was paid, so recording it again here would count every rickshaw twice. This screen puts the float back to the level it is meant to hold — and nothing else.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./petty-cash.html"><i class="bi bi-cash-coin" aria-hidden="true"></i> The floats</a>
                    <a class="btn btn-primary" href="./petty-cash-expenses.html"><i class="bi bi-receipt" aria-hidden="true"></i> Vouchers</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Put back this month</p>
                    <p class="erp-kpi-value">৳ 12,000.00</p>
                    <p class="erp-kpi-foot">Transfers into the floats — each one a real move of money</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-stack" aria-hidden="true"></i> In the tins now</p>
                    <p class="erp-kpi-value">৳ 18,450.00</p>
                    <p class="erp-kpi-foot">Across 3 open float(s)</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-bullseye" aria-hidden="true"></i> Meant to be there</p>
                    <p class="erp-kpi-value">৳ 25,000.00</p>
                    <p class="erp-kpi-foot">The levels the floats are replenished back to</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Short of their level</p>
                    <p class="erp-kpi-value">৳ 6,550.00</p>
                    <p class="erp-kpi-foot">Put the whole of this back and every tin is level again</p>
                </div>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Where each float stands<span class="erp-chip erp-chip-outline">4 float(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Float</th>
                                <th>Custodian</th>
                                <th class="erp-th-num">Holds</th>
                                <th class="erp-th-num">Level</th>
                                <th class="erp-th-num">To put back</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${floats.map((f) => `
                            <tr>
                                <td>
                                    <span class="erp-cell-strong">${f.code}</span>
                                    <span class="d-block erp-td-muted">${f.name}</span>
                                </td>
                                <td>${f.custodian}</td>
                                <td class="erp-td-num">৳ ${f.holds}</td>
                                <td class="erp-td-num">৳ ${f.level}</td>
                                <td class="erp-td-num"><span class="erp-cell-strong">৳ ${f.back}</span></td>
                                <td>${statusChip(f.state, f.label)}</td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button">Look at it</button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Put money back into a float</h2>
                        <p class="erp-card-sub">The money comes out of one of the company's own accounts and goes into the float's — a transfer between two accounts the company holds, which is why it never touches the expense reports.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="pt-fund">Float</label>
                            <select class="form-select" id="pt-fund">
                                <option>Head office tin — holds 6,250.00 of 10,000.00</option>
                                <option>Depot tin — holds 4,200.00 of 8,000.00</option>
                                <option>Counter till — holds 8,000.00 of 7,000.00</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pt-source">Money comes from</label>
                            <select class="form-select" id="pt-source">
                                <option>1120 — Islami Bank, current</option>
                                <option>1110 — Cash in Hand</option>
                                <option>1115-PC-HEAD — Head office tin (petty cash) (this is a float's own account)</option>
                            </select>
                            <small class="form-text">A tin cannot top itself up: the money has to come from outside the float.</small>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pt-amount">Amount</label>
                            <div class="erp-input-group">
                                <span class="input-group-text">৳</span>
                                <input class="form-control erp-num" type="number" step="0.01" id="pt-amount" placeholder="3,750.00">
                            </div>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="pt-date">Date</label>
                            <input class="form-control" type="date" id="pt-date" value="2026-10-08">
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="pt-note">Note</label>
                            <input class="form-control" type="text" id="pt-note" placeholder="October top-up of the head office tin">
                        </div>
                    </div>
                    <button class="btn btn-primary mt-2" type="button"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Put it back</button>
                </form>
            </section>

            <div class="erp-table-shell mt-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Replenishments — Head office tin<span class="erp-chip erp-chip-outline">3 top-up(s) shown</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transfer</th>
                                <th>Float</th>
                                <th>From</th>
                                <th class="erp-th-num">Amount</th>
                                <th>Note</th>
                                <th>Recorded by</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${topUps.map((t) => `
                            <tr>
                                <td>${t.date}</td>
                                <td><span class="erp-cell-strong">${t.no}</span></td>
                                <td>${t.fund}</td>
                                <td>${t.from}</td>
                                <td class="erp-td-num">৳ ${t.amount}</td>
                                <td>${t.note || '—'}</td>
                                <td>${t.by}</td>
                            </tr>`).join('')}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <span class="erp-td-muted">A top-up carries a transfer number of its own, so the move can be asked for by number like any other.</span>
                                        <a class="btn btn-outline-secondary btn-sm" href="./cash-bank.html">The transfer desk</a>
                                    </div>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <nav class="erp-card erp-card-tight mt-3" aria-label="More in this module">
                <p class="erp-field-label">More in this module</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="erp-chip erp-chip-outline" href="./petty-cash.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Overview</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-requests.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Requests</a>
                    <a class="erp-chip erp-chip-outline" href="./petty-cash-expenses.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Petty Cash Expenses</a>
                    <a class="erp-chip erp-chip-outline" href="./cash-bank.html"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Cash Transfer</a>
                </div>
            </nav>
        </main>
${footer()}
    </div>
</div>
<script>window.erpNavIndex = [];</script>`;
}

export function cashCounts() {
    const drawers = [
        { code: '1110', name: 'Cash in Hand', where: 'Head office', books: '38,420.00', last: '2026-10-07', gap: 'Short 120.00', state: 'balanced', pending: '' },
        { code: '1110-CNTR', name: 'Counter tin — Dhanmondi', where: 'Dhanmondi outlet', books: '9,650.00', last: '2026-10-08', gap: 'Waiting', state: 'pending', pending: 'Expected 9,650.00, counted 9,410.00' },
        { code: '1110-DEPOT', name: 'Depot tin — Uttara', where: 'Uttara depot', books: '12,800.00', last: 'never counted', gap: '—', state: 'idle', pending: '' },
    ];

    const counts = [
        { date: '2026-10-08', drawer: 'Counter tin — Dhanmondi', books: '9,650.00', counted: '9,410.00', gap: 'Short 240.00', state: 'pending', tone: 'short', by: 'Jamal Uddin', decided: '' },
        { date: '2026-10-07', drawer: 'Cash in Hand', books: '38,540.00', counted: '38,420.00', gap: 'Short 120.00', state: 'posted', tone: 'short', by: 'Rakib Hasan', decided: 'posted to the ledger' },
        { date: '2026-10-06', drawer: 'Depot tin — Uttara', books: '12,750.00', counted: '12,800.00', gap: 'Over 50.00', state: 'posted', tone: 'over', by: 'Sumaiya Akter', decided: 'posted to the ledger' },
        { date: '2026-10-05', drawer: 'Counter tin — Dhanmondi', books: '9,200.00', counted: '8,900.00', gap: 'Short 300.00', state: 'rejected', tone: 'short', by: 'Jamal Uddin', decided: 'refused by Md. Faruk — recount with the supervisor' },
    ];

    const denominations = [
        ['৳ 1000 note', 28], ['৳ 500 note', 4], ['৳ 200 note', 1], ['৳ 100 note', 3],
        ['৳ 50 note', 1], ['৳ 20 note', 1], ['৳ 10 note', 1], ['৳ 5 coin', 2],
    ];

    return `
${previewBar('cash-counts.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Cash count')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Cash &amp; bank · Cash management · Cash count</p>
                    <h1 class="erp-h1">Counting the drawer</h1>
                    <p class="erp-page-sub">Every other screen here believes the ledger. This one asks what is actually in the tin: the notes and coins are written down, added up, and compared with the books, and the gap — if there is one — is the only thing that posts. Above the company's tolerance the gap waits for somebody other than the person holding the money.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-cash-stack" aria-hidden="true"></i> Cash in hand</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-safe" aria-hidden="true"></i> Drawers in this company</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">1 of them never counted yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Waiting for a signature</p>
                    <p class="erp-kpi-value">৳ 240.00</p>
                    <p class="erp-kpi-foot">1 count — the difference has not reached the ledger</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-down-circle" aria-hidden="true"></i> Short this month</p>
                    <p class="erp-kpi-value">৳ 420.00</p>
                    <p class="erp-kpi-foot">Posted to Cash Over &amp; Short (5250)</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> Over this month</p>
                    <p class="erp-kpi-value">৳ 50.00</p>
                    <p class="erp-kpi-foot">3 count(s) posted this month</p>
                </div>
            </div>

            <div class="erp-note erp-note-info mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>A difference of <strong>৳ 100.00</strong> or more is recorded but not posted until somebody other than the counter approves it. Below that, the difference is corrected as it is counted. <a href="./index.html">Cash &amp; Bank settings</a></div>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The drawers<span class="erp-chip erp-chip-outline">3 cash account(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Drawer</th>
                                <th>Where</th>
                                <th class="erp-th-num">Books say</th>
                                <th>Last counted</th>
                                <th>Waiting</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${drawers.map((d) => `
                            <tr>
                                <td>
                                    <span class="erp-cell-strong">${d.code}</span>
                                    <span class="d-block erp-td-muted">${d.name}</span>
                                </td>
                                <td>
                                    ${d.where}
                                    <span class="d-block erp-td-muted"><a href="./cash-bank.html">read the ledger</a></span>
                                </td>
                                <td class="erp-td-num">৳ ${d.books}</td>
                                <td>
                                    ${d.last}
                                    <span class="d-block erp-td-muted">${d.gap === '—' ? '' : (d.state === 'balanced' ? statusChip('balanced', 'Counted exactly') : d.gap)}</span>
                                </td>
                                <td>
                                    ${d.state === 'pending' ? statusChip('pending_approval', 'Awaiting approval') : '<span class="erp-td-muted">nothing waiting</span>'}
                                    ${d.pending ? `<span class="d-block erp-td-muted"><a href="./cash-count.html">${d.pending}</a></span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button">Count it</button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Count a drawer</h2>
                        <p class="erp-card-sub">Write the notes down and the desk adds them up against the figure you type — a breakdown that does not add up to the total is refused, because the whole reason for writing denominations down is that somebody can check them afterwards.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="cc-account">Drawer</label>
                            <select class="form-select" id="cc-account">
                                <option>1110 — Cash in Hand (books say 38,420.00)</option>
                                <option>1110-CNTR — Counter tin — Dhanmondi (books say 9,650.00)</option>
                                <option>1110-DEPOT — Depot tin — Uttara (books say 12,800.00)</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="cc-branch">Kept at</label>
                            <select class="form-select" id="cc-branch">
                                <option>Head office (head office)</option>
                                <option>Dhanmondi outlet</option>
                                <option>Uttara depot</option>
                            </select>
                            <p class="form-text">The ledger's figure is read for this branch, not the whole company.</p>
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="cc-date">Counted on</label>
                            <input class="form-control" type="date" id="cc-date" value="2026-10-08">
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="cc-amount">What was in the tin</label>
                            <input class="form-control" type="text" inputmode="decimal" id="cc-amount" placeholder="9410.00">
                            <p class="form-text">Zero is a perfectly good answer — an empty drawer.</p>
                        </div>
                    </div>

                    <p class="erp-field-label mt-2">Notes and coins (optional, but they have to add up)</p>
                    <div class="erp-count-grid">
                        ${denominations.map(([label, count]) => `
                        <div class="erp-count-row">
                            <span class="erp-count-label">${label}</span>
                            <input class="form-control form-control-sm erp-num" type="text" inputmode="numeric" value="${count}" aria-label="${label} counted">
                        </div>`).join('')}
                    </div>

                    <div class="erp-form-grid mt-2">
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="cc-reason">Why it does not match (when it does not)</label>
                            <input class="form-control" type="text" id="cc-reason" maxlength="300" placeholder="Change given wrong at 4pm — the customer came back">
                            <p class="form-text">Required whenever the counted figure differs from the books: the next person to count this drawer reads it.</p>
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="cc-notes">Anything else worth writing down</label>
                            <input class="form-control" type="text" id="cc-notes" maxlength="300" placeholder="Counted with the shift supervisor present">
                        </div>
                    </div>

                    <button class="btn btn-primary mt-2" type="button"><i class="bi bi-check2-circle" aria-hidden="true"></i> Record the count</button>
                </form>
            </section>

            <form class="erp-filterbar mt-3" onsubmit="return false" role="search">
                <div class="erp-filter">
                    <label class="form-label" for="ccf-account">Drawer</label>
                    <select class="form-select" id="ccf-account"><option>Every drawer</option><option>Cash in Hand</option><option>Counter tin — Dhanmondi</option></select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="ccf-status">State</label>
                    <select class="form-select" id="ccf-status"><option>Everything</option><option>Waiting for approval</option><option>Counted and posted</option><option>Refused</option></select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="ccf-from">From</label>
                    <input class="form-control" type="date" id="ccf-from" value="2026-10-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="ccf-to">To</label>
                    <input class="form-control" type="date" id="ccf-to" value="2026-10-08">
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="./cash-counts.html">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <div class="erp-table-shell mt-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Counts made<span class="erp-chip erp-chip-outline">4 count(s) shown</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Drawer</th>
                                <th class="erp-th-num">Books said</th>
                                <th class="erp-th-num">Counted</th>
                                <th class="erp-th-num">Difference</th>
                                <th>State</th>
                                <th>Counted by</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${counts.map((c) => `
                            <tr>
                                <td>${c.date}</td>
                                <td><span class="erp-cell-strong">${c.drawer}</span></td>
                                <td class="erp-td-num">৳ ${c.books}</td>
                                <td class="erp-td-num">৳ ${c.counted}</td>
                                <td class="erp-td-num">${statusChip(c.tone, c.gap)}</td>
                                <td>
                                    ${statusChip(c.state === 'pending' ? 'pending_approval' : c.state, c.state === 'pending' ? 'Waiting for approval' : (c.state === 'posted' ? 'Counted and posted' : 'Refused'))}
                                    ${c.decided === 'posted to the ledger' ? '<span class="d-block erp-td-muted">posted to the ledger</span>' : ''}
                                </td>
                                <td>
                                    ${c.by}
                                    ${c.decided && c.decided !== 'posted to the ledger' ? `<span class="d-block erp-td-muted">${c.decided}</span>` : ''}
                                </td>
                                <td class="erp-td-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="./cash-count.html">The sheet</a>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>`;
}

export function cashCountSheet() {
    const lines = [
        ['৳ 1000 note', 7, '7,000.00'],
        ['৳ 500 note', 4, '2,000.00'],
        ['৳ 200 note', 1, '200.00'],
        ['৳ 100 note', 2, '200.00'],
        ['৳ 10 note', 1, '10.00'],
    ];

    return `
${previewBar('cash-count.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Cash count sheet')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Cash &amp; bank · Cash management · Cash count</p>
                    <h1 class="erp-h1">The counted drawer</h1>
                    <p class="erp-page-sub">What the books said on the day, what was found in the tin, and the notes and coins that were added up to say so — kept together because a count is evidence rather than a figure. Nothing on this sheet was recalculated afterwards.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./cash-counts.html"><i class="bi bi-arrow-left" aria-hidden="true"></i> All counts</a>
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-journal-text" aria-hidden="true"></i> The drawer's ledger</a>
                </div>
            </header>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div>The difference is <strong>not in the ledger</strong>. It is at or above the tolerance of ৳ 100.0000, so somebody other than Jamal Uddin has to approve it — money that is missing must not be written off by the person who was holding it.</div>
            </div>

            <div class="erp-split">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">1110-CNTR — Counter tin — Dhanmondi</h2>
                            <p class="erp-card-sub">Counted on 2026-10-08 · Dhanmondi outlet</p>
                        </div>
                        ${statusChip('pending_approval', 'Waiting for approval')}
                    </header>
                    <div class="erp-dl erp-dl-tight erp-dl-striped">
                        <dt>Books said</dt>
                        <dd class="erp-money-flat">৳ 9,650.00</dd>
                        <dt>Counted</dt>
                        <dd class="erp-money-flat">৳ 9,410.00</dd>
                        <dt>Difference</dt>
                        <dd>${statusChip('short', 'Short 240.00')}</dd>
                        <dt>Tolerance it was judged against</dt>
                        <dd class="erp-money-flat">৳ 100.0000</dd>
                        <dt>Counted by</dt>
                        <dd>Jamal Uddin</dd>
                        <dt>Why it did not match</dt>
                        <dd>Change given wrong at the counter, two customers came back</dd>
                        <dt>Noted</dt>
                        <dd>Counted with the shift supervisor present</dd>
                    </div>
                </section>

                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">What the drawer was holding</h2>
                            <p class="erp-card-sub">Added up by hand — the figures here are the ones written down at the tin.</p>
                        </div>
                    </header>
                    <div class="erp-table-scroll">
                        <table class="table erp-table erp-table-compact">
                            <thead>
                                <tr>
                                    <th>Denomination</th>
                                    <th class="erp-th-num">Count</th>
                                    <th class="erp-th-num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${lines.map(([label, count, amount]) => `
                                <tr>
                                    <td>${label}</td>
                                    <td class="erp-td-num">${count}</td>
                                    <td class="erp-td-num">৳ ${amount}</td>
                                </tr>`).join('')}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Added up</th>
                                    <th class="erp-th-num">৳ 9,410.00</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Answer for the difference</h2>
                        <p class="erp-card-sub">Approving posts it — a shortage debits Cash Over &amp; Short and credits the drawer. Refusing leaves the books untouched and needs a reason the next counter can read.</p>
                    </div>
                </header>
                <form onsubmit="return false">
                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="ccd-note">Why (kept with the decision)</label>
                        <input class="form-control" type="text" id="ccd-note" maxlength="300" placeholder="The courier was paid from the till and the voucher was filed late">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="button" name="action" value="approve"><i class="bi bi-check2" aria-hidden="true"></i> Approve and post the difference</button>
                        <button class="btn btn-outline-secondary" type="button" name="action" value="reject"><i class="bi bi-x-lg" aria-hidden="true"></i> Refuse the count</button>
                    </div>
                </form>
            </section>

            <p class="erp-filter-note mt-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                This drawer now reads ৳ 9,650.00 in the books, and it was last counted 2026-10-08. Another count of it is still waiting for a decision.
            </p>

        </div>
    </main>
</div>`;
}

/**
 * §08-10 — what the bank takes without asking.
 *
 * The desk the user asked for in this slice: the register of charges the banks
 * have already taken, the rules that will take the next ones by themselves, and
 * the one button that runs what is due now instead of waiting for the morning.
 */
export function bankCharges() {
    const rules = [
        {
            name: 'Account maintenance', account: '1120 — City Bank current', expense: '5280 — Bank Charges',
            terms: '500.00 every quarter', quote: 'would charge ৳ 500.00 today — fixed', rhythm: 'every quarter on the 5th',
            next: '2027-01-05', charged: '7', register: '7 in the register', state: 'active', tone: 'running',
        },
        {
            name: 'Commission on withdrawals', account: '1120 — City Bank current', expense: '5280 — Bank Charges',
            terms: '0.5% of withdrawals, minimum 100.00', quote: 'would charge ৳ 240.00 today — 0.5% of ৳ 48,000.00 of withdrawals since 2026-10-05',
            rhythm: 'every month on the last day', next: '2026-10-31', charged: '11', register: '11 in the register', state: 'active', tone: 'running',
        },
        {
            name: 'SMS alert fee — Nagad', account: '1130 — Nagad merchant', expense: '5280 — Bank Charges',
            terms: '25.00 every month', quote: 'paused — nothing will be charged', rhythm: 'every month on the 1st',
            next: '—', charged: '4', register: '4 in the register', state: 'paused', tone: 'paused',
        },
    ];

    const charges = [
        {
            date: '2026-10-05', no: 'BC-2026-00012', what: 'Quarterly maintenance', account: 'City Bank current', expense: 'Bank Charges',
            basis: '500.00 fixed', origin: 'generated by Account maintenance', amount: '500.00', state: 'posted',
        },
        {
            date: '2026-10-05', no: 'BC-2026-00011', what: 'Commission on withdrawals — September', account: 'City Bank current', expense: 'Bank Charges',
            basis: '0.5% of ৳ 1,86,400.00 of withdrawals', origin: 'generated by Commission on withdrawals', amount: '932.00', state: 'posted',
        },
        {
            date: '2026-09-30', no: 'BC-2026-00010', what: 'Cash handling charge', account: 'City Bank current', expense: 'Bank Charges',
            basis: '400.00 fixed', origin: 'recorded by hand', amount: '400.00', state: 'reversed',
            answer: 'answered by JV-2026-00418 — the bank reversed it',
        },
        {
            date: '2026-09-01', no: 'BC-2026-00009', what: 'SMS alert fee', account: 'Nagad merchant', expense: 'Bank Charges',
            basis: '25.00 fixed', origin: 'generated by SMS alert fee — Nagad', amount: '25.00', state: 'posted',
        },
    ];

    return `
${previewBar('bank-charges.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Bank charges')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-cash-coin" aria-hidden="true"></i> Cash &amp; bank · Bank accounts · Bank charge auto-posting</p>
                    <h1 class="erp-h1">What the bank takes without asking</h1>
                    <p class="erp-page-sub">Account maintenance, SMS alerts, commission on withdrawals: a current account quietly loses money every quarter and none of it arrives as a bill. A rule written here says what the bank takes and when, and from then on the charge posts by itself — Dr the charge, Cr the account it came out of, two lines, no party, because nobody was paid.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./cash-bank.html"><i class="bi bi-bank" aria-hidden="true"></i> The accounts</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Run what is due <span class="erp-chip erp-chip-warn ms-1">2</span></button>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-coin" aria-hidden="true"></i> Taken this month</p>
                    <p class="erp-kpi-value">৳ 1,432.00</p>
                    <p class="erp-kpi-foot">2 charge(s) posted since the first of the month</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calendar-range" aria-hidden="true"></i> Taken this year</p>
                    <p class="erp-kpi-value">৳ 4,318.00</p>
                    <p class="erp-kpi-foot">11 charge(s) — what the banks have taken so far</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-graph-up" aria-hidden="true"></i> Average charge</p>
                    <p class="erp-kpi-value">৳ 392.55</p>
                    <p class="erp-kpi-foot">This year's charges divided by how many there were</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-sliders" aria-hidden="true"></i> Rules in place</p>
                    <p class="erp-kpi-value">2 / 3</p>
                    <p class="erp-kpi-foot">2 have come due — ৳ 740.00 to post</p>
                </div>
            </div>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">2 rule(s) have come due</strong>
                    ৳ 740.00 in charges is waiting to be posted. The scheduled run does this every morning; the button above is for when the statement cannot wait until tomorrow.
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false">
                <div class="erp-filter">
                    <label class="form-label" for="bc-account">Account</label>
                    <select class="form-select" id="bc-account">
                        <option>Every account</option>
                        <option>1120 — City Bank current</option>
                        <option>1130 — Nagad merchant</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="bc-state">State</label>
                    <select class="form-select" id="bc-state">
                        <option>Everything</option>
                        <option>Posted</option>
                        <option>Reversed</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="bc-origin">Came from</label>
                    <select class="form-select" id="bc-origin">
                        <option>Rules and the statement</option>
                        <option>A rule, posted by itself</option>
                        <option>Recorded by hand</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="bc-from">From</label>
                    <input class="form-control" id="bc-from" type="date" value="2026-09-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="bc-to">To</label>
                    <input class="form-control" id="bc-to" type="date" value="2026-10-08">
                </div>
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="bc-q">Search</label>
                    <input class="form-control" id="bc-q" placeholder="Charge number, what it was for, the bank's reference">
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="./bank-charges.html">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            <section class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">
                        What the banks have taken
                        <span class="erp-chip erp-chip-outline">4 charge(s) shown</span>
                    </h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Charge</th>
                                <th>Taken from</th>
                                <th>Booked to</th>
                                <th>Computed from</th>
                                <th class="erp-th-num">Amount</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${charges.map((row) => `
                            <tr>
                                <td>${row.date}</td>
                                <td>
                                    <span class="erp-cell-strong">${row.no}</span>
                                    <div class="erp-td-muted">${row.what}</div>
                                </td>
                                <td>${row.account}</td>
                                <td>${row.expense}</td>
                                <td>
                                    ${row.basis}
                                    <div class="erp-td-muted">${row.origin}</div>
                                </td>
                                <td class="erp-td-num">৳ ${row.amount}</td>
                                <td>
                                    <span class="erp-status erp-status-${row.state}">${row.state === 'posted' ? 'Posted' : 'Reversed'}</span>
                                    ${row.answer ? `<div class="erp-td-muted">${row.answer}</div>` : ''}
                                </td>
                                <td class="erp-td-actions">${row.state === 'posted' ? '<span class="erp-td-muted">Reverse</span>' : '<span class="erp-td-muted">history, not a button</span>'}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">The rules — what the bank is told to take</h2>
                        <p class="erp-card-sub">A rule is a standing statement about a tariff, not a posting: writing one charges nothing. On its day the charge posts by itself, and a rule and a hand-recorded charge can never duplicate each other because the same rule cannot post twice for the same date.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Rule</th>
                                <th>Account</th>
                                <th>Booked to</th>
                                <th>Terms</th>
                                <th>Rhythm</th>
                                <th>Next charge</th>
                                <th class="erp-th-num">Charged so far</th>
                                <th>State</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rules.map((rule) => `
                            <tr>
                                <td><span class="erp-cell-strong">${rule.name}</span></td>
                                <td>${rule.account}</td>
                                <td>${rule.expense}</td>
                                <td>
                                    ${rule.terms}
                                    <div class="erp-td-muted">${rule.quote}</div>
                                </td>
                                <td>${rule.rhythm}</td>
                                <td>${rule.next}</td>
                                <td class="erp-td-num">
                                    ${rule.charged}
                                    <div class="erp-td-muted">${rule.register}</div>
                                </td>
                                <td><span class="erp-status erp-status-${rule.state}">${rule.state === 'active' ? 'Running' : 'Paused'}</span></td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-secondary" type="button">
                                        <i class="bi ${rule.state === 'active' ? 'bi-pause' : 'bi-play'}" aria-hidden="true"></i>
                                        ${rule.state === 'active' ? 'Pause' : 'Resume'}
                                    </button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="erp-split mt-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Record a charge</h2>
                            <p class="erp-card-sub">For a charge that has already happened: pick the rule and the desk computes the amount from its terms, or leave the rule out and enter the figure the statement shows.</p>
                        </div>
                    </header>
                    <form onsubmit="return false">
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-rule">Rule</label>
                                <select class="form-select" id="bc-rule">
                                    <option>No rule — I have the figure</option>
                                    <option>Account maintenance — 500.00 every quarter</option>
                                    <option>Commission on withdrawals — 0.5% of withdrawals, minimum 100.00</option>
                                </select>
                                <small class="form-text">Naming a rule takes the account, the expense account and the amount from it.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-from-account">Taken from</label>
                                <select class="form-select" id="bc-from-account">
                                    <option>Use the rule's account</option>
                                    <option>1120 — City Bank current</option>
                                    <option>1130 — Nagad merchant</option>
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-expense">Booked to</label>
                                <select class="form-select" id="bc-expense">
                                    <option>5280 — Bank Charges</option>
                                    <option>5220 — Rent</option>
                                    <option>5230 — Utilities</option>
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-amount">Amount</label>
                                <input class="form-control" id="bc-amount" placeholder="Leave empty to use the rule's terms">
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-on">Date the bank took it</label>
                                <input class="form-control" id="bc-on" type="date" value="2026-10-08">
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bc-ref">Bank's reference</label>
                                <input class="form-control" id="bc-ref" placeholder="From the statement, if there is one">
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="bc-narration">What it was for</label>
                                <input class="form-control" id="bc-narration" placeholder="Quarterly account maintenance">
                            </div>
                        </div>
                        <button class="btn btn-primary mt-2" type="button"><i class="bi bi-cash-coin" aria-hidden="true"></i> Record and post the charge</button>
                    </form>
                </section>

                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Write a rule</h2>
                            <p class="erp-card-sub">Nothing is charged by writing one. A commission is computed on the money that left the account since the last charge, so the figure can be checked against the bank's own arithmetic.</p>
                        </div>
                    </header>
                    <form onsubmit="return false">
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-name">Name</label>
                                <input class="form-control" id="bcr-name" placeholder="Account maintenance">
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-account">Taken from</label>
                                <select class="form-select" id="bcr-account">
                                    <option>1120 — City Bank current</option>
                                    <option>1130 — Nagad merchant</option>
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-expense">Booked to</label>
                                <select class="form-select" id="bcr-expense"><option>5280 — Bank Charges</option></select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-basis">Terms</label>
                                <select class="form-select" id="bcr-basis">
                                    <option>A fixed amount</option>
                                    <option>A percentage of what leaves the account</option>
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-amount">Fixed amount</label>
                                <input class="form-control" id="bcr-amount" placeholder="500.00">
                                <small class="form-text">Used when the terms are a fixed amount.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-rate">Rate %</label>
                                <input class="form-control" id="bcr-rate" placeholder="0.15">
                                <small class="form-text">Used when the terms are a percentage of what left the account.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-min">Minimum</label>
                                <input class="form-control" id="bcr-min" placeholder="100.00">
                                <small class="form-text">A floor under a commission — “0.15%, minimum ৳100”. A quarter in which nothing left the account is charged nothing.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-frequency">How often</label>
                                <select class="form-select" id="bcr-frequency">
                                    <option>Every month</option>
                                    <option selected>Every quarter</option>
                                    <option>Every half year</option>
                                    <option>Every year</option>
                                </select>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-day">On which day</label>
                                <input class="form-control" id="bcr-day" value="5">
                                <small class="form-text">A short month takes its last day rather than spilling into the next one.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-starts">First date</label>
                                <input class="form-control" id="bcr-starts" type="date" value="2026-10-08">
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="bcr-ends">Last date</label>
                                <input class="form-control" id="bcr-ends" type="date">
                                <small class="form-text">Optional. A rule whose last date has passed stops itself.</small>
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="bcr-narration">What it is</label>
                                <input class="form-control" id="bcr-narration" placeholder="Quarterly maintenance charge on the current account">
                            </div>
                        </div>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" checked>
                            <span class="form-check-label">Run it</span>
                        </label>
                        <button class="btn btn-primary mt-2" type="button"><i class="bi bi-sliders" aria-hidden="true"></i> Write the rule</button>
                    </form>
                </section>
            </div>

            <p class="erp-filter-note mt-2">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Recording what the bank has taken and writing the rules that post charges by themselves are two separate permissions — the second is the one that can quietly move money every quarter for years.
            </p>

            <p class="erp-filter-note mt-2">
                <i class="bi bi-signpost-split" aria-hidden="true"></i>
                <span>Related: <a href="./cash-bank.html">The accounts</a> · <a href="./bank-recon.html">Bank reconciliation</a> · <a href="./expenses.html">Expenses</a></span>
            </p>

        </div>
    </main>
</div>`;
}

/**
 * §08-20 — the expense report.
 *
 * The screen whose whole job is to agree with the ledger: posted expenses only,
 * grouped by the categories that *are* accounts, with the ledger check printed
 * underneath rather than kept as an assertion in somebody's head.
 */
export function expenseReport() {
    const byCategory = [
        { category: 'Office rent', account: '5220 — Rent Expense', rows: 4, amount: '1,86,000.00', share: '38.6' },
        { category: 'Utilities', account: '5230 — Utilities Expense', rows: 9, amount: '1,12,450.00', share: '23.4' },
        { category: 'Transport & delivery', account: '5270 — Delivery Expense', rows: 22, amount: '94,300.00', share: '19.6' },
        { category: 'Bank charges', account: '5280 — Bank Charges', rows: 4, amount: '88,432.00', share: '18.4' },
    ];

    const rows = [
        { date: '2026-10-05', no: 'EXP-2026-00041', category: 'Office rent', account: '5220', branch: 'Head office', payee: 'Landlord', settled: 'Paid from an account', from: 'City Bank current', amount: '46,500.00', entry: 'JV-2026-004412', generated: true },
        { date: '2026-10-05', no: 'EXP-2026-00042', category: 'Bank charges', account: '5280', branch: 'Head office', payee: 'City Bank', settled: 'Paid from an account', from: 'City Bank current', amount: '932.00', entry: 'BC-2026-00012', generated: true },
        { date: '2026-10-04', no: 'EXP-2026-00040', category: 'Utilities', account: '5230', branch: 'Uttara depot', payee: 'DESCO', settled: 'Owed to a supplier', from: '—', amount: '38,900.00', entry: 'JV-2026-004401', generated: false },
        { date: '2026-10-03', no: 'EXP-2026-00039', category: 'Transport & delivery', account: '5270', branch: 'Dhanmondi outlet', payee: 'Sundarban Courier', settled: 'Paid from an account', from: 'Cash in Hand', amount: '4,250.00', entry: 'JV-2026-004388', generated: false },
    ];

    const ledger = [
        { account: '5220 — Rent Expense', category: 'Office rent', reported: '1,86,000.00', debit: '1,86,000.00', credit: '0.00', difference: 'agrees' },
        { account: '5230 — Utilities Expense', category: 'Utilities', reported: '1,12,450.00', debit: '1,13,650.00', credit: '1,200.00', difference: '-1,200.00' },
        { account: '5270 — Delivery Expense', category: 'Transport & delivery', reported: '94,300.00', debit: '94,300.00', credit: '0.00', difference: 'agrees' },
        { account: '5280 — Bank Charges', category: 'Bank charges', reported: '88,432.00', debit: '88,432.00', credit: '0.00', difference: 'agrees' },
    ];

    return `
${previewBar('expense-report.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Expense report')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-receipt" aria-hidden="true"></i> Cash &amp; bank · Expenses · Expense Reports</p>
                    <h1 class="erp-h1">What the company spent</h1>
                    <p class="erp-page-sub">Every figure here is a posted expense — a bill waiting for a signature is not in these totals, because it is not in the ledger yet. That is the test this report is built to pass: pick a category, look up the account it points at, and the ledger says the same number.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./expenses.html"><i class="bi bi-receipt" aria-hidden="true"></i> The register</a>
                    <a class="btn btn-outline-secondary" href="./cash-flow.html"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cash flow</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-cash-stack" aria-hidden="true"></i> Posted in this window</p>
                    <p class="erp-kpi-value">৳ 4,81,182.00</p>
                    <p class="erp-kpi-foot">39 expense(s) between 2026-10-01 and 2026-10-08</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-box-arrow-up" aria-hidden="true"></i> Paid out of an account</p>
                    <p class="erp-kpi-value">৳ 3,44,332.00</p>
                    <p class="erp-kpi-foot">The rest was owed — ৳ 1,36,850.00 posted to payables</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-diagram-3" aria-hidden="true"></i> Where it goes</p>
                    <p class="erp-kpi-value">Office rent</p>
                    <p class="erp-kpi-foot">৳ 1,86,000.00 — 38.6% of the window</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Ledger check</p>
                    <p class="erp-kpi-value">৳ -1,200.00</p>
                    <p class="erp-kpi-foot">Posted expenses against the debit of every account the categories point at</p>
                </div>
            </div>

            <div class="erp-note erp-note-warn mb-3">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">The ledger carries ৳ 4,79,982.00 where this report counted ৳ 4,81,182.00</strong>
                    The difference is ৳ -1,200.00. That is not a rounding artefact — something posted to one of these accounts that is not an expense on this
                    register: a manual journal, or a credit line correcting one. The table at the bottom names which account it is on.
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false">
                <div class="erp-filter">
                    <label class="form-label" for="er-from">From</label>
                    <input class="form-control" id="er-from" type="date" value="2026-10-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="er-to">To</label>
                    <input class="form-control" id="er-to" type="date" value="2026-10-08">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="er-branch">Branch</label>
                    <select class="form-select" id="er-branch">
                        <option>Every branch</option>
                        <option>Head office</option>
                        <option>Uttara depot</option>
                        <option>Dhanmondi outlet</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="er-category">Category</label>
                    <select class="form-select" id="er-category">
                        <option>Every category</option>
                        <option>Office rent</option>
                        <option>Utilities</option>
                        <option>Transport &amp; delivery</option>
                        <option>Bank charges</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="er-settled">Paid or owed</label>
                    <select class="form-select" id="er-settled">
                        <option>Both</option>
                        <option>Paid from an account</option>
                        <option>Owed to a supplier</option>
                    </select>
                </div>
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="er-q">Search</label>
                    <div class="erp-input-group">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" type="search" id="er-q" placeholder="Expense number, payee or what it was for…">
                    </div>
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="./expense-report.html">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Run the report</button>
                    <button class="btn btn-outline-secondary" type="button"><i class="bi bi-download" aria-hidden="true"></i> CSV</button>
                </div>
            </form>

            <div class="erp-split mb-3">
                <section class="erp-card">
                    <div class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">By category</h2>
                            <p class="erp-card-sub">A category <em>is</em> a ledger account, so this column can be walked straight to the general ledger.</p>
                        </div>
                    </div>
                    <div class="erp-table-scroll">
                        <table class="table erp-table">
                            <thead>
                                <tr><th>Category</th><th>Account</th><th class="erp-th-num">Expenses</th><th class="erp-th-num">Amount</th><th class="erp-th-num">Share</th></tr>
                            </thead>
                            <tbody>
                                ${byCategory.map((row) => `
                                <tr>
                                    <td><span class="erp-cell-strong">${row.category}</span></td>
                                    <td class="erp-td-muted">${row.account}</td>
                                    <td class="erp-td-num">${row.rows}</td>
                                    <td class="erp-td-num">৳ ${row.amount}</td>
                                    <td class="erp-td-num">${row.share}%</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="erp-card">
                    <div class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">By month</h2>
                            <p class="erp-card-sub">The same money, read down the calendar — which is how a budget is compared.</p>
                        </div>
                    </div>
                    <div class="erp-table-scroll">
                        <table class="table erp-table">
                            <thead>
                                <tr><th>Month</th><th class="erp-th-num">Expenses</th><th class="erp-th-num">Amount</th><th class="erp-th-num">Share</th></tr>
                            </thead>
                            <tbody>
                                <tr><td><span class="erp-cell-strong">2026-10</span></td><td class="erp-td-num">39</td><td class="erp-td-num">৳ 4,81,182.00</td><td class="erp-td-num">100.0%</td></tr>
                                <tr><td><span class="erp-cell-strong">2026-09</span></td><td class="erp-td-num">148</td><td class="erp-td-num">৳ 18,42,900.00</td><td class="erp-td-num">100.0%</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The expenses behind the figures<span class="erp-chip erp-chip-outline">4 expense(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr><th>Date</th><th>Expense</th><th>Category</th><th>Branch</th><th>Payee</th><th>Paid or owed</th><th class="erp-th-num">Amount</th><th>Entry</th></tr>
                        </thead>
                        <tbody>
                            ${rows.map((row) => `
                            <tr>
                                <td>${row.date}</td>
                                <td>
                                    <span class="erp-cell-strong font-monospace">${row.no}</span>
                                    ${row.generated ? '<span class="erp-chip erp-chip-outline">from a schedule</span>' : ''}
                                </td>
                                <td>${row.category}<div class="erp-td-muted">${row.account}</div></td>
                                <td class="erp-td-muted">${row.branch}</td>
                                <td>${row.payee}</td>
                                <td>${row.settled}<div class="erp-td-muted">${row.from}</div></td>
                                <td class="erp-td-num">৳ ${row.amount}</td>
                                <td class="erp-td-muted font-monospace">${row.entry}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="erp-card mt-3">
                <div class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Does the ledger agree?</h2>
                        <p class="erp-card-sub">The debit carried by every account the categories above point at, against what this report counted. An expense posts a debit; a credit on one of these accounts is something else — a reversal, a supplier credit, a hand-written journal — and it is why the two can differ.</p>
                    </div>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead>
                            <tr><th>Account</th><th>Category</th><th class="erp-th-num">Reported</th><th class="erp-th-num">Ledger debit</th><th class="erp-th-num">Ledger credit</th><th class="erp-th-num">Difference</th></tr>
                        </thead>
                        <tbody>
                            ${ledger.map((row) => `
                            <tr>
                                <td><span class="erp-cell-strong">${row.account}</span></td>
                                <td class="erp-td-muted">${row.category}</td>
                                <td class="erp-td-num">৳ ${row.reported}</td>
                                <td class="erp-td-num">৳ ${row.debit}</td>
                                <td class="erp-td-num">৳ ${row.credit}</td>
                                <td class="erp-td-num">
                                    ${row.difference === 'agrees'
                                        ? '<span class="erp-status erp-status-posted">agrees</span>'
                                        : `<span class="erp-status erp-status-overdue">৳ ${row.difference}</span>`}
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="p-3 pt-0">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Not in these totals: <strong>3</strong> expense(s) worth ৳ 1,36,850.00 still waiting for a signature, and ৳ 4,250.00 that was reversed —
                        a reversal has its own entry in the ledger, so subtracting it here would count it twice.
                    </p>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/**
 * §08-22 — the cash reports: the bank book, the cash flow and what the tills
 * counted. One page because the catalogue has one leaf for these.
 */
export function cashReports() {
    const banks = [
        { name: 'City Bank current', code: '1120', kind: 'Bank', opening: '4,12,300.00', inn: '3,86,940.00', out: '2,51,880.00', closing: '5,47,360.00', moves: 62, last: '2026-10-08' },
        { name: 'Islami Bank savings', code: '1130', kind: 'Bank', opening: '1,20,000.00', inn: '12,000.00', out: '0.00', closing: '1,32,000.00', moves: 4, last: '2026-10-03' },
        { name: 'bKash merchant', code: '1140', kind: 'bKash wallet', opening: '48,600.00', inn: '94,200.00', out: '61,450.00', closing: '81,350.00', moves: 38, last: '2026-10-08' },
        { name: 'Nagad merchant', code: '1150', kind: 'Nagad wallet', opening: '9,800.00', inn: '25.00', out: '24,300.00', closing: '-14,475.00', moves: 11, last: '2026-10-07', negative: true },
    ];

    const inflows = [
        { account: '4100 — Sales Revenue', type: 'revenue', rows: 34, amount: '4,18,640.00', share: '86.9' },
        { account: '1210 — Accounts Receivable', type: 'asset', rows: 12, amount: '52,300.00', share: '10.9' },
        { account: '4100 — Sales Revenue · counter', type: 'revenue', rows: 9, amount: '10,600.00', share: '2.2' },
    ];

    const outflows = [
        { account: '5210 — Salary Expense', type: 'expense', rows: 6, amount: '1,84,000.00', share: '42.4' },
        { account: '2110 — Accounts Payable', type: 'liability', rows: 41, amount: '1,32,480.00', share: '30.5' },
        { account: '5220 — Rent Expense', type: 'expense', rows: 3, amount: '93,000.00', share: '21.4' },
        { account: '5280 — Bank Charges', type: 'expense', rows: 4, amount: '24,730.00', share: '5.7' },
    ];

    const sessions = [
        { no: 'POS-2026-01187', branch: 'Dhanmondi outlet', opened: '08 Oct 09:12', float: '10,000.00', sales: '38,940.00', inn: '5,000.00', out: '12,000.00', expected: '41,940.00', counted: '41,940.00', state: 'agreed' },
        { no: 'POS-2026-01186', branch: 'Uttara depot', opened: '07 Oct 09:04', float: '8,000.00', sales: '21,300.00', inn: '0.00', out: '2,000.00', expected: '27,300.00', counted: '26,880.00', state: 'short', difference: '-420.00' },
        { no: 'POS-2026-01185', branch: 'Head office counter', opened: '07 Oct 09:31', float: '12,000.00', sales: '54,120.00', inn: '10,000.00', out: '0.00', expected: '76,120.00', counted: '76,450.00', state: 'over', difference: '+330.00' },
        { no: 'POS-2026-01188', branch: 'Dhanmondi outlet', opened: '08 Oct 16:40', float: '10,000.00', sales: '6,150.00', inn: '0.00', out: '0.00', expected: '16,150.00', counted: null, state: 'open' },
    ];

    return `
${previewBar('cash-reports.html')}
<div class="erp-shell">
    ${sidebar('cash_bank')}
    <main class="erp-main">
        ${topbar('Cash reports')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-list-columns" aria-hidden="true"></i> Cash &amp; bank · Cash Reports</p>
                    <h1 class="erp-h1">Where the money is, came from and went</h1>
                    <p class="erp-page-sub">Every figure on this page is a posted journal line. The bank book opens where each account opened and closes at the account's own ledger balance; the cash flow names each movement by the <em>other</em> side of its posting, so a receipt is a sale however the clerk filed it; and moving money between two of our own accounts is reported apart, because it is a change of address rather than income.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./expense-report.html"><i class="bi bi-receipt" aria-hidden="true"></i> Expense report</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-bank" aria-hidden="true"></i> Accounts</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">Banks and wallets this company holds money in</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Opened with</p>
                    <p class="erp-kpi-value">৳ 5,90,700.00</p>
                    <p class="erp-kpi-foot">Everything posted before this window</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Moved in the window</p>
                    <p class="erp-kpi-value">৳ 1,56,055.00</p>
                    <p class="erp-kpi-foot">In ৳ 5,18,765.00 · out ৳ 3,62,710.00</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-safe" aria-hidden="true"></i> Closing</p>
                    <p class="erp-kpi-value">৳ 7,46,235.00</p>
                    <p class="erp-kpi-foot">What the ledger says the banks and wallets hold</p>
                </div>
            </div>

            <form class="erp-filterbar" onsubmit="return false">
                <div class="erp-filter">
                    <label class="form-label" for="cr-from">From</label>
                    <input class="form-control" id="cr-from" type="date" value="2026-10-01">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="cr-to">To</label>
                    <input class="form-control" id="cr-to" type="date" value="2026-10-08">
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="cr-branch">Branch</label>
                    <select class="form-select" id="cr-branch">
                        <option>Every branch</option>
                        <option>Head office</option>
                        <option>Uttara depot</option>
                        <option>Dhanmondi outlet</option>
                    </select>
                </div>
                <div class="erp-filterbar-actions">
                    <a class="btn btn-link" href="./cash-reports.html">Reset</a>
                    <button class="btn btn-primary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Run the report</button>
                    <button class="btn btn-outline-secondary" type="button"><i class="bi bi-download" aria-hidden="true"></i> CSV</button>
                </div>
            </form>

            <div class="erp-table-shell mb-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The accounts<span class="erp-chip erp-chip-outline">4 account(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Account</th><th>Kind</th><th class="erp-th-num">Opening</th><th class="erp-th-num">In</th>
                                <th class="erp-th-num">Out</th><th class="erp-th-num">Closing</th><th class="erp-th-num">Movements</th><th>Last movement</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${banks.map((row) => `
                            <tr>
                                <td>
                                    <a class="erp-cell-strong" href="./cash-bank.html">${row.name}</a>
                                    <div class="erp-td-muted">${row.code}</div>
                                </td>
                                <td><span class="erp-status erp-status-${row.kind === 'Bank' ? 'active' : 'cleared'}">${row.kind}</span></td>
                                <td class="erp-td-num">৳ ${row.opening}</td>
                                <td class="erp-td-num erp-money-in">৳ ${row.inn}</td>
                                <td class="erp-td-num erp-money-out">৳ ${row.out}</td>
                                <td class="erp-td-num">
                                    <span class="${row.negative ? 'erp-money-out' : 'erp-cell-strong'}">৳ ${row.closing}</span>
                                    ${row.negative ? '<div class="erp-td-muted">a wallet cannot be overdrawn — a reconciliation is owed</div>' : ''}
                                </td>
                                <td class="erp-td-num">${row.moves}</td>
                                <td class="erp-td-muted">${row.last}</td>
                            </tr>`).join('')}
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="2">Added up</th>
                                <th class="erp-th-num">5,90,700.00</th>
                                <th class="erp-th-num">5,18,765.00</th>
                                <th class="erp-th-num">3,62,710.00</th>
                                <th class="erp-th-num">7,46,235.00</th>
                                <th class="erp-th-num" colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="erp-split mb-3">
                <section class="erp-card">
                    <div class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Money in</h2>
                            <p class="erp-card-sub">Named by the account on the other side of the posting.</p>
                        </div>
                        <div class="erp-card-actions"><span class="erp-chip erp-chip-ok">৳ 4,81,540.00</span></div>
                    </div>
                    <div class="erp-table-scroll">
                        <table class="table erp-table">
                            <thead><tr><th>Came from</th><th class="erp-th-num">Postings</th><th class="erp-th-num">Amount</th><th class="erp-th-num">Share</th></tr></thead>
                            <tbody>
                                ${inflows.map((row) => `
                                <tr>
                                    <td><span class="erp-cell-strong">${row.account}</span><div class="erp-td-muted">${row.type}</div></td>
                                    <td class="erp-td-num">${row.rows}</td>
                                    <td class="erp-td-num erp-money-in">৳ ${row.amount}</td>
                                    <td class="erp-td-num">${row.share}%</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="erp-card">
                    <div class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Money out</h2>
                            <p class="erp-card-sub">The same reading, on the way out.</p>
                        </div>
                        <div class="erp-card-actions"><span class="erp-chip erp-chip-danger">৳ 4,34,210.00</span></div>
                    </div>
                    <div class="erp-table-scroll">
                        <table class="table erp-table">
                            <thead><tr><th>Went to</th><th class="erp-th-num">Postings</th><th class="erp-th-num">Amount</th><th class="erp-th-num">Share</th></tr></thead>
                            <tbody>
                                ${outflows.map((row) => `
                                <tr>
                                    <td><span class="erp-cell-strong">${row.account}</span><div class="erp-td-muted">${row.type}</div></td>
                                    <td class="erp-td-num">${row.rows}</td>
                                    <td class="erp-td-num erp-money-out">৳ ${row.amount}</td>
                                    <td class="erp-td-num">${row.share}%</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="erp-card mb-3">
                <div class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Between our own accounts</h2>
                        <p class="erp-card-sub">A transfer has a money account on both sides, so it is not cash flow — the company is no richer for moving cash from the tin to the bank. It is listed because leaving it out entirely would make the books look like they moved less money than they did.</p>
                    </div>
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">৳ 44,225.00 moved</span></div>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>Pair</th><th class="erp-th-num">Postings</th><th class="erp-th-num">Moved</th></tr></thead>
                        <tbody>
                            <tr><td><span class="erp-cell-strong">Between our own accounts · 1120 — City Bank current</span></td><td class="erp-td-num">3</td><td class="erp-td-num">৳ 37,225.00</td></tr>
                            <tr><td><span class="erp-cell-strong">Between our own accounts · 1140 — bKash merchant</span></td><td class="erp-td-num">2</td><td class="erp-td-num">৳ 7,000.00</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">What the tills counted<span class="erp-chip erp-chip-outline">4 session(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Session</th><th>Branch</th><th>Opened</th><th class="erp-th-num">Float</th><th class="erp-th-num">Cash sales</th>
                                <th class="erp-th-num">Cash in</th><th class="erp-th-num">Cash out</th><th class="erp-th-num">Expected</th>
                                <th class="erp-th-num">Counted</th><th class="erp-th-num">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${sessions.map((row) => `
                            <tr>
                                <td><span class="erp-cell-strong font-monospace">${row.no}</span></td>
                                <td class="erp-td-muted">${row.branch}</td>
                                <td>
                                    ${row.opened}
                                    ${row.state === 'open'
                                        ? '<span class="erp-status erp-status-active ms-1">open</span>'
                                        : '<div class="erp-td-muted">closed 21:40</div>'}
                                </td>
                                <td class="erp-td-num">${row.float}</td>
                                <td class="erp-td-num">${row.sales}</td>
                                <td class="erp-td-num">${row.inn}</td>
                                <td class="erp-td-num">${row.out}</td>
                                <td class="erp-td-num">${row.expected}</td>
                                <td class="erp-td-num">${row.counted ?? '—'}</td>
                                <td class="erp-td-num">
                                    ${row.state === 'agreed' ? '<span class="erp-status erp-status-posted">agreed</span>' : ''}
                                    ${row.state === 'short' ? `<span class="erp-money-out">${row.difference}</span>` : ''}
                                    ${row.state === 'over' ? `<span class="erp-money-in">${row.difference}</span>` : ''}
                                    ${row.state === 'open' ? '<span class="erp-td-muted">not counted yet</span>' : ''}
                                </td>
                            </tr>`).join('')}
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3">Added up</th>
                                <th class="erp-th-num"></th>
                                <th class="erp-th-num">1,20,510.00</th>
                                <th class="erp-th-num">15,000.00</th>
                                <th class="erp-th-num">14,000.00</th>
                                <th class="erp-th-num" colspan="2"></th>
                                <th class="erp-th-num">-90.00</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <p class="erp-filter-note mt-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                The one block above that is not the ledger's is the till variance, and it does not pretend to be: expected cash is the till's own arithmetic
                (float + cash sales + cash in − cash out) frozen on the session when it closed, and a session still open shows no counted figure at all rather than a
                zero that would read as a perfect count. A difference against the <em>books</em> is answered on the count desk, where it posts.
            </p>

        </div>
    </main>
</div>`;
}

/**
 * §13-01…§13-09 — the report centre: one card per catalogue family, with what
 * each one really holds. The hub pages show the other half of the design: a
 * report the reader cannot open is listed with the key it needs, never hidden.
 */
export function reportCentre() {
    const families = [
        { title: 'Sales reports', icon: 'bi-cart3', built: 16, openable: 16, blurb: 'What sold, to whom, through which channel and at what hour — every figure a posted invoice, a payment or a counter sale.', state: 'built' },
        { title: 'Purchase reports', icon: 'bi-bag-check', built: 6, openable: 6, blurb: 'What the company bought, from whom, and what it still owes for it.', state: 'partial', note: 'Purchase reports proper — bills by supplier, price-movement analysis — are §03 rows that have not been built; what is here reads the buying side through the registers that do exist.' },
        { title: 'Inventory reports', icon: 'bi-boxes', built: 9, openable: 9, blurb: 'What is on the shelf, how long it has been there, what it is worth and what it cost to pack.', state: 'built' },
        { title: 'Customer reports', icon: 'bi-people', built: 7, openable: 5, blurb: 'Who owes what and for how long, with the ledger and the statement behind each name.', state: 'partial', note: 'Collections, referral and loyalty reporting (§05) are not built yet; the account itself — ledger, ageing and statement — is.' },
        { title: 'Supplier reports', icon: 'bi-truck', built: 4, openable: 4, blurb: 'What is owed to each supplier, spread by how long it has been owed.', state: 'partial', note: 'Contract, scoring and document reporting (§06) are not built; the payable side is.' },
        { title: 'Finance reports', icon: 'bi-journal-text', built: 12, openable: 9, blurb: 'The books themselves: trial balance, the general ledger, the money desk and the expense register.', state: 'built' },
        { title: 'VAT &amp; tax reports', icon: 'bi-file-earmark-ruled', built: 1, openable: 1, blurb: 'What the company has to account for to the tax authority, and what has already been filed.', state: 'partial', note: 'A VAT return is a statutory document with its own numbering and its own filing dates (§09-18…§09-28). The invoice-level Mushak 9.1 print exists; the period register behind it does not yet.' },
        { title: 'Employee reports', icon: 'bi-person-badge', built: 6, openable: 4, blurb: 'Attendance and leave today; salary, overtime and loan reporting once payroll posts.', state: 'partial', note: 'Payroll itself (§10) is not built, so there is nothing to report on yet. Attendance and leave are real.' },
        { title: 'Marketing reports', icon: 'bi-megaphone', built: 0, openable: 0, blurb: 'What a campaign cost and what it brought back in, measured on the orders it actually produced.', state: 'empty', note: 'The campaign engine itself (§11) is not built, so there is nothing to measure yet. Promotion performance is real, and sits on the sales hub.' },
    ];

    return `
${previewBar('reports.html')}
<div class="erp-shell">
    ${sidebar('insight')}
    <main class="erp-main">
        ${topbar('Reports')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Reports</p>
                    <h1 class="erp-h1">Every report this system can actually open</h1>
                    <p class="erp-page-sub">One page per family the catalogue names, and nothing on it that does not exist: these counts are read out of the router, so a report appears here the moment its route is registered — and a report nobody has built yet cannot be listed at all. Where a family is thin, the hub says what is missing and why instead of leaving a blank card.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./reports-custom.html"><i class="bi bi-sliders" aria-hidden="true"></i> Custom reports</a>
                    <a class="btn btn-outline-secondary" href="./reports-scheduled.html"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-diagram-3" aria-hidden="true"></i> Families</p>
                    <p class="erp-kpi-value">9</p>
                    <p class="erp-kpi-foot">One page each, with its own permission — reading the sales reports is not reading the payroll</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Reports reachable</p>
                    <p class="erp-kpi-value">62</p>
                    <p class="erp-kpi-foot">Routes this installation really registers, counted from the router</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-unlock" aria-hidden="true"></i> You may open</p>
                    <p class="erp-kpi-value">55</p>
                    <p class="erp-kpi-foot">The other 7 are listed on their hubs with the key they need — none are hidden</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Reports that run themselves and file what they produced</p>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                ${families.map((family) => `
                <div class="col">
                    <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title"><i class="bi ${family.icon}" aria-hidden="true"></i> ${family.title}</h2>
                                <p class="erp-card-sub">${family.blurb}</p>
                            </div>
                        </header>

                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <span class="erp-chip erp-chip-outline">${family.built} report(s)</span>
                            ${family.built > family.openable ? `<span class="erp-chip erp-chip-warn">${family.built - family.openable} need another key</span>` : ''}
                            ${family.state === 'partial' ? '<span class="erp-chip erp-chip-soft">still growing</span>' : ''}
                        </div>

                        ${family.note ? `<p class="erp-filter-note mt-2 mb-0">${family.note}</p>` : ''}

                        <div class="mt-auto pt-3 d-flex gap-2">
                            ${family.built === 0
                                ? '<span class="erp-chip erp-chip-soft"><i class="bi bi-dash-circle" aria-hidden="true"></i> nothing to report yet</span>'
                                : family.openable < family.built
                                    ? '<span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> some need another key</span>'
                                    : ''}
                            <a class="btn btn-outline-secondary btn-sm" href="./reports-family.html">Open the hub <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        </div>
                    </section>
                </div>`).join('')}
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">About these pages</h2>
                        <p class="erp-card-sub">Three families in the catalogue are thin or empty — they are here, and they say so.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr><th>Family</th><th>Permission</th><th class="erp-th-num">Reports</th><th>State</th></tr>
                        </thead>
                        <tbody>
                            <tr><td><span class="erp-cell-strong">Sales reports</span></td><td class="erp-td-muted font-monospace">reports.sales</td><td class="erp-td-num">16</td><td><span class="erp-status erp-status-posted">built</span></td></tr>
                            <tr><td><span class="erp-cell-strong">Finance reports</span></td><td class="erp-td-muted font-monospace">reports.finance</td><td class="erp-td-num">12</td><td><span class="erp-status erp-status-posted">built</span></td></tr>
                            <tr><td><span class="erp-cell-strong">VAT &amp; tax reports</span></td><td class="erp-td-muted font-monospace">reports.tax</td><td class="erp-td-num">1</td><td><span class="erp-status erp-status-active">partly built</span></td></tr>
                            <tr><td><span class="erp-cell-strong">Marketing reports</span></td><td class="erp-td-muted font-monospace">reports.marketing</td><td class="erp-td-num">0</td><td><span class="erp-status erp-status-draft">nothing to report yet</span></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 pt-0">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        A report whose route needs an argument — one account, one customer, one employee — is listed on its hub with the
                        <strong>register</strong> it is opened from, because a hub can only link to things that open by themselves.
                    </p>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/**
 * A family hub. Finance is the family that shows both halves at once: reports
 * this reader may open, reports that exist but need another key, and a report
 * that lives on a detail page and is therefore reached through its register.
 */
export function reportFamily() {
    const openable = [
        { title: 'Trial balance', answers: 'Whether the books balance, account by account, as at a date.', reads: 'Posted journal lines', permission: 'accounting.reports.view', url: './cash-bank.html' },
        { title: 'Chart of accounts', answers: 'The accounts themselves with their balances — the frame every other report is drawn on.', reads: 'The chart and its posted balances', permission: 'accounting.coa.view', url: './cash-bank.html' },
        { title: 'Cash book', answers: 'One money account read the way a bank statement reads.', reads: 'Posted journal lines of that account', permission: 'cash.reports', url: './cash-reports.html' },
        { title: 'Bank book', answers: 'Every bank and wallet side by side.', reads: 'Posted journal lines of every money account', permission: 'cash.reports', url: './cash-reports.html' },
        { title: 'Cash flow', answers: 'Where the money came from and where it went.', reads: 'The counterpart of every posting on a money account', permission: 'cash.reports', url: './cash-reports.html' },
        { title: 'Till variances', answers: 'What each counter session counted against what it should have held.', reads: 'Counter sessions — the screen says it is not the ledger', permission: 'cash.reports', url: './cash-reports.html' },
        { title: 'Expense report', answers: 'What the company spent, by category, branch and month, with the ledger check beside it.', reads: 'Posted expenses and the accounts their categories point at', permission: 'expenses.reports', url: './expense-report.html' },
        { title: 'General ledger', answers: 'Every posting on one account, with a running balance.', reads: 'Posted journal lines', permission: 'accounting.reports.view', register: true },
    ];

    const locked = [
        { title: 'Opening trial balance', answers: 'What the books opened with, before anything was posted.', reads: 'Opening entries', permission: 'accounting.reports.view' },
        { title: 'Cheque register', answers: 'Cheques received and issued, and which ones a bank has paid.', reads: 'The cheque register', permission: 'cheques.view' },
        { title: 'Trial balance rebuild', answers: 'Rebuild the derived balances and see what moved — the one page here that writes.', reads: 'The ledger, re-derived from its postings', permission: 'accounting.reports.view' },
    ];

    const card = (entry, allowed) => `
                <div class="col">
                    <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">${entry.title}</h2>
                                <p class="erp-card-sub">${entry.answers}</p>
                            </div>
                            ${allowed ? '' : '<span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> locked</span>'}
                        </header>

                        <dl class="erp-dl erp-dl-tight mt-2">
                            <dt class="erp-field-label">Reads</dt>
                            <dd>${entry.reads}</dd>
                            <dt class="erp-field-label">Permission</dt>
                            <dd class="font-monospace mb-0">${entry.permission}</dd>
                        </dl>

                        <div class="mt-auto pt-3 d-flex flex-wrap gap-2">
                            ${allowed
                                ? `<a class="btn btn-outline-secondary btn-sm" href="${entry.url}">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a>`
                                : `<span class="erp-chip erp-chip-soft">Needs <span class="font-monospace ms-1">${entry.permission}</span></span>`}
                            ${entry.register ? '<span class="erp-chip erp-chip-outline"><i class="bi bi-journal-text" aria-hidden="true"></i> opened from the register</span>' : ''}
                        </div>
                    </section>
                </div>`;

    return `
${previewBar('reports-family.html')}
<div class="erp-shell">
    ${sidebar('insight')}
    <main class="erp-main">
        ${topbar('Finance reports')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i> Reports · Finance Reports</p>
                    <h1 class="erp-h1">Finance reports</h1>
                    <p class="erp-page-sub">The books themselves: trial balance, the general ledger, the money desk and the expense register. Every row below is a report this installation really registers, with the question it answers and where its numbers come from; the ones your role does not open are listed with the key they need rather than hidden.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./reports.html"><i class="bi bi-grid" aria-hidden="true"></i> All families</a>
                    <a class="btn btn-outline-secondary" href="./reports-scheduled.html"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-text" aria-hidden="true"></i> Reports in this family</p>
                    <p class="erp-kpi-value">12</p>
                    <p class="erp-kpi-foot">Registered routes, counted from the router</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-unlock" aria-hidden="true"></i> You may open</p>
                    <p class="erp-kpi-value">9</p>
                    <p class="erp-kpi-foot">3 more are listed below with the key they need</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-key" aria-hidden="true"></i> Needs the hub key</p>
                    <p class="erp-kpi-value">reports.finance</p>
                    <p class="erp-kpi-foot">The permission this whole page is behind</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled runs</p>
                    <p class="erp-kpi-value">2</p>
                    <p class="erp-kpi-foot">Active schedules whose definitions live in this company</p>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-xl-2 g-3">
                ${openable.map((entry) => card(entry, true)).join('')}
                ${locked.map((entry) => card(entry, false)).join('')}
            </div>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">The other families</h2>
                        <p class="erp-card-sub">The rest of the centre, with what each one holds.</p>
                    </div>
                </header>
                <div class="d-flex flex-wrap gap-2 p-3">
                    <a class="erp-chip erp-chip-outline" href="./reports-family.html"><i class="bi bi-cart3" aria-hidden="true"></i>Sales reports <span class="erp-td-muted">16</span></a>
                    <a class="erp-chip erp-chip-outline" href="./reports-family.html"><i class="bi bi-bag-check" aria-hidden="true"></i>Purchase reports <span class="erp-td-muted">6</span></a>
                    <a class="erp-chip erp-chip-outline" href="./reports-family.html"><i class="bi bi-boxes" aria-hidden="true"></i>Inventory reports <span class="erp-td-muted">9</span></a>
                    <a class="erp-chip erp-chip-outline" href="./reports-family.html"><i class="bi bi-people" aria-hidden="true"></i>Customer reports <span class="erp-td-muted">7</span></a>
                    <a class="erp-chip erp-chip-outline" href="./reports-family.html"><i class="bi bi-truck" aria-hidden="true"></i>Supplier reports <span class="erp-td-muted">4</span></a>
                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i>VAT &amp; tax reports</span>
                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i>Employee reports</span>
                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i>Marketing reports</span>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/**
 * §13-11 — the register of custom reports, and §13-12 — the schedules that run
 * them. Two pages in the app; both are about reports rather than of them.
 */
export function reportsCustom() {
    const definitions = [
        { name: 'Weekly receivables ageing', code: 'WEEKLY-AR', source: 'invoices', columns: 7, filters: 2, savedFilters: 3, runs: 14, by: 'Manager' },
        { name: 'Branch day-book', code: 'BRANCH-DAY', source: 'invoices', columns: 9, filters: 3, savedFilters: 1, runs: 42, by: 'Accountant' },
        { name: 'Slow movers by warehouse', code: 'SLOW-MOV', source: 'stock_movements', columns: 6, filters: 2, savedFilters: 2, runs: 9, by: 'Store keeper' },
        { name: 'Chase list for the callers', code: 'CHASE-90', source: 'invoices', columns: 5, filters: 4, savedFilters: 5, runs: 31, by: 'Manager' },
    ];

    const runs = [
        { when: '2026-10-08 06:15', report: 'Weekly receivables ageing', by: 'the scheduler', state: 'completed', rows: 148, note: '—' },
        { when: '2026-10-08 06:15', report: 'Branch day-book', by: 'the scheduler', state: 'completed', rows: 640, note: '—' },
        { when: '2026-10-08 06:15', report: 'Chase list for the callers', by: 'the scheduler', state: 'failed', rows: 0, note: 'The branch you asked for is not in your scope.' },
        { when: '2026-10-07 16:02', report: 'Slow movers by warehouse', by: 'Store keeper', state: 'completed', rows: 62, note: '—' },
        { when: '2026-10-07 06:15', report: 'Branch day-book', by: 'the scheduler', state: 'completed', rows: 617, note: '—' },
    ];

    return `
${previewBar('reports-custom.html')}
<div class="erp-shell">
    ${sidebar('insight')}
    <main class="erp-main">
        ${topbar('Custom reports')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-sliders" aria-hidden="true"></i> Reports · Custom Reports</p>
                    <h1 class="erp-h1">Reports this company wrote for itself</h1>
                    <p class="erp-page-sub">A saved report is a source, a set of columns and a set of filters — stored as those keys, never as SQL, and re-validated against the builder's whitelist on every single run, so a definition saved last year cannot reach a column that has since been closed or a branch the reader cannot see. Executions are kept with their row count and their snapshot, which is why a schedule that failed can say so rather than looking like it produced nothing.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./reports.html"><i class="bi bi-grid" aria-hidden="true"></i> All families</a>
                    <a class="btn btn-primary" href="#"><i class="bi bi-sliders" aria-hidden="true"></i> Open the builder</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i> Saved reports</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">Definitions this company owns — shared, not per person</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-funnel" aria-hidden="true"></i> Saved filters</p>
                    <p class="erp-kpi-value">11</p>
                    <p class="erp-kpi-foot">Named filter sets hanging off those definitions</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-play-circle" aria-hidden="true"></i> Runs recorded</p>
                    <p class="erp-kpi-value">96</p>
                    <p class="erp-kpi-foot">Every execution, with its row count and a snapshot of what it produced</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Definitions that run themselves — see Scheduled reports</p>
                </div>
            </div>

            <div class="erp-note mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">What a custom report can be built on</strong>
                    The builder whitelists 4 sources: invoices, payments, stock_movements, purchase_bills. Columns are re-checked against that list on every run,
                    so a definition cannot outlive the column it names — and branch scope is applied at run time from your own access, never from what
                    whoever wrote the definition could see.
                </div>
            </div>

            <div class="erp-table-shell mb-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The definitions<span class="erp-chip erp-chip-outline">4 saved</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead>
                            <tr><th>Report</th><th>Code</th><th>Source</th><th class="erp-th-num">Columns</th><th class="erp-th-num">Filters</th><th class="erp-th-num">Saved sets</th><th class="erp-th-num">Runs</th><th></th></tr>
                        </thead>
                        <tbody>
                            ${definitions.map((row) => `
                            <tr>
                                <td><span class="erp-cell-strong">${row.name}</span><div class="erp-td-muted">written by ${row.by}</div></td>
                                <td class="erp-td-muted font-monospace">${row.code}</td>
                                <td><span class="erp-chip erp-chip-soft">${row.source}</span></td>
                                <td class="erp-td-num">${row.columns}</td>
                                <td class="erp-td-num">${row.filters}</td>
                                <td class="erp-td-num">${row.savedFilters}</td>
                                <td class="erp-td-num">${row.runs}</td>
                                <td class="text-end">
                                    <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-play" aria-hidden="true"></i> Run</button>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Recent runs</h2>
                        <p class="erp-card-sub">What actually came out, including the failures — a run that failed is recorded as failed, with the reason, rather than as an empty report.</p>
                    </div>
                    <div class="erp-card-actions">
                        <a class="erp-chip erp-chip-outline" href="./reports-scheduled.html">Scheduled reports</a>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-compact">
                        <thead><tr><th>When</th><th>Report</th><th>By</th><th>State</th><th class="erp-th-num">Rows</th><th>Note</th></tr></thead>
                        <tbody>
                            ${runs.map((row) => `
                            <tr>
                                <td>${row.when}</td>
                                <td><span class="erp-cell-strong">${row.report}</span></td>
                                <td class="erp-td-muted">${row.by}</td>
                                <td><span class="erp-status erp-status-${row.state === 'completed' ? 'posted' : 'failed'}">${row.state}</span></td>
                                <td class="erp-td-num">${row.rows}</td>
                                <td class="erp-td-muted">${row.note}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

export function reportsScheduled() {
    const schedules = [
        { name: 'Weekly ageing to the manager', report: 'Weekly receivables ageing', code: 'WEEKLY-AR', frequency: 'weekly', next: '2026-10-12 06:15', last: '2026-10-05 06:15', active: true, by: 'Manager' },
        { name: 'Branch day-book, every morning', report: 'Branch day-book', code: 'BRANCH-DAY', frequency: 'daily', next: '2026-10-09 06:15', last: '2026-10-08 06:15', active: true, by: 'Accountant' },
        { name: 'Chase list for the callers', report: 'Chase list for the callers', code: 'CHASE-90', frequency: 'daily', next: '2026-10-09 06:15', last: '2026-10-08 06:15', active: true, by: 'Manager' },
        { name: 'Slow movers, month end', report: 'Slow movers by warehouse', code: 'SLOW-MOV', frequency: 'monthly', next: '2026-11-01 06:15', last: '2026-10-01 06:15', active: false, by: 'Store keeper' },
    ];

    const runs = [
        { when: '2026-10-08 06:15', schedule: 'Chase list for the callers', state: 'failed', rows: 0, note: 'The branch you asked for is not in your scope.' },
        { when: '2026-10-08 06:15', schedule: 'Branch day-book, every morning', state: 'completed', rows: 640, note: null },
        { when: '2026-10-05 06:15', schedule: 'Weekly ageing to the manager', state: 'completed', rows: 151, note: null },
    ];

    return `
${previewBar('reports-scheduled.html')}
<div class="erp-shell">
    ${sidebar('insight')}
    <main class="erp-main">
        ${topbar('Scheduled reports')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-clock-history" aria-hidden="true"></i> Reports · Scheduled Reports</p>
                    <h1 class="erp-h1">Reports that run themselves</h1>
                    <p class="erp-page-sub">A schedule is an instruction to produce a report on a rhythm, not a promise that it worked. So every execution is written down with its row count and its snapshot, a failure keeps its reason on the row, and the next run time only moves when a run has actually finished — which is what makes “it ran last night” a fact on this page rather than something somebody remembers.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./reports.html"><i class="bi bi-grid" aria-hidden="true"></i> All families</a>
                    <a class="btn btn-outline-secondary" href="./reports-custom.html"><i class="bi bi-file-earmark-ruled" aria-hidden="true"></i> Saved reports</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i> Schedules</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">3 active, 1 paused</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-alarm" aria-hidden="true"></i> Due now</p>
                    <p class="erp-kpi-value">0</p>
                    <p class="erp-kpi-foot">Active schedules whose next run time has arrived — the command picks them up</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-archive" aria-hidden="true"></i> Runs filed</p>
                    <p class="erp-kpi-value">96</p>
                    <p class="erp-kpi-foot">Executions recorded against a schedule, most recent first</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Last failure</p>
                    <p class="erp-kpi-value">08 Oct 06:15</p>
                    <p class="erp-kpi-foot">A failed run keeps its reason; it is never silently retried as if it had worked</p>
                </div>
            </div>

            <div class="erp-table-shell mb-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">What is scheduled<span class="erp-chip erp-chip-outline">4 schedule(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead>
                            <tr><th>Schedule</th><th>Report</th><th>Rhythm</th><th>Next run</th><th>Last run</th><th>State</th><th>Written by</th></tr>
                        </thead>
                        <tbody>
                            ${schedules.map((row) => `
                            <tr>
                                <td><span class="erp-cell-strong">${row.name}</span></td>
                                <td>${row.report}<div class="erp-td-muted font-monospace">${row.code}</div></td>
                                <td><span class="erp-chip erp-chip-soft">${row.frequency}</span></td>
                                <td>${row.next}</td>
                                <td class="erp-td-muted">${row.last}</td>
                                <td><span class="erp-status erp-status-${row.active ? 'active' : 'paused'}">${row.active ? 'active' : 'paused'}</span></td>
                                <td class="erp-td-muted">${row.by}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="erp-split mb-3">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Schedule a saved report</h2>
                            <p class="erp-card-sub">The definition has to exist and belong to this company. A schedule starts due immediately, so the next run of the command produces it.</p>
                        </div>
                    </header>
                    <form class="erp-form p-3" onsubmit="return false">
                        <div class="erp-form-field">
                            <label class="erp-field-label" for="sch-def">Report</label>
                            <select class="form-select" id="sch-def">
                                <option>Weakly receivables ageing (WEEKLY-AR)</option>
                                <option>Branch day-book (BRANCH-DAY)</option>
                                <option>Slow movers by warehouse (SLOW-MOV)</option>
                                <option>Chase list for the callers (CHASE-90)</option>
                            </select>
                        </div>
                        <div class="erp-form-field">
                            <label class="erp-field-label" for="sch-name">Name it</label>
                            <input class="form-control" id="sch-name" type="text" placeholder="e.g. Weekly receivables ageing for the manager">
                            <p class="form-text">This is what the run log will call it, so name it for the person who reads it, not for the table.</p>
                        </div>
                        <div class="erp-form-field">
                            <label class="erp-field-label" for="sch-freq">How often</label>
                            <select class="form-select" id="sch-freq">
                                <option>Daily</option>
                                <option>Weekly</option>
                                <option>Monthly</option>
                            </select>
                        </div>
                        <div class="erp-form-actions">
                            <button class="btn btn-primary" type="button"><i class="bi bi-plus-lg" aria-hidden="true"></i> Schedule it</button>
                        </div>
                    </form>
                </section>

                <section class="erp-card">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Runs filed by the scheduler</h2>
                            <p class="erp-card-sub">What each scheduled run produced, and what it could not.</p>
                        </div>
                    </header>
                    <div class="erp-table-scroll">
                        <table class="table erp-table erp-table-compact">
                            <thead><tr><th>When</th><th>Schedule</th><th>State</th><th class="erp-th-num">Rows</th></tr></thead>
                            <tbody>
                                ${runs.map((row) => `
                                <tr>
                                    <td>${row.when}</td>
                                    <td><span class="erp-cell-strong">${row.schedule}</span></td>
                                    <td>
                                        <span class="erp-status erp-status-${row.state === 'completed' ? 'posted' : 'failed'}">${row.state}</span>
                                        ${row.note ? `<div class="erp-td-muted">${row.note}</div>` : ''}
                                    </td>
                                    <td class="erp-td-num">${row.rows}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 pt-0">
                        <p class="erp-filter-note mb-0">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            Delivery to recipients and PDF/XLSX filing are not built: a run today produces its rows and records them here. Until a delivery
                            channel exists, this page is the report — it does not pretend to have emailed anything.
                        </p>
                    </div>
                </section>
            </div>

        </div>
    </main>
</div>`;
}

/**
 * §15 — the settings desk. Two pages: the module's own index (every group, what
 * is set, who set it, the key it needs) and one branch's scope, where the
 * company's value is shown beside the branch's — and the policy groups are listed
 * with the reason they cannot be overridden rather than hidden.
 */
export function settingsDesk() {
    const groups = [
        { key: 'general', label: 'General Settings', what: 'Company-wide display and formatting defaults.', fields: 5, set: 3, scope: 'per branch', changed: '2026-10-06 09:14', who: 'Instance Owner', permission: 'settings.general' },
        { key: 'localization', label: 'Bengali / Localization Settings', what: 'Language toggle, Bengali numerals and amount-in-words behaviour.', fields: 4, set: 2, scope: 'per branch', changed: '2026-09-30 17:02', who: 'Instance Owner', permission: 'settings.localization', href: './settings-localization.html' },
        { key: 'security', label: 'Security Settings', what: 'Password policy, lockout policy and session policy.', fields: 10, set: 10, scope: 'company policy', changed: '2026-10-04 11:20', who: 'Instance Owner', permission: 'settings.security' },
        { key: 'notifications', label: 'Notification Settings', what: 'Channel defaults. External channels stay disabled until a real provider is configured.', fields: 5, set: 2, scope: 'company policy', changed: '2026-09-28 08:41', who: 'Instance Owner', permission: 'settings.notifications' },
        { key: 'workflow', label: 'Workflow & Approval Settings', what: 'Defaults for the generic database-driven approval engine.', fields: 3, set: 3, scope: 'company policy', changed: '2026-10-01 15:08', who: 'Instance Owner', permission: 'settings.workflow' },
        { key: 'tax', label: 'VAT & Tax Settings', what: 'Inclusive or exclusive pricing, rounding, the default rate and the form revision on the statutory invoice.', fields: 5, set: 2, scope: 'per branch', changed: '2026-10-08 09:40', who: 'Instance Owner', permission: 'tax.manage', href: './settings-tax.html' },
        { key: 'numbering', label: 'Document Numbering', what: 'How every numbered document in the system is numbered.', fields: 4, set: 4, scope: 'per branch', changed: '2026-09-22 10:03', who: 'Instance Owner', permission: 'settings.numbering' },
        { key: 'audit', label: 'Audit Log Retention', what: 'How long evidence is kept online, and whether it may be exported.', fields: 3, set: 1, scope: 'company policy', changed: '2026-09-22 10:03', who: 'Instance Owner', permission: 'settings.audit' },
        { key: 'pos', label: 'POS Settings', what: 'Receipt paper, footer, rounding and offline behaviour.', fields: 4, set: 3, scope: 'per branch', changed: '2026-10-07 08:30', who: 'Manager', permission: 'pos.settings.configure' },
        { key: 'labels', label: 'Label & Barcode Printing', what: 'Sheet geometry and QR density for the label desk.', fields: 3, set: 2, scope: 'per branch', changed: '2026-10-02 19:55', who: 'Store keeper', permission: 'settings.labels' },
        { key: 'appearance', label: 'Appearance', what: 'Accent colour, density and sidebar behaviour.', fields: 4, set: 1, scope: 'per branch', changed: 'never — still on defaults', who: null, permission: 'settings.appearance' },
    ];

    return `
${previewBar('settings.html')}
<div class="erp-shell">
    ${sidebar('configure')}
    <main class="erp-main">
        ${topbar('Settings')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-sliders" aria-hidden="true"></i> Settings</p>
                    <h1 class="erp-h1">What this company has decided</h1>
                    <p class="erp-page-sub">One row per group of settings, with how many of its values are actually set rather than left at their default, who last touched them, and the key each group needs. Nothing here is decorative: every group on this page is read somewhere in the application — a value nobody reads would be a promise the screen cannot keep.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./settings-branch.html"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch settings</a>
                    <a class="btn btn-outline-secondary" href="./settings.html"><i class="bi bi-sliders" aria-hidden="true"></i> General</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-sliders" aria-hidden="true"></i> Setting groups</p>
                    <p class="erp-kpi-value">16</p>
                    <p class="erp-kpi-foot">12 of them are open to you</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-toggle-on" aria-hidden="true"></i> Values set</p>
                    <p class="erp-kpi-value">41</p>
                    <p class="erp-kpi-foot">Explicit rows in the settings table — everything else is still its declared default</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch overrides</p>
                    <p class="erp-kpi-value">6</p>
                    <p class="erp-kpi-foot">3 branch(es); company values are untouched</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-shield-lock" aria-hidden="true"></i> Invariant floors</p>
                    <p class="erp-kpi-value">7</p>
                    <p class="erp-kpi-foot">Numbers this system will not go below, whatever a form says</p>
                </div>
            </div>

            <div class="erp-note mb-3">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">Some values are floors, not preferences</strong>
                    A password minimum, a lockout threshold, the audit retention window: these are refused below a
                    fixed floor whichever screen asks, and the refusal is written to the audit trail. Company policy
                    (security, audit, workflow, notifications) also cannot be overridden per branch — money and access
                    decisions are the same in every outlet.
                </div>
            </div>

            <div class="erp-table-shell" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The groups<span class="erp-chip erp-chip-outline">15 group(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table erp-table-stack">
                        <thead>
                            <tr>
                                <th>Group</th><th>What it decides</th><th class="erp-th-num">Fields</th><th class="erp-th-num">Set</th>
                                <th>Scope</th><th>Last changed</th><th>Permission</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${groups.map((g) => `
                            <tr>
                                <td><span class="erp-cell-strong">${g.label}</span><div class="erp-td-muted font-monospace">${g.key}</div></td>
                                <td class="erp-td-muted">${g.what}</td>
                                <td class="erp-td-num">${g.fields}</td>
                                <td class="erp-td-num">${g.set}${g.set === 0 ? '<div class="erp-td-muted">defaults</div>' : ''}</td>
                                <td>
                                    ${g.scope === 'per branch'
                                        ? '<span class="erp-chip erp-chip-soft">per branch</span>'
                                        : '<span class="erp-chip erp-chip-outline">company policy</span>'}
                                </td>
                                <td class="erp-td-muted">
                                    ${g.who ? `${g.changed}<div class="erp-td-muted">${g.who}</div>` : g.changed}
                                </td>
                                <td class="font-monospace erp-td-muted">${g.permission}</td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="${g.href ?? './settings.html'}">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="p-3 pt-0">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        “Set” counts the values this company has actually chosen. A group that reads
                        <strong>never — still on defaults</strong> has never been edited, which is a useful thing to know
                        before changing one of its numbers: whatever is in force there came from the system's own defaults,
                        not from a decision somebody made.
                    </p>
                </div>
            </div>

        </div>
    </main>
</div>`;
}

/**
 * §15-03 — one branch's scope: the company's value beside the branch's, and the
 * policy groups that cannot be overridden, with the reason on the row.
 */
export function settingsBranch() {
    const writable = [
        {
            label: 'Label & Barcode Printing',
            what: 'Sheet geometry and QR density for the label desk.',
            own: 1,
            fields: [
                { label: 'Sheet template', company: 'A4 · 3 × 8 (24 per sheet)', value: 'A4 · 3 × 7 (21 per sheet)', overridden: true },
                { label: 'QR error-correction level', company: 'M', value: 'M', overridden: false },
            ],
        },
        {
            label: 'POS Settings',
            what: 'Receipt paper, footer, rounding and offline behaviour.',
            own: 1,
            fields: [
                { label: 'Receipt paper width', company: '80 mm', value: '58 mm', overridden: false },
                { label: 'Receipt footer', company: 'Thank you for shopping with us.', value: 'Thank you for shopping with us.', overridden: false },
            ],
        },
        {
            label: 'General Settings',
            what: 'Company-wide display and formatting defaults.',
            own: 0,
            fields: [
                { label: 'Decimal places for amounts', company: '2', value: '2', overridden: false },
                { label: 'Default landing page after login', company: 'Dashboard', value: 'Dashboard', overridden: false },
            ],
        },
    ];

    const policy = [
        { label: 'Security Settings', key: 'security', why: 'Who may log in and how: a weak outlet would be a weak door into the same books.' },
        { label: 'Audit Log Retention', key: 'audit', why: 'How long evidence is kept. A branch may not shorten the trail its own mistakes are written to.' },
        { label: 'Workflow & Approval Settings', key: 'workflow', why: 'What has to be approved. Approval thresholds are a company\'s control, not a branch\'s preference.' },
        { label: 'Notification Settings', key: 'notifications', why: 'Which channels notify whom. One branch silencing an alert would silence it for the company.' },
    ];

    return `
${previewBar('settings-branch.html')}
<div class="erp-shell">
    ${sidebar('configure')}
    <main class="erp-main">
        ${topbar('Branch settings')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-diagram-3" aria-hidden="true"></i> Settings · Branch Settings</p>
                    <h1 class="erp-h1">Uttara depot</h1>
                    <p class="erp-page-sub">Each row shows what this branch uses beside the company's own value. A value written here replaces the company's for this branch only; the company value is never touched, and removing an override is how this branch goes back to following it.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./settings.html"><i class="bi bi-diagram-3" aria-hidden="true"></i> All branches</a>
                    <a class="btn btn-outline-secondary" href="./settings.html"><i class="bi bi-sliders" aria-hidden="true"></i> Company settings</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch</p>
                    <p class="erp-kpi-value">UTT</p>
                    <p class="erp-kpi-foot">Uttara depot</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-list-check" aria-hidden="true"></i> Own values</p>
                    <p class="erp-kpi-value">2</p>
                    <p class="erp-kpi-foot">Settings this branch has decided for itself</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-arrow-down-left" aria-hidden="true"></i> Inherited</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">Values this branch takes from the company</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-shield-lock" aria-hidden="true"></i> Policy groups</p>
                    <p class="erp-kpi-value">4</p>
                    <p class="erp-kpi-foot">The same in every outlet — listed below, with the reason</p>
                </div>
            </div>

            <div class="erp-note mb-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">2 value(s) here come from this branch, not the company</strong>
                    If a figure on this branch's documents looks wrong to somebody reading the company settings, this is
                    the page that explains it: the branch's own value wins, and the row says so.
                </div>
            </div>

            <form class="erp-form" onsubmit="return false">
                <div class="row row-cols-1 row-cols-xl-2 g-3">
                    ${writable.map((group) => `
                    <div class="col">
                        <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                            <header class="erp-card-head">
                                <div>
                                    <h2 class="erp-card-title">${group.label}</h2>
                                    <p class="erp-card-sub">${group.what}</p>
                                </div>
                                ${group.own > 0 ? `<span class="erp-chip erp-chip-warn">${group.own} own value(s)</span>` : ''}
                            </header>
                            <div class="p-3">
                                ${group.fields.map((field) => `
                                <div class="mb-3">
                                    <label class="erp-field-label d-flex align-items-center gap-2">
                                        ${field.label}
                                        ${field.overridden ? '<span class="erp-chip erp-chip-soft">this branch</span>' : ''}
                                    </label>
                                    ${field.value === '58 mm' || field.value === '80 mm'
                                        ? `<select class="form-select"><option${field.value === '58 mm' ? ' selected' : ''}>58 mm</option><option${field.value === '80 mm' ? ' selected' : ''}>80 mm</option></select>`
                                        : field.value === 'A4 · 3 × 8 (24 per sheet)' || field.value === 'A4 · 3 × 7 (21 per sheet)'
                                            ? `<select class="form-select"><option${field.value.startsWith('A4 · 3 × 8') ? ' selected' : ''}>A4 · 3 × 8 (24 per sheet)</option><option${field.value.startsWith('A4 · 3 × 7') ? ' selected' : ''}>A4 · 3 × 7 (21 per sheet)</option></select>`
                                            : `<input class="form-control" type="text" value="${field.value}">`}
                                    <p class="form-text mb-0">Company value: <strong>${field.company}</strong></p>
                                </div>`).join('')}
                            </div>
                        </section>
                    </div>`).join('')}
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary" type="button"><i class="bi bi-check-lg" aria-hidden="true"></i> Save Uttara depot's values</button>
                    <span class="erp-filter-note align-self-center mb-0">Empty fields mean “follow the company” — they are not stored as blanks.</span>
                </div>
            </form>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">This branch's own values</h2>
                        <p class="erp-card-sub">Removing one deletes the branch's row, so the company's value takes over again. The history of the change stays in the settings trail.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>Group</th><th>Setting</th><th>Branch value</th><th>Company value</th><th></th></tr></thead>
                        <tbody>
                            <tr>
                                <td class="erp-td-muted">Label &amp; Barcode Printing</td>
                                <td><span class="erp-cell-strong">Sheet template</span></td>
                                <td>A4 · 3 × 7 (21 per sheet)</td>
                                <td class="erp-td-muted">A4 · 3 × 8 (24 per sheet)</td>
                                <td class="text-end"><button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Follow the company</button></td>
                            </tr>
                            <tr>
                                <td class="erp-td-muted">POS Settings</td>
                                <td><span class="erp-cell-strong">Receipt paper width</span></td>
                                <td>58 mm</td>
                                <td class="erp-td-muted">80 mm</td>
                                <td class="text-end"><button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Follow the company</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Company policy — the same in every outlet</h2>
                        <p class="erp-card-sub">These groups cannot be overridden per branch, and the reason is not tidiness: they are the rules that make the rest of the books trustworthy.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>Group</th><th>Why it is company-wide</th><th></th></tr></thead>
                        <tbody>
                            ${policy.map((p) => `
                            <tr>
                                <td><span class="erp-cell-strong">${p.label}</span><div class="erp-td-muted font-monospace">${p.key}</div></td>
                                <td class="erp-td-muted">${p.why}</td>
                                <td class="text-end"><span class="erp-chip erp-chip-soft"><i class="bi bi-shield-lock" aria-hidden="true"></i> company-wide</span></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/**
 * §15-23…§15-33 — the maintenance desk. The interesting thing about this page
 * is what it refuses to claim: the first table is the questions an operator asks
 * (high availability, backups, queue worker) answered honestly, and the
 * self-healing block lists what the system will never do to itself.
 */
export function maintenanceDesk() {
    const claims = [
        { label: 'High availability', verdict: 'single instance', tone: 'erp-chip-outline', why: 'One application server and one database. There is no replica, no failover and no load balancer.' },
        { label: 'Database integrity', verdict: 'ok · 2 days ago', tone: 'erp-chip-soft', why: '142 tables checked — every one answered OK.' },
        { label: 'Self-healing', verdict: 'last run 3 days ago', tone: 'erp-chip-outline', why: 'Only derived state is healed: cache, temp files, sessions, search index.' },
        { label: 'Backups', verdict: 'not built in this build', tone: 'erp-chip-warn', why: 'Backup and restore (§15-19) is not implemented, so this page will not report a backup as current.' },
        { label: 'Queue worker', verdict: 'unknown from here', tone: 'erp-chip-outline', why: 'A web request cannot see whether a worker is running. The pending figure is real; the worker is not.' },
    ];

    const operations = [
        { title: 'Clear cache', tone: 'erp-chip-soft', note: 'Allowed self-heal', body: 'Clears the application cache and compiled templates — both reproducible from the database.', does: 'Does not touch: uploads, backups, audit trail, log files, business rows.', button: 'Clear cache' },
        { title: 'End other sessions', tone: 'erp-chip-soft', note: 'Allowed self-heal', body: 'Ends every session except the one you are using — a machine left signed in at a counter.', does: 'Your own session is kept: nothing escapes being the person who did it.', button: 'End other sessions' },
        { title: 'Remove temporary files', tone: 'erp-chip-soft', note: 'Allowed self-heal', body: 'Sweeps storage/app/temp and the file-cache leftovers, skipping anything written in the last 24 hours.', does: 'Never follows a symlink out of the storage tree, never removes a directory.', button: 'Remove temporary files' },
        { title: 'Rebuild the search index', tone: 'erp-chip-soft', note: 'Allowed self-heal', body: 'Rebuilds global search from source tables — users, branches, warehouses, roles, documents.', does: 'The index holds no fact the source tables do not.', button: 'Rebuild search index' },
    ];

    const never = [
        { label: 'Schema changes', why: 'A repair that alters structure has to be a migration somebody reviewed, with a rollback.' },
        { label: 'Posting or reversing entries', why: 'Money moves through documents with an approver and an audit trail.' },
        { label: 'Editing or deleting audit rows', why: 'The trail is the evidence that the rest of this list was followed.' },
        { label: 'Granting permissions', why: 'A heal that widens access is privilege escalation with a friendly name.' },
        { label: 'Rewriting instance identity', why: 'Company, currency and fiscal calendar are protected settings (§15-35).' },
        { label: 'Removing uploaded files', why: 'A file a person uploaded is not garbage because the system cannot see who needs it.' },
    ];

    const counts = [
        { group: 'Access', rows: [['Users', '12'], ['Roles', '5'], ['Branches', '3']] },
        { group: 'Trading', rows: [['Customers', '486'], ['Suppliers', '63'], ['Products', '1,240'], ['Invoices', '3,914']] },
        { group: 'Stock', rows: [['Warehouses', '2'], ['Stock balances', '2,517'], ['Valuation layers', '4,088']] },
        { group: 'Books', rows: [['Accounts', '142'], ['Journal entries', '6,201'], ['Journal lines', '18,442']] },
        { group: 'System', rows: [['Audit events', '41,908'], ['Setting rows', '38'], ['Search index rows', '1,338']] },
    ];

    const history = [
        { at: '07 Oct 2026 09:12', label: 'Cache cleared', status: 'ok', what: 'Cache cleared: 41.2 MB of derived files removed; uploads, backups and the audit trail untouched.', freed: '41.2 MB', by: 'Instance Owner' },
        { at: '06 Oct 2026 22:40', label: 'Integrity check', status: 'ok', what: '142 table(s) checked — every one answered OK.', freed: '—', by: 'Instance Owner' },
        { at: '05 Oct 2026 08:02', label: 'Self-healing run', status: 'ok', what: 'Self-healing run: 4 safe operation(s) executed, 63.8 MB freed.', freed: '63.8 MB', by: 'Instance Owner' },
        { at: '02 Oct 2026 11:15', label: 'Database repaired', status: 'refused', what: 'No integrity check is on file for this company. Run the check first.', freed: '—', by: 'Instance Owner' },
    ];

    return `
${previewBar('maintenance.html')}
<div class="erp-shell">
    ${sidebar('configure')}
    <main class="erp-main">
        ${topbar({ title: 'System maintenance', trail: [{ label: 'Settings & masters' }, { label: 'System maintenance' }] })}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-tools" aria-hidden="true"></i> Settings · System maintenance</p>
                    <h1 class="erp-h1">System maintenance</h1>
                    <p class="erp-page-sub">What this installation is, what may be done to it, and what will never be done to it automatically. Every operation records what it did — how many files, how many bytes, which tables — so “the system felt slower after maintenance” is a question with an answer.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./maintenance-log.html"><i class="bi bi-journal-code" aria-hidden="true"></i> Error log</a>
                    <a class="btn btn-outline-secondary" href="./settings.html"><i class="bi bi-sliders" aria-hidden="true"></i> Settings</a>
                </div>
            </header>

            <div class="erp-kpi-grid mb-3">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-database" aria-hidden="true"></i> Database</p>
                    <p class="erp-kpi-value">412 MB</p>
                    <p class="erp-kpi-foot">mysql 8.0 · 142 tables</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-device-hdd" aria-hidden="true"></i> Storage free</p>
                    <p class="erp-kpi-value">68 GB</p>
                    <p class="erp-kpi-foot">of 120 GB on /var/www/erp</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Pending jobs</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">database queue · oldest queued 4 minutes ago</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i> Failed jobs</p>
                    <p class="erp-kpi-value">0</p>
                    <p class="erp-kpi-foot">Nothing has exhausted its retries</p>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">What this page does not claim</h2>
                        <p class="erp-card-sub">A system information page is only worth reading if it says “unknown” where it cannot see.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>Question</th><th>Answer</th><th>Why</th></tr></thead>
                        <tbody>
                            ${claims.map((c) => `
                            <tr>
                                <td class="erp-cell-strong">${c.label}</td>
                                <td><span class="erp-chip ${c.tone}">${c.verdict}</span></td>
                                <td class="erp-td-muted">${c.why}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title">Application &amp; runtime</h2>
                            <span class="erp-chip erp-chip-outline">measured now</span>
                        </header>
                        <div class="p-3">
                            <dl class="erp-dl erp-dl-tight mb-0">
                                <dt>Application</dt><dd>Erp · production</dd>
                                <dt>Framework</dt><dd>Laravel 13.0 on PHP 8.3.14</dd>
                                <dt>Host</dt><dd>Linux (6.8.0) · nginx/1.24.0</dd>
                                <dt>Timezone &amp; locale</dt><dd>Asia/Dhaka · bn · amounts in BDT</dd>
                                <dt>Memory limit</dt><dd>256M (peak this request: 18 MB)</dd>
                                <dt>Request limits</dt><dd>30s · upload 20M · post 24M</dd>
                            </dl>
                            <p class="erp-filter-note mt-3 mb-1">Extensions this application uses:</p>
                            <div class="d-flex flex-wrap gap-1">
                                ${['pdo', 'mbstring', 'openssl', 'bcmath', 'gd', 'zip', 'intl', 'fileinfo'].map((e) => `<span class="erp-chip erp-chip-soft">${e}</span>`).join('')}
                            </div>
                        </div>
                    </section>
                </div>
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Database, storage &amp; services</h2></header>
                        <div class="p-3">
                            <dl class="erp-dl erp-dl-tight">
                                <dt>Database</dt><dd>mysql · erp @ 127.0.0.1 · 8.0.36</dd>
                                <dt>Size</dt><dd>412 MB · 142 tables · 96 migrations run</dd>
                                <dt>Cache &amp; sessions</dt><dd>database cache · database sessions (14 live)</dd>
                                <dt>Queue &amp; mail</dt><dd>database queue · smtp mailer · local filesystem</dd>
                                <dt>Newest log line</dt><dd>12 minutes ago</dd>
                            </dl>
                            <p class="erp-filter-note mt-3 mb-1">Storage on disk:</p>
                            <table class="table erp-table mb-0">
                                <tbody>
                                    <tr><td class="font-monospace erp-td-muted">storage/app/private</td><td class="erp-td-muted">Uploads &amp; documents</td><td class="erp-td-num">2.4 GB</td><td class="erp-td-num erp-td-muted">3,188 file(s)</td></tr>
                                    <tr><td class="font-monospace erp-td-muted">storage/logs</td><td class="erp-td-muted">Log files</td><td class="erp-td-num">18.2 MB</td><td class="erp-td-num erp-td-muted">6 file(s)</td></tr>
                                    <tr><td class="font-monospace erp-td-muted">storage/framework/cache</td><td class="erp-td-muted">Derived cache</td><td class="erp-td-num">41.2 MB</td><td class="erp-td-num erp-td-muted">220 file(s)</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">What is in the database</h2>
                        <p class="erp-card-sub">Counted in this request. After a restore, these are the first figures to compare — a count that came back as zero is how a silent failure is caught on the day it happens.</p>
                    </div>
                </header>
                <div class="p-3">
                    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                        ${counts.map((c) => `
                        <div class="col">
                            <p class="erp-filter-note mb-1">${c.group}</p>
                            <dl class="erp-dl erp-dl-tight mb-0">
                                ${c.rows.map(([label, value]) => `<dt>${label}</dt><dd>${value}</dd>`).join('')}
                            </dl>
                        </div>`).join('')}
                    </div>
                </div>
            </section>

            <h2 class="erp-h2 mb-2">Safe operations</h2>
            <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
                ${operations.map((op) => `
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title">${op.title}</h2>
                            <span class="erp-chip ${op.tone}">${op.note}</span>
                        </header>
                        <div class="p-3">
                            <p class="text-body-secondary mb-2">${op.body}</p>
                            <p class="erp-filter-note mb-3">${op.does}</p>
                            <button class="btn btn-primary" type="button"><i class="bi bi-check-lg" aria-hidden="true"></i> ${op.button}</button>
                        </div>
                    </section>
                </div>`).join('')}
            </div>

            <h2 class="erp-h2 mb-2">Database</h2>
            <div class="row row-cols-1 row-cols-xl-2 g-3 mb-3">
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Optimise &amp; check</h2></header>
                        <div class="p-3">
                            <p class="text-body-secondary mb-2"><strong>Optimise</strong> reclaims the space free inside the table files and reports how much moved. <strong>Check</strong> asks every table whether it is sound — it reads, it never writes, and its answer is what a repair is allowed to act on.</p>
                            <p class="erp-filter-note mb-3">Last check 2 days ago: <strong>142 table(s) checked — every one answered OK.</strong></p>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="button"><i class="bi bi-speedometer2" aria-hidden="true"></i> Optimise</button>
                                <button class="btn btn-outline-secondary" type="button"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Run integrity check</button>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <h2 class="erp-card-title">Repair</h2>
                            <span class="erp-chip erp-chip-outline">nothing to repair</span>
                        </header>
                        <div class="p-3">
                            <p class="text-body-secondary mb-2">Repair writes to table structure, so it stands on two things it will not assume: a check on file, and a table that check named. It acts on nothing else — a “quick fix” that touches a table nobody looked at is how data loss starts.</p>
                            <p class="erp-filter-note mb-0">Run an integrity check first. When it finds problems, they appear here with the tables that reported them, and repair becomes available for exactly those.</p>
                        </div>
                    </section>
                </div>
            </div>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Self-healing</h2>
                        <p class="erp-card-sub">The four safe operations above, in a fixed order, in one run. What is on this list is short on purpose — and the second list is why.</p>
                    </div>
                    <span class="erp-chip erp-chip-outline">last run 3 days ago</span>
                </header>
                <div class="p-3">
                    <div class="row row-cols-1 row-cols-xl-2 g-3">
                        <div class="col">
                            <p class="erp-filter-note mb-2">Never performed automatically, whatever a heal is asked to do:</p>
                            <table class="table erp-table mb-0">
                                <tbody>
                                    ${never.map((n) => `<tr><td class="erp-cell-strong">${n.label}</td><td class="erp-td-muted">${n.why}</td></tr>`).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div class="col">
                            <p class="erp-filter-note mb-2">What a heal will run, in order:</p>
                            <ol class="mb-3">
                                <li>Clear cache and compiled templates</li>
                                <li>Remove temporary files older than 24 hours</li>
                                <li>End other sessions</li>
                                <li>Rebuild the search index</li>
                            </ol>
                            <button class="btn btn-primary" type="button"><i class="bi bi-heart-pulse" aria-hidden="true"></i> Run safe self-healing</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Reset settings to their defaults</h2>
                        <p class="erp-card-sub">Every group goes back to the declared default. Business data is not touched: invoices, payments, stock, the ledger and the audit trail are exactly where they were.</p>
                    </div>
                    <span class="erp-chip erp-chip-warn">irreversible</span>
                </header>
                <div class="p-3">
                    <p class="text-body-secondary mb-2">Rows are deleted rather than rewritten with the default value. A row that says what the default already says is a decision nobody made — and it would hide the useful fact that this company has not chosen yet. The settings history and the audit trail are kept.</p>
                    <label class="erp-field-label">Type <code>RESET SETTINGS</code> to confirm</label>
                    <input class="form-control mb-2" type="text" placeholder="RESET SETTINGS">
                    <button class="btn btn-danger" type="button"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset settings</button>
                </div>
            </section>

            <div class="erp-table-shell mb-3" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Maintenance history<span class="erp-chip erp-chip-outline">4 recent run(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>When</th><th>Operation</th><th>Result</th><th>What it did</th><th class="erp-th-num">Freed</th><th>By</th></tr></thead>
                        <tbody>
                            ${history.map((h) => `
                            <tr>
                                <td class="erp-td-muted">${h.at}</td>
                                <td class="erp-cell-strong">${h.label}</td>
                                <td><span class="erp-chip ${h.status === 'ok' ? 'erp-chip-soft' : 'erp-chip-outline'}">${h.status}</span></td>
                                <td class="erp-td-muted">${h.what}</td>
                                <td class="erp-td-num">${h.freed}</td>
                                <td class="erp-td-muted">${h.by}</td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-xl-2 g-3">
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">Scheduled work (CLI)</h2></header>
                        <div class="p-3">
                            <p class="text-body-secondary mb-2">These run from cron, not from a browser: their absence shows up as work that never happened rather than as an error here, which is why the list is on this page.</p>
                            <pre class="erp-pre mb-0">php artisan erp:search:rebuild
php artisan erp:chain-verify
php artisan erp:expiry-alerts
php artisan erp:generate-bank-charges</pre>
                        </div>
                    </section>
                </div>
                <div class="col">
                    <section class="erp-card h-100">
                        <header class="erp-card-head"><h2 class="erp-card-title">What is not built yet</h2></header>
                        <div class="p-3">
                            <dl class="erp-dl erp-dl-tight mb-0">
                                <dt>Backup &amp; restore (§15-19)</dt>
                                <dd>Not implemented. Take a database dump with your own tooling, and keep the restore steps written down outside the application.</dd>
                                <dt>Image derivatives (§15-29)</dt>
                                <dd>This build creates a document's image derivatives at upload time and stores them beside it. There is no separate thumbnail cache to regenerate.</dd>
                            </dl>
                        </div>
                    </section>
                </div>
            </div>

        </div>
    </main>
</div>`;
}

/**
 * §15-07 — one settings group, opened: Bengali / Localization.
 *
 * The screen exists to show that the four switches are consumed rather than
 * decorative, so the page carries the group's real layout (tabs, switches,
 * branch note) *and* the sample strip the application renders from the same
 * service the invoices print through — lakh/crore grouping, বাংলা numerals
 * applied after grouping, and the amount in words taken from the figure the
 * totals were computed from.
 *
 * The four field rows carry the ids the catalogue deep-links to
 * (`#bengali_numerals`, `#amount_words_bn`, `#lakh_crore_format`), so the
 * sidebar's Bengali leaves land where they say they will.
 */
export function settingsLocalization() {
    const tabs = [
        'General', 'Bengali / Localization', 'Security', 'Notifications', 'Workflow',
        'Numbering', 'Audit', 'POS', 'Labels', 'Appearance',
    ];

    const switches = [
        {
            id: 'default_locale',
            label: 'Default language',
            control: `<select class="form-select" id="setting_default_locale"><option value="en">English</option><option value="bn" selected>বাংলা</option></select>`,
            help: 'What the interface and the documents are rendered in for everyone who has not chosen a language of their own.',
            scope: 'per branch',
        },
        {
            id: 'bengali_numerals',
            label: 'Show Bengali numerals in print',
            control: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_bengali_numerals" checked><label class="form-check-label" for="setting_bengali_numerals">০–৯ on documents</label></div>`,
            help: 'The glyphs change, the figures do not: grouping separators stay in the lakh-wise places they were already in.',
            scope: 'per branch',
        },
        {
            id: 'amount_words_bn',
            label: 'Amount in words in Bengali on documents',
            control: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_amount_words_bn" checked><label class="form-check-label" for="setting_amount_words_bn">print the words line</label></div>`,
            help: 'Taken from the same number the totals were computed from — never from a formatted string that may already have lost a paisa.',
            scope: 'per branch',
        },
        {
            id: 'lakh_crore_format',
            label: 'Use Lakh / Crore grouping (৳1,25,000)',
            control: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_lakh_crore_format" checked><label class="form-check-label" for="setting_lakh_crore_format">12,34,567 — not 1,234,567</label></div>`,
            help: 'The subcontinent reads its own grouping. A company that trades the other way can turn it off and still keep the Bengali digits.',
            scope: 'per branch',
        },
    ];

    return `
${previewBar('settings-localization.html')}
<div class="erp-shell">
    ${sidebar('configure')}
    <main class="erp-main">
        ${topbar('Bengali / Localization Settings')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-translate" aria-hidden="true"></i> Settings</p>
                    <h1 class="erp-h1">Bengali / Localization Settings</h1>
                    <p class="erp-page-sub">Language toggle, Bengali numerals and amount-in-words behaviour.</p>
                </div>
            </header>

            <ul class="nav erp-settings-tabs mb-3">
                ${tabs.map((label) => `<li class="nav-item"><a class="nav-link${label === 'Bengali / Localization' ? ' active' : ''}" href="./settings-localization.html">${label}</a></li>`).join('')}
            </ul>

            <form method="POST" action="#" onsubmit="return false">
                <section class="erp-card erp-card-max">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Bengali / Localization Settings</h2>
                            <p class="erp-card-sub">
                                Scope: <strong>per branch may differ</strong>
                                · this group needs <span class="font-monospace">settings.localization</span> to read it,
                                <span class="font-monospace">settings.update</span> to change it.
                            </p>
                        </div>
                        <span class="erp-chip erp-chip-soft">stored in <code>settings</code> table</span>
                    </header>

                    <div class="row g-3">
                        ${switches.map((field) => `
                            <div id="${field.id}" class="col-12">
                                <label class="form-label" for="setting_${field.id}">${field.label}</label>
                                ${field.control}
                                <div class="form-text">${field.help}</div>
                            </div>`).join('')}
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Save settings</button>
                        <a class="btn btn-outline-secondary" href="./settings-localization.html">Reset changes</a>
                    </div>
                </section>
            </form>

            <section class="erp-card erp-card-max mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">What these switches change</h2>
                        <p class="erp-card-sub">Rendered by the service the invoices and the POS receipt render through — save, and the documents change with this sample.</p>
                    </div>
                    <span class="erp-chip erp-chip-soft">বাংলা</span>
                </header>
                <dl class="erp-dl erp-dl-tight">
                    <dt>Figures</dt>
                    <dd class="font-monospace">১২,৩৪,৫৬৭.৫০</dd>
                    <dt>Quantity</dt>
                    <dd class="font-monospace">১২.৫</dd>
                    <dt>Amount in words</dt>
                    <dd>Taka twelve lakh thirty-four thousand five hundred and sixty-seven and fifty paisa only</dd>
                    <dt>বাংলায়</dt>
                    <dd>টাকা বারো লাখ চৌত্রিশ হাজার পাঁচশ সাতষট্টি এবং পঞ্চাশ পয়সা মাত্র</dd>
                </dl>
            </section>

            <section class="erp-card erp-card-max mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Branches that differ</h2>
                        <p class="erp-card-sub">An outlet may set its own value for this group. The company value stays as it is everywhere else — and a branch can be put back on the company's value at any time.</p>
                    </div>
                    <div class="erp-card-actions">
                        <a class="erp-chip erp-chip-outline" href="./settings-branch.html"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch settings</a>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr><th>Branch</th><th>Keys set there</th><th>Company value it replaces</th><th>Changed</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="erp-cell-strong">Dhanmondi outlet</span><div class="erp-td-muted">DHK2</div></td>
                                <td class="erp-td-muted font-monospace">bengali_numerals, amount_words_bn</td>
                                <td class="erp-td-muted">off → printed in বাংলা numerals and words</td>
                                <td class="erp-td-muted">2026-10-06 09:14</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="./settings-branch.html">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                            <tr>
                                <td><span class="erp-cell-strong">Head office</span><div class="erp-td-muted">MAIN</div></td>
                                <td class="erp-td-muted">— follows the company value</td>
                                <td class="erp-td-muted">12,34,567.50 in western digits</td>
                                <td class="erp-td-muted">—</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="./settings-branch.html">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/**
 * §15-14 — one settings group, opened: VAT & tax.
 *
 * The first settings screen whose values change money, so the page carries the
 * arithmetic beside the switches: what an exclusive ৳115 becomes, what the same
 * price becomes when the VAT is already inside it, where a paisa lands under
 * each rounding mode, and what the nearest taka costs. Below that, the group's
 * branch scope and the guard that refuses a default code naming no active rate.
 */
export function settingsTax() {
    const tabs = [
        'General', 'Bengali / Localization', 'Security', 'Notifications', 'Workflow',
        'Numbering', 'VAT & Tax', 'Audit', 'POS', 'Labels', 'Appearance',
    ];

    const switches = [
        {
            label: 'My prices already include VAT',
            control: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_prices_include_tax"><label class="form-check-label" for="setting_prices_include_tax">the customer pays the price on the shelf</label></div>`,
            help: 'Retail counters in Bangladesh usually quote a price the customer pays. With this on, the tax is taken out of that figure instead of added to it: a ৳115 item at 15% is ৳100 taxable + ৳15 VAT, and the customer still pays ৳115.',
        },
        {
            label: 'Default tax code',
            control: `<input class="form-control" type="text" id="setting_default_code" value="VAT15">`,
            help: 'Used when a taxable sale names no rate of its own. Leave empty and an untagged sale carries no tax — which is a decision, so the screen says so. A code that names no active rate is refused.',
        },
        {
            label: 'Round VAT',
            control: `<select class="form-select" id="setting_rounding_mode"><option value="document" selected>Once, on the document total</option><option value="line">On every line, then added up</option></select>`,
            help: 'The two differ by a paisa or two on an order with fractions in it. Whichever you choose, the figure stored on the invoice is the figure that was printed.',
        },
        {
            label: 'Round the grand total to the nearest taka',
            control: `<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="setting_round_to_nearest_taka"><label class="form-check-label" for="setting_round_to_nearest_taka">counter-friendly totals</label></div>`,
            help: 'Convenient at a counter and awkward in a ledger: the difference is recorded in the invoice’s own rounding column, so the books still add up.',
        },
        {
            label: 'Mushak 9.1 form revision',
            control: `<input class="form-control" type="text" id="setting_mushak_form_revision" placeholder="printed only if you declare one">`,
            help: 'Printed on the statutory tax invoice beside the form code, when your form has a revision marker. Left empty, the form prints no revision rather than one this system made up.',
        },
    ];

    return `
${previewBar('settings-tax.html')}
<div class="erp-shell">
    ${sidebar('configure')}
    <main class="erp-main">
        ${topbar('VAT & Tax Settings')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-percent" aria-hidden="true"></i> Settings</p>
                    <h1 class="erp-h1">VAT &amp; Tax Settings</h1>
                    <p class="erp-page-sub">Whether your prices already include VAT, how tax is rounded, which rate an untagged sale uses, and the form revision printed on the statutory tax invoice.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./settings.html"><i class="bi bi-sliders" aria-hidden="true"></i> Settings desk</a>
                </div>
            </header>

            <ul class="nav erp-settings-tabs mb-3">
                ${tabs.map((label) => `<li class="nav-item"><a class="nav-link${label === 'VAT & Tax' ? ' active' : ''}" href="./settings-tax.html">${label}</a></li>`).join('')}
            </ul>

            <form method="POST" action="#" onsubmit="return false">
                <section class="erp-card erp-card-max">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">VAT &amp; Tax Settings</h2>
                            <p class="erp-card-sub">
                                Scope: <strong>per branch may differ</strong>
                                · this group needs <span class="font-monospace">tax.manage</span> to read it,
                                <span class="font-monospace">settings.update</span> to change it.
                            </p>
                        </div>
                        <span class="erp-chip erp-chip-soft">stored in <code>settings</code> table</span>
                    </header>

                    <div class="row g-3">
                        ${switches.map((f) => `
                            <div class="col-12">
                                <label class="form-label">${f.label}</label>
                                ${f.control}
                                <div class="form-text">${f.help}</div>
                            </div>`).join('')}
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Save settings</button>
                        <a class="btn btn-outline-secondary" href="./settings-tax.html">Reset changes</a>
                    </div>
                </section>
            </form>

            <section class="erp-card erp-card-max mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">What these switches change</h2>
                        <p class="erp-card-sub">Computed by the pricing engine the quotations, orders, POS sales and invoices all run through — the figures below are its output, not an illustration of it.</p>
                    </div>
                    <span class="erp-chip erp-chip-soft">15% · ৳115</span>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr><th>Policy</th><th class="erp-th-num">Taxable value</th><th class="erp-th-num">VAT</th><th class="erp-th-num">Customer pays</th><th>Printed as</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="erp-cell-strong">Exclusive</span><div class="erp-td-muted">the shipped default</div></td>
                                <td class="erp-td-num">৳115.00</td>
                                <td class="erp-td-num">৳17.25</td>
                                <td class="erp-td-num">৳132.25</td>
                                <td class="erp-td-muted">Subtotal, then Tax</td>
                            </tr>
                            <tr>
                                <td><span class="erp-cell-strong">Inclusive</span><div class="erp-td-muted">the price on the shelf</div></td>
                                <td class="erp-td-num">৳100.00</td>
                                <td class="erp-td-num">৳15.00</td>
                                <td class="erp-td-num">৳115.00</td>
                                <td class="erp-td-muted">Taxable value, VAT (included in the prices)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <dl class="erp-dl erp-dl-tight">
                    <dt>Rounding, once on the document</dt>
                    <dd class="font-monospace">three lines of ৳1.05 at 15% → 0.1575 × 3 → ৳0.4725 stored as the document's tax</dd>
                    <dt>Rounding, on every line</dt>
                    <dd class="font-monospace">0.1575 → ৳0.16 each → ৳0.48 on the same three lines</dd>
                    <dt>Nearest taka</dt>
                    <dd class="font-monospace">৳1419.79 → ৳1420.00, and the ৳0.21 difference lands in the invoice's rounding column</dd>
                </dl>
                <div class="p-3 pt-0">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        A default tax code that names no <strong>active</strong> rate of this company is refused when it is saved,
                        and the attempt is written to the audit chain — a typo here would make every untagged sale tax-free.
                    </p>
                </div>
            </section>

            <section class="erp-card erp-card-max mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Branches that differ</h2>
                        <p class="erp-card-sub">An outlet may price the way it sells while head office prices the way it invoices. The company value stays as it is everywhere else.</p>
                    </div>
                    <div class="erp-card-actions">
                        <a class="erp-chip erp-chip-outline" href="./settings-branch.html"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch settings</a>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr><th>Branch</th><th>Keys set there</th><th>Company value it replaces</th><th>Changed</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="erp-cell-strong">Dhanmondi outlet</span><div class="erp-td-muted">DHK2</div></td>
                                <td class="erp-td-muted font-monospace">prices_include_tax, round_to_nearest_taka</td>
                                <td class="erp-td-muted">exclusive, exact to the paisa → the counter's way</td>
                                <td class="erp-td-muted">2026-10-08 09:40</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="./settings-branch.html">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                            <tr>
                                <td><span class="erp-cell-strong">Head office</span><div class="erp-td-muted">MAIN</div></td>
                                <td class="erp-td-muted">— follows the company value</td>
                                <td class="erp-td-muted">exclusive pricing, 15% added on top</td>
                                <td class="erp-td-muted">—</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="./settings-branch.html">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>
    </main>
</div>`;
}

/* --------------------------------------------------- 12-12. the notice board */

export function noticeBoard() {
    const board = [
        {
            title: 'Safety drill on the 24th — counter staff',
            category: 'Policy',
            tone: 'erp-chip-warn',
            published: '07 Oct 2026, 09:20',
            by: 'Instance Owner',
            audience: 'Everybody in the company (12)',
            waiting: true,
            body: 'The fire drill runs at 10:00 and takes about twenty minutes. Please read the evacuation route for your floor and confirm that you have read this notice — the register has to show the office was told.',
        },
        {
            title: 'Eid bonus paid with the October salary',
            category: 'Finance',
            tone: 'erp-chip-soft',
            published: '02 Oct 2026, 16:05',
            by: 'Head of Accounts',
            audience: 'Everybody in the company (12)',
            waiting: false,
            body: 'The bonus has been processed with this month’s payroll. Payslips are in the employee portal; the figure is the one on your own payslip, not a company-wide number.',
        },
        {
            title: 'Narayanganj outlet opens at 11:00 on Fridays',
            category: 'General',
            tone: 'erp-chip-outline',
            published: '28 Sep 2026, 11:40',
            by: 'Head of Retail',
            audience: 'People of 1 branch (4)',
            waiting: false,
            body: 'From next week the outlet opens an hour later on Fridays and closes at the same time. The change does not affect head office or the Dhanmondi counter.',
        },
    ];

    const ledger = [
        { name: 'Instance Owner', at: '07 Oct 2026, 09:22', done: true },
        { name: 'Head of Accounts', at: '07 Oct 2026, 09:31', done: true },
        { name: 'Counter Manager, Dhanmondi', at: '07 Oct 2026, 10:02', done: true },
        { name: 'Store Keeper', at: '07 Oct 2026, 10:14', done: true },
        { name: 'Narayanganj Supervisor', at: '07 Oct 2026, 11:20', done: true },
        { name: 'Accounts Assistant', at: null, done: false },
        { name: 'Warehouse Helper', at: null, done: false },
    ];

    return `
${previewBar('notices.html')}
<div class="erp-shell">
    ${sidebar('govern')}
    <main class="erp-main">
        ${topbar({ title: 'Notice board', trail: [{ label: 'Business management' }, { label: 'Notice board' }] })}
        <div class="erp-content">

            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Business Management · Notice board</p>
                    <h1 class="erp-h1">What the company has told everybody</h1>
                    <p class="erp-page-sub">A notice is addressed to an audience — everybody, the people holding certain roles, the people of certain branches, or named people — and that audience is written down when it is published, not guessed at later. A notice that asks for acknowledgement keeps a ledger of who has read it, and a row in that ledger survives a refresh, a new login and somebody emptying their inbox.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./notices.html"><i class="bi bi-card-list" aria-hidden="true"></i> Register</a>
                    <a class="btn btn-outline-secondary" href="#acknowledgements"><i class="bi bi-clipboard2-check" aria-hidden="true"></i> Acknowledgement tracking</a>
                    <a class="btn btn-primary" href="#new"><i class="bi bi-megaphone" aria-hidden="true"></i> New notice</a>
                </div>
            </header>

            <div class="erp-kpi-grid">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-pen" aria-hidden="true"></i> Waiting for you</p>
                    <p class="erp-kpi-value">1 notice</p>
                    <p class="erp-kpi-foot">Notices that ask for an acknowledgement and do not have yours yet</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-megaphone" aria-hidden="true"></i> Live notices</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Published, addressed to you, and not expired</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-tags" aria-hidden="true"></i> Categories</p>
                    <p class="erp-kpi-value">5</p>
                    <p class="erp-kpi-foot">General, policy, urgent, people and finance</p>
                </div>
            </div>

            <form class="erp-filterbar" method="GET" action="#">
                <div class="erp-filter">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select" name="category" id="category">
                        <option value="">Everything</option>
                        <option>General</option><option>Policy</option><option>Urgent</option><option>People &amp; HR</option><option>Finance</option>
                    </select>
                </div>
                <div class="erp-filterbar-actions">
                    <button class="btn btn-outline-secondary" type="button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                </div>
            </form>

            ${board.map((notice) => `
            <article class="erp-card mt-3">
                <div class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title"><a href="#notice">${notice.title}</a></h2>
                        <p class="erp-card-sub">
                            <span class="erp-chip ${notice.tone}">${notice.category}</span>
                            published ${notice.published} · by ${notice.by} · ${notice.audience}
                        </p>
                    </div>
                    <div class="erp-card-actions">
                        ${notice.waiting
                            ? '<span class="erp-chip erp-chip-warn"><i class="bi bi-pen" aria-hidden="true"></i> Waiting for you</span>'
                            : '<span class="erp-chip erp-chip-ok"><i class="bi bi-check2" aria-hidden="true"></i> Acknowledged</span>'}
                    </div>
                </div>
                <div class="p-3 pt-0">
                    <p class="mb-2">${notice.body}</p>
                    ${notice.waiting ? `
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label" for="note">Note (optional)</label>
                                <input class="form-control" type="text" id="note" placeholder="anything you want on the record beside your name">
                            </div>
                            <div class="col-md-6">
                                <button class="btn btn-primary" type="button"><i class="bi bi-pen" aria-hidden="true"></i> I have read this</button>
                            </div>
                        </div>` : `
                        <a class="btn btn-outline-secondary btn-sm" href="#notice">Read it <i class="bi bi-arrow-right" aria-hidden="true"></i></a>`}
                </div>
            </article>`).join('')}

            <section class="erp-card mt-3" id="acknowledgements">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Who has not read it yet</h2>
                        <p class="erp-card-sub">Safety drill on the 24th — 5 of 7 acknowledged · 2 still to read it. A name here is a name, not a count of emails that were sent.</p>
                    </div>
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-soft">71.4%</span></div>
                </header>
                <div class="p-3 pt-0">
                    <div class="erp-progress mb-3"><span style="width: 71.4%"></span></div>
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead><tr><th>Person</th><th>When</th></tr></thead>
                            <tbody>
                                ${ledger.map((row) => `
                                <tr>
                                    <td>${row.name}</td>
                                    <td class="erp-td-muted">${row.done ? row.at : '<span class="erp-chip erp-chip-warn">waiting</span>'}</td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                    <p class="erp-filter-note mt-3 mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        The audience is enforced on the notice’s own page too: a reader outside it gets a 403 by typing the address,
                        and a draft is a 404 for anybody who may not publish. A notice without “acknowledge” switched on records nothing,
                        and says so rather than pretending.
                    </p>
                </div>
            </section>

        </div>
        ${footer()}
    </main>
</div>`;
}

/* --------------------------------------------------- 12-13. tasks & projects */

export function taskBoard() {
    const columns = [
        {
            status: 'todo', label: 'To do',
            cards: [
                { title: 'Count aisle four before Friday', who: 'Warehouse Helper', project: 'Annual stock take', priority: 'high', due: '23 Oct', overdue: false, moves: ['In progress'] },
                { title: 'Renew the Narayanganj trade licence', who: 'Counter Manager, Dhanmondi', project: null, priority: 'normal', due: '30 Oct', overdue: false, moves: ['In progress'] },
            ],
        },
        {
            status: 'in_progress', label: 'In progress',
            cards: [
                { title: 'Reconcile the bank statement to 30 September', who: 'Head of Accounts', project: null, priority: 'urgent', due: '19 Oct', overdue: true, moves: ['In review', 'Blocked'] },
                { title: 'Write the October promotion on the Dhanmondi counter', who: 'Instance Owner', project: 'Winter promotion', priority: 'high', due: '21 Oct', overdue: false, moves: ['In review', 'Blocked'] },
            ],
        },
        {
            status: 'blocked', label: 'Blocked',
            cards: [
                { title: 'Migrate the old supplier ledger', who: 'Accounts Assistant', project: null, priority: 'normal', due: '28 Oct', overdue: false, moves: ['In progress'] },
            ],
        },
        {
            status: 'review', label: 'In review',
            cards: [
                { title: 'Sign off the packaging cost sheet', who: 'Store Keeper', project: 'Packaging audit', priority: 'normal', due: '20 Oct', overdue: false, moves: ['In progress', 'Done'] },
            ],
        },
        {
            status: 'done', label: 'Done',
            cards: [
                { title: 'Close the September cash book', who: 'Head of Accounts', project: null, priority: 'high', due: '05 Oct', overdue: false, moves: ['In progress'] },
            ],
        },
    ];

    const mine = [
        { title: 'Reconcile the bank statement to 30 September', project: '—', priority: 'Urgent', tone: 'erp-chip-danger', due: '19 Oct 2026, 17:00', overdue: true, status: 'in_progress', statusLabel: 'In progress' },
        { title: 'Write the October promotion on the Dhanmondi counter', project: 'Winter promotion', priority: 'High', tone: 'erp-chip-warn', due: '21 Oct 2026, 12:00', overdue: false, status: 'in_progress', statusLabel: 'In progress' },
        { title: 'Approve the store keeper’s leave request', project: '—', priority: 'Normal', tone: 'erp-chip-soft', due: '24 Oct 2026, 10:00', overdue: false, status: 'todo', statusLabel: 'To do' },
    ];

    return `
${previewBar('tasks.html')}
<div class="erp-shell">
    ${sidebar('govern')}
    <main class="erp-main">
        ${topbar({ title: 'Tasks & projects', trail: [{ label: 'Business management' }, { label: 'Tasks & projects' }] })}
        <div class="erp-content">

            <header class="erp-page-head">
                <div class="erp-page-head-main">
                    <p class="erp-eyebrow">Business Management · Tasks</p>
                    <h1 class="erp-h1">The board</h1>
                    <p class="erp-page-sub">One column per state, drawn from the task state machine itself — todo, in progress, blocked, in review, done — so a column cannot appear that no task may be in. A card only offers the moves that state allows: done can be reopened, cancelled cannot. Overdue is computed from the clock, so a task that became late a minute ago is already late.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="#mine"><i class="bi bi-list-task" aria-hidden="true"></i> My tasks</a>
                    <a class="btn btn-outline-secondary" href="./tasks.html"><i class="bi bi-people" aria-hidden="true"></i> Everybody’s</a>
                    <a class="btn btn-primary" href="#new"><i class="bi bi-plus-lg" aria-hidden="true"></i> New task</a>
                </div>
            </header>

            <div class="erp-kpi-grid">
                <div class="erp-kpi"><p class="erp-kpi-label"><i class="bi bi-list-task" aria-hidden="true"></i> Open, mine</p><p class="erp-kpi-value">3</p><p class="erp-kpi-foot">Everything assigned to you that is not done or cancelled</p></div>
                <div class="erp-kpi"><p class="erp-kpi-label"><i class="bi bi-alarm" aria-hidden="true"></i> Overdue, mine</p><p class="erp-kpi-value">1</p><p class="erp-kpi-foot">Past the due date and still open</p></div>
                <div class="erp-kpi"><p class="erp-kpi-label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Completed this week</p><p class="erp-kpi-value">4</p><p class="erp-kpi-foot">Company-wide, marked done since Monday</p></div>
                <div class="erp-kpi"><p class="erp-kpi-label"><i class="bi bi-people" aria-hidden="true"></i> Open, everybody</p><p class="erp-kpi-value">9</p><p class="erp-kpi-foot">Across the company, including yours</p></div>
            </div>

            <p class="erp-filter-note">
                <i class="bi bi-eye" aria-hidden="true"></i>
                A person with only <span class="font-monospace">tasks.view_own</span> sees this board scoped to their own cards —
                the query never returns the rest. Seeing everybody’s work needs <span class="font-monospace">tasks.view_all</span>.
            </p>

            <div class="row g-3">
                ${columns.map((column) => `
                <div class="col-xl col-lg-4 col-md-6">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">${column.label}</h2>
                                <p class="erp-card-sub">${column.cards.length} card(s)</p>
                            </div>
                            <div class="erp-card-actions">${statusChip(column.status, column.label)}</div>
                        </header>
                        <div class="p-2">
                            ${column.cards.map((card) => `
                            <article class="erp-list-row erp-list-row-top">
                                <div class="erp-list-row-main">
                                    <a class="erp-cell-strong" href="#task">${card.title}</a>
                                    <div class="erp-td-muted">${card.who}${card.project ? ' · ' + card.project : ''}</div>
                                    <div class="mt-1 d-flex gap-1 flex-wrap">
                                        <span class="erp-chip ${card.priority === 'urgent' ? 'erp-chip-danger' : (card.priority === 'high' ? 'erp-chip-warn' : 'erp-chip-soft')}">${card.priority}</span>
                                        <span class="erp-chip ${card.overdue ? 'erp-chip-danger' : 'erp-chip-outline'}">${card.due}${card.overdue ? ' · overdue' : ''}</span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    ${card.moves.map(() => `<button class="btn btn-outline-secondary btn-sm" type="button" title="Move to the next state"><i class="bi bi-arrow-right" aria-hidden="true"></i></button>`).join(' ')}
                                </div>
                            </article>`).join('')}
                        </div>
                    </section>
                </div>`).join('')}
            </div>

            <section class="erp-card mt-3" id="mine">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">My tasks, in the order it hurts</h2>
                        <p class="erp-card-sub">Overdue first, then by priority, then by the soonest due date — sorted when the page is read.</p>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Task</th><th>Project</th><th>Priority</th><th>Due</th><th>State</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${mine.map((task) => `
                            <tr>
                                <td><a class="erp-cell-strong" href="#task">${task.title}</a></td>
                                <td class="erp-td-muted">${task.project}</td>
                                <td><span class="erp-chip ${task.tone}">${task.priority}</span></td>
                                <td class="erp-td-muted">${task.due}${task.overdue ? '<div><span class="erp-chip erp-chip-danger">overdue</span></div>' : ''}</td>
                                <td>${statusChip(task.status, task.statusLabel)}</td>
                                <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="#task">Open</a></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="p-3">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i>
                        Every move, assignment, due-date change and comment writes a row on the task’s own timeline <em>and</em> an audit
                        event; only the assignee, the creator, or somebody with <span class="font-monospace">tasks.manage</span> may move it.
                    </p>
                </div>
            </section>

        </div>
        ${footer()}
    </main>
</div>`;
}

/* ------------------------------------- 12-03/04/09/10. the company registers */

export function registerDesk() {
    const shelves = [
        {
            group: 'Company identity',
            note: 'What the company is: its licence to trade, its tax numbers, the certificates that say it exists.',
            kinds: [
                { label: 'Trade licences', icon: 'bi-patch-check', count: 4, hint: 'A licence is only real while it is unexpired; renewals are logged against the record.' },
                { label: 'TIN &amp; BIN registrations', icon: 'bi-upc-scan', count: 2, hint: 'TIN and BIN do not expire — what matters is that a changed number is a recorded event.' },
                { label: 'Company certificates', icon: 'bi-award', count: 3, hint: 'Incorporation, commencement, share allotment: the number, the issuer, the term.' },
            ],
        },
        {
            group: 'Compliance',
            note: 'What the company owes somebody by a date: filings with the registrar, statutory duties, insurance cover.',
            kinds: [
                { label: 'Insurance policies', icon: 'bi-shield-check', count: 4, hint: 'Fire, stock, vehicle and employee cover: the policy number, the sum insured, the day it stops.' },
                { label: 'RJSC filings', icon: 'bi-building-gear', count: 5, hint: 'Returns with the registrar; completing one rolls the next due date forward.' },
                { label: 'Statutory obligations', icon: 'bi-calendar-check', count: 6, hint: 'VAT returns, TDS deposits, labour-law duties — each with its own cadence.' },
            ],
        },
        {
            group: 'Documents &amp; papers',
            note: 'What the company has signed and what it prints: contracts, agreements, the vault, the brand.',
            kinds: [
                { label: 'Contracts', icon: 'bi-file-earmark-text', count: 7, hint: 'A counterparty, a value and an end date. The register notices before the other side does.' },
                { label: 'Agreements', icon: 'bi-file-earmark-check', count: 5, hint: 'Distribution, tenancy and service terms that are not orders.' },
                { label: 'Brand assets', icon: 'bi-palette', count: 3, hint: 'The master logo, the seal, signboard artwork — which version is current and who keeps it.' },
            ],
        },
    ];

    const rows = [
        {
            title: 'Trade licence — Dhanmondi counter',
            kind: 'Trade licence', reference: 'TRAD/DHN/2026/118',
            party: 'Dhaka South City Corporation', value: '৳ 6,000.00',
            tracked: '30 Nov 2026', days: '53 days left', state: 'valid', stateLabel: 'In force',
            branch: 'Dhanmondi counter',
        },
        {
            title: 'Fire safety licence — head office',
            kind: 'Trade licence', reference: 'FSC/2025/4471',
            party: 'Fire Service &amp; Civil Defence', value: '৳ 3,500.00',
            tracked: '02 Oct 2026', days: '6 days ago', state: 'expired', stateLabel: 'Expired',
            branch: 'Head office',
        },
        {
            title: 'Stock insurance policy 2026-27',
            kind: 'Insurance policy', reference: 'GD-STK-88 21 447',
            party: 'Green Delta Insurance', value: '৳ 42,00,000.00',
            tracked: '18 Oct 2026', days: '10 days left', state: 'expiring', stateLabel: 'Expiring',
            branch: 'Company-wide',
        },
        {
            title: 'Supply agreement — Meghna Traders',
            kind: 'Contract', reference: 'CT/2027/01',
            party: 'Meghna Traders', value: '৳ 2,50,000.00',
            tracked: '31 Mar 2027', days: '174 days left', state: 'valid', stateLabel: 'In force',
            branch: 'Company-wide',
        },
        {
            title: 'Monthly VAT return (Mushak 9.1)',
            kind: 'Statutory obligation', reference: '—',
            party: 'National Board of Revenue', value: '—',
            tracked: '14 Oct 2026', days: '6 days left', state: 'due_soon', stateLabel: 'Due soon',
            branch: 'Company-wide',
        },
        {
            title: 'Annual return to the registrar',
            kind: 'RJSC filing', reference: 'RJSC/AR/2026',
            party: 'RJSC', value: '—',
            tracked: '05 Oct 2026', days: '3 days late', state: 'overdue', stateLabel: 'Overdue',
            branch: 'Company-wide',
        },
        {
            title: 'TIN certificate',
            kind: 'TIN / BIN', reference: 'TIN 452 118 907',
            party: 'National Board of Revenue', value: '—',
            tracked: '—', days: 'no term', state: 'undated', stateLabel: 'No date on file',
            branch: 'Company-wide',
        },
        {
            title: 'Certificate of incorporation',
            kind: 'Company certificate', reference: 'C-88214/2019',
            party: 'RJSC', value: '—',
            tracked: '—', days: 'no term', state: 'undated', stateLabel: 'No date on file',
            branch: 'Company-wide',
        },
    ];

    const history = [
        { action: 'Renewed', tone: 'erp-chip-soft', note: 'Renewed to 30 Nov 2026 (was 30 Nov 2025).', said: 'Paid at the counter, receipt 4412', when: '12 Nov 2025', who: 'Head of Accounts' },
        { action: 'File attached', tone: 'erp-chip-soft', note: 'Filed “2026 renewal scan”.', said: null, when: '12 Nov 2025', who: 'Counter Manager' },
        { action: 'Created', tone: 'erp-chip-outline', note: 'Trade licence recorded under TRAD/DHN/2024/087.', said: null, when: '04 Nov 2024', who: 'Head of Accounts' },
    ];

    return `
${previewBar('records.html')}
<div class="erp-shell">
    ${sidebar('govern')}
    <main class="erp-main">
        ${topbar('Business records')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-journal-text" aria-hidden="true"></i> Business Management · Registers</p>
                    <h1 class="erp-h1">What the company is, and the day each of it runs out</h1>
                    <p class="erp-page-sub">A licence, a policy, a contract and a filing are the same animal: somebody issued it, it carries a number, and it stops being true on a date. One register for all of them is what makes “what runs out this quarter?” a question with an answer. States are read from the clock — nothing here waits for a nightly job to be true.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./compliance.html"><i class="bi bi-hourglass-split" aria-hidden="true"></i> What is running out</a>
                    <a class="btn btn-outline-secondary" href="./compliance.html#calendar"><i class="bi bi-calendar-event" aria-hidden="true"></i> Compliance calendar</a>
                    <a class="btn btn-outline-secondary" href="./compliance.html#obligations"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recurring duties</a>
                </div>
            </header>

            <div class="erp-kpi-grid">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-journal-text" aria-hidden="true"></i> Live records</p>
                    <p class="erp-kpi-value">39</p>
                    <p class="erp-kpi-foot">Everything on the registers that has not been retired</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Expiring within 30 days</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Renew these before they lapse — the month is where renewing is cheap</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Already expired</p>
                    <p class="erp-kpi-value">1</p>
                    <p class="erp-kpi-foot">A lapsed licence is a decision somebody has to take, not a paperwork problem</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calendar-check" aria-hidden="true"></i> Due within 30 days</p>
                    <p class="erp-kpi-value">2</p>
                    <p class="erp-kpi-foot">Filings and duties with a deadline this month</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-alarm" aria-hidden="true"></i> Overdue</p>
                    <p class="erp-kpi-value">1</p>
                    <p class="erp-kpi-foot">Past the deadline and not filed</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-question-circle" aria-hidden="true"></i> No date on file</p>
                    <p class="erp-kpi-value">5</p>
                    <p class="erp-kpi-foot">Registrations and assets with no term — worth a look, not a panic</p>
                </div>
            </div>

            <div class="row g-3 mb-3">
                ${shelves.map((shelf) => `
                <div class="col-lg-4">
                    <section class="erp-card h-100">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">${shelf.group}</h2>
                                <p class="erp-card-sub">${shelf.note}</p>
                            </div>
                        </header>
                        <div class="px-3 pb-2">
                            ${shelf.kinds.map((kind) => `
                            <div class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <span class="erp-cell-strong"><i class="bi ${kind.icon} me-1" aria-hidden="true"></i>${kind.label}</span>
                                    <div class="erp-td-muted">${kind.hint}</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="erp-chip erp-chip-soft">${kind.count} live</span>
                                    <a class="btn btn-sm btn-outline-secondary" href="#register">Open</a>
                                </div>
                            </div>`).join('')}
                        </div>
                    </section>
                </div>`).join('')}
            </div>

            <form class="erp-filterbar" method="GET" action="#">
                <div class="erp-filter">
                    <label class="form-label" for="kind">Kind</label>
                    <select class="form-select" name="kind" id="kind">
                        <option value="">Every kind</option>
                        <option>Trade licences</option>
                        <option>TIN &amp; BIN registrations</option>
                        <option>Company certificates</option>
                        <option>Contracts</option>
                        <option>Agreements</option>
                        <option>Brand assets</option>
                        <option>Insurance policies</option>
                        <option>RJSC filings</option>
                        <option>Statutory obligations</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="state">State</label>
                    <select class="form-select" name="state" id="state">
                        <option value="">Live only</option>
                        <option>Expired</option>
                        <option>Expiring</option>
                        <option>Due soon</option>
                        <option>Overdue</option>
                        <option>No date on file</option>
                        <option>Retired</option>
                    </select>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="branch">Branch</label>
                    <select class="form-select" name="branch" id="branch">
                        <option value="">Every branch</option>
                        <option>Company-wide only</option>
                        <option>Head office</option>
                        <option>Dhanmondi counter</option>
                        <option>Narayanganj outlet</option>
                    </select>
                </div>
                <div class="erp-filter erp-filter-wide">
                    <label class="form-label" for="q">Search</label>
                    <input class="form-control" type="search" name="q" id="q" placeholder="Title, number, issuer or counterparty">
                </div>
                <div class="erp-filterbar-actions">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                    <a class="btn btn-link" href="#">Reset</a>
                </div>
            </form>

            <section class="erp-table-shell" id="register" data-erp-table>
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">The register <span class="erp-chip erp-chip-outline">39 record(s)</span></h2>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead>
                            <tr>
                                <th>Record</th>
                                <th>Kind</th>
                                <th>Issued by / other party</th>
                                <th>Value</th>
                                <th>Tracked date</th>
                                <th>State</th>
                                <th>Branch</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map((row) => `
                            <tr>
                                <td>
                                    <a class="erp-cell-strong" href="#detail">${row.title}</a>
                                    ${row.reference !== '—' ? `<div class="erp-td-muted">${row.reference}</div>` : ''}
                                </td>
                                <td class="erp-td-muted">${row.kind}</td>
                                <td class="erp-td-muted">${row.party}</td>
                                <td class="erp-td-num">${row.value}</td>
                                <td class="erp-td-muted">
                                    ${row.tracked}
                                    <div class="erp-td-muted">${row.days}</div>
                                </td>
                                <td>${statusChip(row.state, row.stateLabel)}</td>
                                <td class="erp-td-muted">${row.branch}</td>
                                <td class="erp-td-actions"><a class="btn btn-sm btn-outline-secondary" href="#detail">Open</a></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="erp-note erp-note-warn mt-3">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <div>
                    <div class="mb-1">${statusChip('expiring', 'Expiring')}</div>
                    <div>Runs out on 18 Oct 2026 — 10 day(s) left. Renew it while the register still has time to be useful.</div>
                </div>
            </div>

            <div class="erp-split mt-3" id="detail">
                <div class="erp-split-main">
                    <section class="erp-card">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">Stock insurance policy 2026-27</h2>
                                <p class="erp-card-sub">GD-STK-88 21 447 · Green Delta Insurance</p>
                            </div>
                        </header>
                        <div class="px-3 pb-3">
                            <dl class="erp-dl erp-dl-tight">
                                <dt>Kind</dt><dd>Insurance policy</dd>
                                <dt>Policy number</dt><dd>GD-STK-88 21 447</dd>
                                <dt>Insurer</dt><dd>Green Delta Insurance</dd>
                                <dt>Sum insured</dt><dd>৳ 42,00,000.00</dd>
                                <dt>Issued on</dt><dd>18 Oct 2025</dd>
                                <dt>Runs from</dt><dd>18 Oct 2025</dd>
                                <dt>Expires on</dt><dd>18 Oct 2026</dd>
                                <dt>Branch</dt><dd>Company-wide</dd>
                                <dt>Recorded by</dt><dd>Head of Accounts on 20 Oct 2025</dd>
                                <dt>Notes</dt><dd>Cover note on file; the endorsement for the Narayanganj store is attached separately.</dd>
                            </dl>
                        </div>
                    </section>

                    <section class="erp-card mt-3">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">Papers filed against this record</h2>
                                <p class="erp-card-sub">The file itself lives in the document library — sniffed, checksummed and audited. This is what it is filed under.</p>
                            </div>
                            <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">2 file(s)</span></div>
                        </header>
                        <div class="px-3 pb-3">
                            <div class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <span class="erp-cell-strong">Signed policy schedule</span>
                                    <div class="erp-td-muted">application/pdf · filed by Head of Accounts on 20 Oct 2025</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <a class="btn btn-sm btn-outline-secondary" href="#"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
                                    <button class="btn btn-sm btn-outline-danger" type="button"><i class="bi bi-x-lg" aria-hidden="true"></i> Unfile</button>
                                </div>
                            </div>
                            <div class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <span class="erp-cell-strong">Dhanmondi endorsement 2026</span>
                                    <div class="erp-td-muted">application/pdf · filed by Counter Manager on 04 Feb 2026</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <a class="btn btn-sm btn-outline-secondary" href="#"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
                                    <button class="btn btn-sm btn-outline-danger" type="button"><i class="bi bi-x-lg" aria-hidden="true"></i> Unfile</button>
                                </div>
                            </div>

                            <form class="erp-inline-form mt-3" method="POST" action="#">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-6">
                                        <label class="form-label" for="document_id">File it from the library</label>
                                        <select class="form-select" name="document_id" id="document_id">
                                            <option>stock-policy-2026-renewal.pdf (attachment, pdf)</option>
                                            <option>green-delta-endorsement.pdf (attachment, pdf)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="label">What is it?</label>
                                        <input class="form-control" type="text" id="label" placeholder="Signed copy, 2026 renewal…">
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-paperclip" aria-hidden="true"></i> File</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section class="erp-card mt-3">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">What has happened to it</h2>
                                <p class="erp-card-sub">Renewals keep the date that was true before, so the register can still say what it said last year.</p>
                            </div>
                        </header>
                        <div class="px-3 pb-3">
                            ${history.map((event) => `
                            <div class="erp-list-row erp-list-row-top">
                                <div class="erp-list-row-main">
                                    <span class="erp-chip ${event.tone}">${event.action}</span>
                                    <div>${event.note}</div>
                                    ${event.said ? `<div class="erp-td-muted">“${event.said}”</div>` : ''}
                                </div>
                                <div class="erp-td-muted text-nowrap">${event.when}<div>${event.who}</div></div>
                            </div>`).join('')}
                        </div>
                    </section>
                </div>

                <div class="erp-split-side">
                    <section class="erp-card">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">Renew it</h2>
                                <p class="erp-card-sub">The previous expiry stays in the history — the number that was true last year is still a fact.</p>
                            </div>
                        </header>
                        <form class="p-3 pt-0" method="POST" action="#">
                            <div class="mb-3">
                                <label class="form-label" for="renew_expires_on">New expiry date <span class="text-danger">*</span></label>
                                <input class="form-control" type="date" id="renew_expires_on" value="2027-10-18">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="renewed_on">Renewed on</label>
                                <input class="form-control" type="date" id="renewed_on" value="2026-10-14">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="renew_reference_no">New policy number</label>
                                <input class="form-control" type="text" id="renew_reference_no" placeholder="Leave empty to keep GD-STK-88 21 447">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="renew_value_amount">Renewal sum insured</label>
                                <input class="form-control" type="number" id="renew_value_amount" placeholder="4600000">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="renew_note">Note</label>
                                <input class="form-control" type="text" id="renew_note" placeholder="Premium paid by bank transfer, ref 8871">
                            </div>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Renew</button>
                        </form>
                    </section>

                    <section class="erp-card mt-3">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">Retire it</h2>
                                <p class="erp-card-sub">Surrendered, replaced or torn up. The row stays; the working lists let it go.</p>
                            </div>
                        </header>
                        <form class="p-3 pt-0" method="POST" action="#">
                            <div class="mb-3">
                                <label class="form-label" for="reason">Why</label>
                                <input class="form-control" type="text" id="reason" placeholder="Superseded by the 2027 policy">
                            </div>
                            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-archive" aria-hidden="true"></i> Retire</button>
                        </form>
                    </section>

                    <section class="erp-card mt-3">
                        <header class="erp-card-head"><h2 class="erp-card-title">Where these come from</h2></header>
                        <div class="px-3 pb-3">
                            <p class="erp-td-muted mb-2">Nine menu leaves — trade licence, TIN &amp; BIN, certificates, company documents, logo &amp; seal, contracts, agreements, certificates vault, brand assets — are one table with a kind, so a licence and a policy cannot drift apart.</p>
                            <p class="erp-td-muted mb-0"><code>erp:business:compliance-alerts</code> runs every morning at 06:50: two digests, what has lapsed and what lapses inside the month, deduped per state, company and day.</p>
                        </div>
                    </section>
                </div>
            </div>

        </div>
    </main>
</div>`;
}

/* -------------------------------- 12-09. the compliance lenses and calendar */

export function complianceDesk() {
    const lenses = [
        {
            title: 'Already lapsed',
            note: 'These were true once. Renew, complete or retire them — a lapsed row left alone is how a register stops being trusted.',
            rows: [
                { title: 'Fire safety licence — head office', reference: 'FSC/2025/4471', shelf: 'Trade licence', tracked: '02 Oct 2026', days: '6 ago', state: 'expired', stateLabel: 'Expired' },
                { title: 'Annual return to the registrar', reference: 'RJSC/AR/2026', shelf: 'RJSC filing', tracked: '05 Oct 2026', days: '3 late', state: 'overdue', stateLabel: 'Overdue' },
            ],
        },
        {
            title: 'Inside the next 30 days',
            note: 'The month is where renewals are cheap and late fees are not.',
            rows: [
                { title: 'Stock insurance policy 2026-27', reference: 'GD-STK-88 21 447', shelf: 'Insurance policy', tracked: '18 Oct 2026', days: '10 left', state: 'expiring', stateLabel: 'Expiring' },
                { title: 'Monthly VAT return (Mushak 9.1)', reference: '—', shelf: 'Statutory obligation', tracked: '14 Oct 2026', days: '6 left', state: 'due_soon', stateLabel: 'Due soon' },
                { title: 'Vehicle fitness certificate — Dha 11-4471', reference: 'BRTA/FIT/2026/7741', shelf: 'Company certificate', tracked: '22 Oct 2026', days: '14 left', state: 'expiring', stateLabel: 'Expiring' },
            ],
        },
        {
            title: 'Between 31 and 90 days',
            note: 'Long enough to plan, close enough to see.',
            rows: [
                { title: 'Trade licence — Dhanmondi counter', reference: 'TRAD/DHN/2026/118', shelf: 'Trade licence', tracked: '30 Nov 2026', days: '53 left', state: 'valid', stateLabel: 'In force' },
                { title: 'Trademark registration — “Nirjhor”', reference: 'TM/2019/118842', shelf: 'Company certificate', tracked: '04 Dec 2026', days: '57 left', state: 'valid', stateLabel: 'In force' },
            ],
        },
    ];

    const undated = [
        { title: 'TIN certificate', shelf: 'TIN / BIN', reference: 'TIN 452 118 907', recorded: '12 Mar 2024' },
        { title: 'BIN/VAT registration', shelf: 'TIN / BIN', reference: 'BIN 002345671-0101', recorded: '12 Mar 2024' },
        { title: 'Certificate of incorporation', shelf: 'Company certificate', reference: 'C-88214/2019', recorded: '04 Jan 2024' },
        { title: 'Master logo (current)', shelf: 'Brand asset', reference: 'BRAND/LOGO/v3', recorded: '18 Aug 2025' },
        { title: 'Company seal — impression', shelf: 'Brand asset', reference: 'BRAND/SEAL/v1', recorded: '18 Aug 2025' },
    ];

    const calendarEntries = {
        2: [{ title: 'Fire safety licence (lapsed)', tone: 'erp-cal-entry-danger' }],
        5: [{ title: 'Annual return (3 days late)', tone: 'erp-cal-entry-danger' }],
        14: [{ title: 'Monthly VAT return (Mushak 9.1)', tone: 'erp-cal-entry-warn' }],
        18: [{ title: 'Stock insurance policy', tone: 'erp-cal-entry-warn' }],
        22: [{ title: 'Vehicle fitness certificate', tone: 'erp-cal-entry-warn' }],
        27: [{ title: 'TDS deposit — September', tone: 'erp-cal-entry-warn' }],
       30: [{ title: 'Trade licence — Dhanmondi', tone: 'erp-cal-entry-ok' }],
    };

    const obligations = [
        {
            label: 'Monthly',
            rows: [
                { title: 'Monthly VAT return (Mushak 9.1)', authority: 'National Board of Revenue', due: '14 Oct 2026', days: '6 days left', last: '12 Sep 2026', state: 'due_soon', stateLabel: 'Due soon' },
                { title: 'Monthly TDS deposit (challan 91)', authority: 'National Board of Revenue', due: '27 Oct 2026', days: '19 days left', last: '25 Sep 2026', state: 'valid', stateLabel: 'In force' },
                { title: 'Monthly PF deposit', authority: 'Directorate of Labour', due: '20 Oct 2026', days: '12 days left', last: '18 Sep 2026', state: 'due_soon', stateLabel: 'Due soon' },
            ],
        },
        {
            label: 'Quarterly',
            rows: [
                { title: 'Quarterly VAT return (Mushak 9.2)', authority: 'National Board of Revenue', due: '31 Oct 2026', days: '23 days left', last: '31 Jul 2026', state: 'due_soon', stateLabel: 'Due soon' },
                { title: 'Quarterly labour-law compliance note', authority: 'Directorate of Labour', due: '31 Dec 2026', days: '84 days left', last: '30 Sep 2026', state: 'valid', stateLabel: 'In force' },
            ],
        },
        {
            label: 'Yearly',
            rows: [
                { title: 'Annual return to the registrar', authority: 'RJSC', due: '05 Oct 2026', days: '3 days late', last: '30 Sep 2025', state: 'overdue', stateLabel: 'Overdue' },
                { title: 'Trade licence renewal — head office', authority: 'Dhaka South City Corporation', due: '30 Nov 2026', days: '53 days left', last: '30 Nov 2025', state: 'valid', stateLabel: 'In force' },
            ],
        },
    ];

    // October 2026 starts on a Thursday, so the grid opens on Sunday 27 September
    // and closes on Saturday 31 October: five whole weeks, exactly 35 cells.
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    return `
${previewBar('compliance.html')}
<div class="erp-shell">
    ${sidebar('govern')}
    <main class="erp-main">
        ${topbar('Compliance')}
        <div class="erp-content">

            <header class="erp-page-head">
                <div>
                    <p class="erp-eyebrow"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Business Management · Compliance</p>
                    <h1 class="erp-h1">What is running out</h1>
                    <p class="erp-page-sub">Lapsed first, then the next thirty days, then the rest of the quarter — every expiry and every deadline from every register on one list. The undated shelf is separate, because “no expiry on file” is not the same as “safe”.</p>
                </div>
                <div class="erp-page-head-actions">
                    <a class="btn btn-outline-secondary" href="./records.html"><i class="bi bi-journal-text" aria-hidden="true"></i> All registers</a>
                    <a class="btn btn-outline-secondary" href="#calendar"><i class="bi bi-calendar-event" aria-hidden="true"></i> Calendar</a>
                    <a class="btn btn-outline-secondary" href="#obligations"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recurring duties</a>
                </div>
            </header>

            <div class="erp-kpi-grid">
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Already lapsed</p>
                    <p class="erp-kpi-value">2</p>
                    <p class="erp-kpi-foot">Expired or past the deadline — act, or retire with a reason</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Next 30 days</p>
                    <p class="erp-kpi-value">3</p>
                    <p class="erp-kpi-foot">Renew or file before the date passes</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-calendar-check" aria-hidden="true"></i> 31–90 days</p>
                    <p class="erp-kpi-value">2</p>
                    <p class="erp-kpi-foot">Visible now so it is never a surprise later</p>
                </div>
                <div class="erp-kpi">
                    <p class="erp-kpi-label"><i class="bi bi-question-circle" aria-hidden="true"></i> No date on file</p>
                    <p class="erp-kpi-value">5</p>
                    <p class="erp-kpi-foot">Registrations and assets with no term at all</p>
                </div>
            </div>

            ${lenses.map((lens) => `
            <section class="erp-table-shell">
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">${lens.title} <span class="erp-chip erp-chip-outline">${lens.rows.length} record(s)</span></h2>
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">${lens.note}</span></div>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead>
                            <tr><th>Record</th><th>Shelf</th><th>Tracked date</th><th>Days</th><th>State</th><th></th></tr>
                        </thead>
                        <tbody>
                            ${lens.rows.map((row) => `
                            <tr>
                                <td>
                                    <a class="erp-cell-strong" href="./records.html#detail">${row.title}</a>
                                    ${row.reference !== '—' ? `<div class="erp-td-muted">${row.reference}</div>` : ''}
                                </td>
                                <td class="erp-td-muted">${row.shelf}</td>
                                <td class="erp-td-muted">${row.tracked}</td>
                                <td class="erp-td-num">${row.days}</td>
                                <td>${statusChip(row.state, row.stateLabel)}</td>
                                <td class="erp-td-actions"><a class="btn btn-sm btn-outline-secondary" href="./records.html#detail">Open</a></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>`).join('')}

            <section class="erp-table-shell">
                <div class="erp-card-head px-3 pt-3">
                    <h2 class="erp-card-title">Carrying no date at all <span class="erp-chip erp-chip-outline">5 record(s)</span></h2>
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">A TIN, a number, a logo — nothing to track. And any licence that should have had a term.</span></div>
                </div>
                <div class="erp-table-scroll">
                    <table class="table erp-table">
                        <thead><tr><th>Record</th><th>Shelf</th><th>Reference</th><th>Recorded</th><th></th></tr></thead>
                        <tbody>
                            ${undated.map((row) => `
                            <tr>
                                <td><a class="erp-cell-strong" href="./records.html#detail">${row.title}</a></td>
                                <td class="erp-td-muted">${row.shelf}</td>
                                <td class="erp-td-muted">${row.reference}</td>
                                <td class="erp-td-muted">${row.recorded}</td>
                                <td class="erp-td-actions"><a class="btn btn-sm btn-outline-secondary" href="./records.html#detail">Open</a></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card mt-3" id="calendar">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">October 2026</h2>
                        <p class="erp-card-sub">Sunday to Saturday, whole weeks, so the first and last days are never orphaned. Each entry wears the colour of its own state — the same licence reads the same way here as on its own page.</p>
                    </div>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-soft">7 entries</span>
                    </div>
                </header>
                <div class="p-3">
                    <div class="erp-cal-head">${weekdays.map((weekday) => `<span>${weekday}</span>`).join('')}</div>
                    <div class="erp-cal-grid">
                        ${Array.from({ length: 5 }).map((_, week) => Array.from({ length: 7 }).map((__, weekday) => {
                            const index = week * 7 + weekday;
                            const inMonth = index >= 4 && index <= 34;
                            const dayNumber = index - 3;
                            const entries = inMonth ? (calendarEntries[dayNumber] ?? []) : [];
                            const label = inMonth ? String(dayNumber) : String(27 + index);
                            return `
                            <div class="erp-cal-cell ${inMonth ? '' : 'erp-cal-cell-muted'}">
                                <div class="erp-cal-day">
                                    <span>${label}</span>
                                    ${dayNumber === 8 && inMonth ? '<span class="erp-chip erp-chip-soft">today</span>' : (!inMonth ? '<span class="erp-td-muted">Sep</span>' : '')}
                                </div>
                                ${entries.map((entry) => `<a class="erp-cal-entry ${entry.tone}" href="./records.html#detail">${entry.title}</a>`).join('')}
                            </div>`;
                        }).join('')).join('')}
                    </div>
                </div>
            </section>

            <section class="mt-3" id="obligations">
                ${obligations.map((group) => `
                <div class="erp-table-shell">
                    <div class="erp-card-head px-3 pt-3">
                        <h2 class="erp-card-title">${group.label} <span class="erp-chip erp-chip-outline">${group.rows.length} duty(ies)</span></h2>
                    </div>
                    <div class="erp-table-scroll">
                        <table class="table erp-table">
                            <thead><tr><th>Duty</th><th>Authority</th><th>Next due</th><th>Last done</th><th>State</th><th></th></tr></thead>
                            <tbody>
                                ${group.rows.map((row) => `
                                <tr>
                                    <td><a class="erp-cell-strong" href="./records.html#detail">${row.title}</a></td>
                                    <td class="erp-td-muted">${row.authority}</td>
                                    <td class="erp-td-muted">${row.due}<div class="erp-td-muted">${row.days}</div></td>
                                    <td class="erp-td-muted">${row.last}</td>
                                    <td>${statusChip(row.state, row.stateLabel)}</td>
                                    <td class="erp-td-actions">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-check2" aria-hidden="true"></i> Done</button>
                                        <a class="btn btn-sm btn-outline-secondary" href="./records.html#detail">Open</a>
                                    </td>
                                </tr>`).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>`).join('')}
            </section>

            <div class="erp-note erp-note-info mt-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>Marking a duty done does not clear the row — it sets the next date from the <strong>deadline</strong>, not from the day the work was done, so a return filed three days late is still due the same day next month. The morning digest (<code>erp:business:compliance-alerts</code>, 06:50) says what has lapsed and what lapses inside the month, once per state per company per day.</div>
            </div>

        </div>
    </main>
</div>`;
}
