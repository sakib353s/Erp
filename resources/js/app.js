/**
 * BD ERP — front-end behaviour layer.
 *
 * Only progressive enhancements live here: sidebar off-canvas, auto-
 * submitting context switchers, destructive-action confirms, permission
 * matrix helpers, workflow repeaters and the notification poller.
 * All authorisation remains server-side (Rule 7 — this layer only hides
 * controls the server already refuses).
 */

import * as bootstrap from 'bootstrap';

/* ------------------------------------------------------------ sidebar */
const sidebar = document.getElementById('erpSidebar');
const backdrop = document.querySelector('[data-erp-backdrop]');

function setSidebar(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    document.body.style.overflow = open ? 'hidden' : '';
}

document.querySelector('[data-erp-sidebar-open]')?.addEventListener('click', () => setSidebar(true));
document.querySelector('[data-erp-sidebar-close]')?.addEventListener('click', () => setSidebar(false));
backdrop?.addEventListener('click', () => setSidebar(false));

// Close the off-canvas menu after navigating (mobile).
sidebar?.addEventListener('click', (event) => {
    if (event.target.closest('a') && window.innerWidth < 992) setSidebar(false);
});

/* --------------------------------------------------- auto-submit selects */
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-erp-autosubmit]');
    if (select) select.form?.requestSubmit();
});

/* -------------------------------------------------------- confirm forms */
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

/* -------------------------------------------------- workflow repeaters */
document.addEventListener('click', (event) => {
    const addBtn = event.target.closest('[data-repeater-add]');
    if (addBtn) {
        const name = addBtn.dataset.repeaterAdd;
        const container = document.querySelector(`[data-repeater="${name}"]`);
        const template = document.querySelector(`[data-repeater-template="${name}"]`);
        if (!container || !template) return;

        const idx = Number.parseInt(container.dataset.repeaterStart, 10) || 0;
        container.dataset.repeaterStart = String(idx + 1);

        const html = template.innerHTML.replaceAll('__IDX__', String(idx));
        container.insertAdjacentHTML('beforeend', html);
        syncApproverTypes(container.lastElementChild);
        return;
    }

    const removeBtn = event.target.closest('[data-repeater-remove]');
    if (removeBtn) {
        removeBtn.closest('[data-repeater-row]')?.remove();
    }
});

/* --------------------------------------------- approver role/user toggle */
function syncApproverTypes(scope = document) {
    scope.querySelectorAll('[data-approver-type]').forEach((select) => {
        const row = select.closest('[data-repeater-row]') || select.closest('.row');
        if (!row) return;
        const wantsRole = select.value !== 'user';
        const roleField = row.querySelector('[data-approver-role]');
        const userField = row.querySelector('[data-approver-user]');
        if (roleField) roleField.classList.toggle('d-none', !wantsRole);
        if (userField) userField.classList.toggle('d-none', wantsRole);
    });
}

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-approver-type]')) syncApproverTypes();
});

/* -------------------------------------------------- permission matrix UI */
function refreshMatrixCounts(module) {
    const box = document.querySelector(`[data-module="${module}"]`);
    if (!box) return;
    const items = box.querySelectorAll('[data-module-item]');
    const checked = box.querySelectorAll('[data-module-item]:checked').length;
    const counter = box.querySelector(`[data-module-count]`);
    if (counter) counter.textContent = `${checked}/${items.length}`;
    const master = box.querySelector('[data-module-checkall]');
    if (master) master.checked = checked > 0 && checked === items.length;

    const total = document.querySelectorAll('input[name="permissions[]"]:checked').length;
    const totalEl = document.querySelector('[data-permission-count]');
    if (totalEl) totalEl.textContent = `${total} selected`;
}

document.addEventListener('change', (event) => {
    const master = event.target.closest('[data-module-checkall]');
    if (master) {
        const module = master.dataset.moduleCheckall;
        document
            .querySelectorAll(`[data-module-item="${module}"]`)
            .forEach((item) => { item.checked = master.checked; });
        refreshMatrixCounts(module);
        return;
    }

    const item = event.target.closest('[data-module-item]');
    if (item) refreshMatrixCounts(item.dataset.moduleItem);
});

/* ------------------------------------------------ notification polling */
const pollUrl = document.body.dataset.notifPoll;

async function pollNotifications() {
    if (!pollUrl) return;
    try {
        const response = await fetch(pollUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;
        const data = await response.json();

        const badge = document.querySelector('[data-notif-count]');
        if (badge) badge.textContent = data.unread > 0 ? String(data.unread) : '';

        const label = document.querySelector('[data-notif-unread]');
        if (label) label.textContent = `${data.unread} unread`;

        const list = document.querySelector('[data-erp-notif-list]');
        if (list && Array.isArray(data.latest)) {
            list.innerHTML = data.latest.length === 0
                ? '<p class="dropdown-item-text erp-notif-loading">No notifications yet.</p>'
                : data.latest.map((item) => `
                    <a class="dropdown-item${item.read_at ? '' : ' fw-semibold'}"
                       href="/app/notifications"
                       style="white-space:normal">
                        ${escapeHtml(item.title)}
                        <small class="d-block fw-normal text-body-secondary" style="font-size:11.5px">
                            ${item.read_at ? 'read' : 'unread'} · ${escapeHtml(item.priority)}
                        </small>
                    </a>`).join('');
        }
    } catch {
        /* network hiccup — the next tick retries; never spam the console */
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

if (pollUrl) {
    const seconds = Number.parseInt(document.body.dataset.pollSeconds, 10) || 60;
    window.setInterval(pollNotifications, Math.max(15, seconds) * 1000);
}

/* one-time init for server-rendered repeater rows */
syncApproverTypes();
