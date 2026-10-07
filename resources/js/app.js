/**
 * BD ERP — front-end behaviour layer (docs/ARCHITECTURE.md §18.7).
 *
 * One flat ES module set, no anonymous one-off JS blobs in Blade. Everything
 * here is progressive enhancement: the server remains authoritative for
 * authorisation, money and stock (Rule 7 — this layer only hides controls the
 * server already refuses, and computes display-only values).
 *
 * Modules:
 *   shell        — sidebar off-canvas, desktop rail collapse, density/theme
 *   palette      — ⌘K / Ctrl+K command palette over the server nav index
 *   toasts       — transient feedback only
 *   table        — row selection + bulk action bar + sticky bulk summary
 *   forms        — unsaved-change guard, submit-once, dependent selects
 *   matrix       — permission matrix counters (roles screen)
 *   docLines     — document line grids (purchase orders/receipts)
 *   repeaters    — workflow approver/step repeaters
 *   poller       — notification bell (server-truthful counts)
 *   scanner      — barcode/QR capture into a target input
 */

import * as bootstrap from 'bootstrap';

const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));
const store = {
    get(key, fallback = null) {
        try { return window.localStorage.getItem(key) ?? fallback; } catch { return fallback; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch { /* private mode */ }
    },
    remove(key) {
        try { window.localStorage.removeItem(key); } catch { /* private mode */ }
    },
};

export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

/* ---------------------------------------------------------------- 1. shell */
const shell = (() => {
    const shellEl = $('.erp-shell');
    const sidebar = $('#erpSidebar');
    const backdrop = $('[data-erp-backdrop]');
    const isDesktop = () => window.matchMedia('(min-width: 992px)').matches;

    function setDrawer(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('is-open', open);
        if (backdrop) backdrop.hidden = !open;
        document.body.style.overflow = open && !isDesktop() ? 'hidden' : '';
    }

    function setRail(collapsed) {
        if (!shellEl) return;
        shellEl.dataset.rail = collapsed ? 'collapsed' : 'expanded';
        store.set('erp.rail', collapsed ? 'collapsed' : 'expanded');
        $$('[data-erp-rail-toggle]').forEach((btn) => {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            btn.title = collapsed ? 'Expand navigation' : 'Collapse navigation';
        });
    }

    function applyStoredPreferences() {
        if (shellEl) {
            const rail = store.get('erp.rail', 'expanded');
            shellEl.dataset.rail = isDesktop() && rail === 'collapsed' ? 'collapsed' : 'expanded';
        }

        const theme = store.get('erp.theme', 'light');
        const accent = store.get('erp.accent');
        if (theme === 'dark') document.documentElement.dataset.theme = 'dark';
        if (accent) document.documentElement.dataset.accent = accent;

        const density = store.get('erp.density');
        if (density === 'compact') document.documentElement.dataset.density = 'compact';

        syncToggles();
    }

    function syncToggles() {
        const dark = document.documentElement.dataset.theme === 'dark';
        $$('[data-erp-theme-toggle]').forEach((btn) => {
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            const icon = $('i', btn);
            if (icon) icon.className = `bi ${dark ? 'bi-sun' : 'bi-moon-stars'}`;
            btn.title = dark ? 'Switch to light appearance' : 'Switch to dark appearance';
        });

        const compact = document.documentElement.dataset.density === 'compact';
        $$('[data-erp-density-toggle]').forEach((btn) => {
            btn.setAttribute('aria-pressed', compact ? 'true' : 'false');
            btn.title = compact ? 'Comfortable rows' : 'Compact rows';
        });
    }

    function init() {
        applyStoredPreferences();

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;

            if (target.closest('[data-erp-sidebar-open]')) { setDrawer(true); return; }
            if (target.closest('[data-erp-sidebar-close]')) { setDrawer(false); return; }
            if (target.closest('[data-erp-rail-toggle]')) {
                setRail(shellEl?.dataset.rail !== 'collapsed');
                return;
            }
            if (target.closest('[data-erp-theme-toggle]')) {
                const dark = document.documentElement.dataset.theme === 'dark';
                if (dark) delete document.documentElement.dataset.theme;
                else document.documentElement.dataset.theme = 'dark';
                store.set('erp.theme', dark ? 'light' : 'dark');
                syncToggles();
                return;
            }
            if (target.closest('[data-erp-density-toggle]')) {
                const compact = document.documentElement.dataset.density === 'compact';
                if (compact) delete document.documentElement.dataset.density;
                else document.documentElement.dataset.density = 'compact';
                store.set('erp.density', compact ? 'comfortable' : 'compact');
                syncToggles();
            }
        });

        backdrop?.addEventListener('click', () => setDrawer(false));

        // Navigating away closes the mobile drawer.
        sidebar?.addEventListener('click', (event) => {
            if (event.target.closest('a') && !isDesktop()) setDrawer(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setDrawer(false);
        });

        window.addEventListener('resize', () => {
            if (isDesktop()) {
                setDrawer(false);
                setRail(store.get('erp.rail', 'expanded') === 'collapsed');
            }
        });
    }

    return { init, setDrawer, setRail };
})();

/* -------------------------------------------------------------- 2. palette */
const palette = (() => {
    const root = $('#erpPalette');
    if (!root) return { init() {} };

    const input = $('[data-palette-input]', root);
    const list = $('[data-palette-list]', root);
    const source = window.erpNavIndex || [];
    let items = [];
    let activeIndex = 0;
    let lastFocus = null;

    function open() {
        lastFocus = document.activeElement;
        root.classList.add('is-open');
        root.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        input.value = '';
        render('');
        window.setTimeout(() => input.focus(), 20);
    }

    function close() {
        root.classList.remove('is-open');
        root.setAttribute('hidden', 'hidden');
        document.body.style.overflow = '';
        if (lastFocus instanceof HTMLElement) lastFocus.focus();
    }

    function isOpen() {
        return root.classList.contains('is-open');
    }

    function score(entry, query) {
        const haystack = `${entry.label} ${entry.section ?? ''} ${entry.keywords ?? ''} ${entry.group ?? ''}`.toLowerCase();
        if (!haystack.includes(query)) return -1;
        const label = entry.label.toLowerCase();
        if (label.startsWith(query)) return 0;
        if (label.includes(query)) return 1;
        return 2;
    }

    function render(query) {
        const q = query.trim().toLowerCase();
        const ranked = source
            .map((entry) => ({ entry, rank: score(entry, q) }))
            .filter((row) => row.rank >= 0)
            .sort((a, b) => a.rank - b.rank || a.entry.label.localeCompare(b.entry.label))
            .slice(0, 40)
            .map((row) => row.entry);

        items = ranked;
        activeIndex = 0;

        if (ranked.length === 0) {
            list.innerHTML = `<p class="erp-palette-empty">
                Nothing matches “${escapeHtml(query)}”.<br>
                <small class="text-body-secondary">Try a document number, module name or action.</small>
            </p>`;
            return;
        }

        let html = '';
        let currentGroup = null;

        ranked.forEach((entry, index) => {
            const group = entry.section ?? entry.group ?? 'Navigate';
            if (group !== currentGroup) {
                html += `<p class="erp-palette-group">${escapeHtml(group)}</p>`;
                currentGroup = group;
            }
            html += `<a class="erp-palette-item${index === activeIndex ? ' is-active' : ''}"
                        href="${escapeHtml(entry.url)}" data-palette-item="${index}">
                        <i class="bi ${escapeHtml(entry.icon || 'bi-dot')}" aria-hidden="true"></i>
                        <span>${escapeHtml(entry.label)}</span>
                        ${entry.hint ? `<span class="erp-palette-hint">${escapeHtml(entry.hint)}</span>` : ''}
                     </a>`;
        });

        list.innerHTML = html;
        $$('[data-palette-item]', list).forEach((el) => {
            // Permalinks keep ⌘/Ctrl-click working; plain clicks navigate in place.
            el.addEventListener('click', () => close());
        });
    }

    function highlight() {
        $$('[data-palette-item]', list).forEach((el, index) => {
            el.classList.toggle('is-active', index === activeIndex);
        });
        $('[data-palette-item].is-active', list)?.scrollIntoView({ block: 'nearest' });
    }

    function init() {
        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (target.closest('[data-palette-open]')) { event.preventDefault(); open(); return; }
            if (target === root || target.closest('[data-palette-close]')) close();
        });

        input?.addEventListener('input', () => render(input.value));

        root.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (items.length === 0) return;
                activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                highlight();
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                const entry = items[activeIndex];
                if (entry) window.location.assign(entry.url);
                return;
            }
            if (event.key === 'Escape') close();
        });

        document.addEventListener('keydown', (event) => {
            const typing = event.target instanceof HTMLElement
                && ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);
            const isPaletteKey = (event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey);
            const isSlash = event.key === '/' && !typing;

            if (isPaletteKey || isSlash) {
                event.preventDefault();
                isOpen() ? close() : open();
            }
        });
    }

    return { init, open };
})();

/* --------------------------------------------------------------- 3. toasts */
const toasts = (() => {
    function stack() {
        let el = $('[data-erp-toasts]');
        if (!el) {
            el = document.createElement('div');
            el.className = 'erp-toast-stack';
            el.dataset.erpToasts = '';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        return el;
    }

    function show({ title, text = '', variant = 'ok', timeout = 5200 } = {}) {
        const icons = { ok: 'bi-check-circle', warn: 'bi-exclamation-triangle', danger: 'bi-x-circle', info: 'bi-info-circle' };
        const node = document.createElement('div');
        node.className = `erp-toast erp-toast-${variant}`;
        node.innerHTML = `
            <i class="bi ${icons[variant] ?? icons.ok} erp-toast-icon" aria-hidden="true"></i>
            <div class="erp-toast-body">
                <p class="erp-toast-title">${escapeHtml(title)}</p>
                ${text ? `<p class="erp-toast-text">${escapeHtml(text)}</p>` : ''}
            </div>
            <button class="erp-toast-close" type="button" aria-label="Dismiss">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>`;

        const dismiss = () => {
            node.classList.add('is-leaving');
            window.setTimeout(() => node.remove(), 200);
        };

        $('.erp-toast-close', node)?.addEventListener('click', dismiss);
        stack().appendChild(node);
        if (timeout) window.setTimeout(dismiss, timeout);
    }

    function init() {
        // Server flashes arrive as hidden data, get rendered as real toasts.
        $$('[data-erp-flash]').forEach((el) => {
            show({
                title: el.dataset.erpFlashTitle ?? el.dataset.erpFlash,
                text: el.dataset.erpFlashText ?? '',
                variant: el.dataset.erpFlashVariant ?? 'ok',
                timeout: el.dataset.erpFlashVariant === 'danger' ? 9000 : 5200,
            });
        });
    }

    return { init, show };
})();

/* ---------------------------------------------------------------- 4. tables */
const tables = (() => {
    function init() {
        $$('[data-erp-select-all]').forEach((master) => {
            const scope = master.closest('[data-erp-table]') ?? document;
            const boxes = () => $$('[data-erp-row-select]', scope);

            master.addEventListener('change', () => {
                boxes().forEach((box) => { box.checked = master.checked; });
                sync(scope);
            });

            boxes().forEach((box) => box.addEventListener('change', () => sync(scope)));
            sync(scope);
        });

        // Rows flagged as attention help operators scan exceptions, not noise.
        $$('[data-erp-row-href]').forEach((row) => {
            row.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, select, label')) return;
                window.location.assign(row.dataset.erpRowHref);
            });
        });
    }

    function sync(scope) {
        const boxes = $$('[data-erp-row-select]', scope);
        const checked = boxes.filter((box) => box.checked);
        const master = $('[data-erp-select-all]', scope);

        if (master) {
            master.checked = boxes.length > 0 && checked.length === boxes.length;
            master.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }

        boxes.forEach((box) => box.closest('tr')?.classList.toggle('erp-row-selected', box.checked));

        const bar = $('[data-erp-bulkbar]', scope);
        if (bar) {
            bar.classList.toggle('is-visible', checked.length > 0);
            const count = $('[data-erp-bulk-count]', bar);
            if (count) count.textContent = String(checked.length);
        }
    }

    return { init };
})();

/* ---------------------------------------------------------------- 5. forms */
const forms = (() => {
    function init() {
        // Auto-submitting context switchers.
        document.addEventListener('change', (event) => {
            const select = event.target.closest('[data-erp-autosubmit]');
            if (select) select.form?.requestSubmit();
        });

        // Destructive confirmation.
        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form[data-confirm]');
            if (form && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }

            // Double-submit guard: the server still enforces idempotency keys.
            const button = event.submitter ?? null;
            if (form && form.dataset.noSubmitOnce === undefined && button) {
                window.setTimeout(() => { button.disabled = true; }, 0);
            }
        });

        // Unsaved-change guard on long forms.
        const guards = $$('form[data-erp-dirty-guard]');
        if (guards.length > 0) {
            let dirty = false;
            let submitting = false;

            guards.forEach((form) => {
                form.addEventListener('input', () => { dirty = true; });
                form.addEventListener('submit', () => { submitting = true; });
            });

            window.addEventListener('beforeunload', (event) => {
                if (!dirty || submitting) return;
                event.preventDefault();
                event.returnValue = '';
            });
        }

        // Debounced submit for search-as-you-type toolbars.
        $$('[data-erp-search]').forEach((input) => {
            let timer = null;
            input.addEventListener('input', () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(() => input.form?.requestSubmit(), 420);
            });
        });
    }

    return { init };
})();

/* ---------------------------------------------------------------- 6. matrix */
const matrix = (() => {
    function refresh(module) {
        const box = $(`[data-module="${module}"]`);
        if (!box) return;

        const items = $$('[data-module-item]', box);
        const checked = items.filter((item) => item.checked).length;
        const counter = $('[data-module-count]', box);
        if (counter) counter.textContent = `${checked}/${items.length}`;

        const master = $('[data-module-checkall]', box);
        if (master) {
            master.checked = checked > 0 && checked === items.length;
            master.indeterminate = checked > 0 && checked < items.length;
        }

        const total = $$('input[name="permissions[]"]:checked').length;
        const totalEl = $('[data-permission-count]');
        if (totalEl) totalEl.textContent = `${total} selected`;
    }

    function init() {
        document.addEventListener('change', (event) => {
            const master = event.target.closest('[data-module-checkall]');
            if (master) {
                const module = master.dataset.moduleCheckall;
                $$(`[data-module-item="${module}"]`).forEach((item) => { item.checked = master.checked; });
                refresh(module);
                return;
            }

            const item = event.target.closest('[data-module-item]');
            if (item) refresh(item.dataset.moduleItem);
        });
    }

    return { init };
})();

/* ------------------------------------------------------------- 7. repeaters */
function syncApproverTypes(scope = document) {
    $$('[data-approver-type]', scope).forEach((select) => {
        const row = select.closest('[data-repeater-row]') || select.closest('.row');
        if (!row) return;
        const wantsRole = select.value !== 'user';
        const roleField = $('[data-approver-role]', row);
        const userField = $('[data-approver-user]', row);
        if (roleField) roleField.classList.toggle('d-none', !wantsRole);
        if (userField) userField.classList.toggle('d-none', wantsRole);
    });
}

const repeaters = (() => {
    function init() {
        document.addEventListener('click', (event) => {
            const addBtn = event.target.closest('[data-repeater-add]');
            if (addBtn) {
                const name = addBtn.dataset.repeaterAdd;
                const container = $(`[data-repeater="${name}"]`);
                const template = $(`[data-repeater-template="${name}"]`);
                if (!container || !template) return;

                const idx = Number.parseInt(container.dataset.repeaterStart, 10) || 0;
                container.dataset.repeaterStart = String(idx + 1);
                container.insertAdjacentHTML(
                    'beforeend',
                    template.innerHTML.replaceAll('__IDX__', String(idx)),
                );
                syncApproverTypes(container.lastElementChild);
                return;
            }

            const removeBtn = event.target.closest('[data-repeater-remove]');
            if (removeBtn) removeBtn.closest('[data-repeater-row]')?.remove();
        });

        document.addEventListener('change', (event) => {
            if (event.target.matches?.('[data-approver-type]')) syncApproverTypes();
        });

        syncApproverTypes();
    }

    return { init };
})();

/* ------------------------------------------------- 7b. document line grids */
/**
 * Line editor for document tables (purchase orders, goods receipts).
 *
 * The server recomputes every figure on save — this only keeps the screen
 * honest while typing, so the number the operator sees is the number the
 * service will store: qty × price − discount, then tax on the taxable part.
 */
const docLines = (() => {
    const money = (value) => `৳ ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    function scopeOf(node) {
        return node.closest('[data-erp-lines]');
    }

    function rows(scope) {
        return $$('[data-erp-line]', scope);
    }

    function reindex(scope) {
        rows(scope).forEach((row, index) => {
            $$('[name]', row).forEach((input) => {
                input.name = input.name.replace(/lines\[\d+\]/, `lines[${index}]`);
            });
        });

        const count = $('[data-erp-line-count]', scope);
        if (count) count.textContent = String(rows(scope).length);
    }

    function clear(row) {
        $$('input, select, textarea', row).forEach((field) => {
            if (field.tagName === 'SELECT') {
                field.selectedIndex = 0;
            } else if (field.type === 'number') {
                field.value = '0';
            } else {
                field.value = '';
            }
        });
    }

    function recalc(scope) {
        if (!scope) return;

        let sum = 0;

        rows(scope).forEach((row) => {
            const qtyField = $('[data-erp-line-qty]', row) ?? $('[name$="[qty_ordered]"]', row);
            const priceField = $('[data-erp-line-price]', row) ?? $('[name$="[unit_price]"]', row);
            const discountField = $('[name$="[discount]"]', row);
            const taxField = $('[name$="[tax_rate]"]', row);

            const qty = Number.parseFloat(qtyField?.value ?? '0') || 0;
            const price = Number.parseFloat(priceField?.value ?? '0') || 0;
            const discount = Math.min(Number.parseFloat(discountField?.value ?? '0') || 0, qty * price);
            const tax = Math.max(0, (qty * price - discount)) * ((Number.parseFloat(taxField?.value ?? '0') || 0) / 100);
            const total = qty * price - discount + tax;

            const cell = $('[data-erp-line-total]', row);
            if (cell) cell.textContent = money(total);

            sum += total;
        });

        const sumCell = $('[data-erp-lines-sum]', scope);
        if (sumCell) sumCell.textContent = money(sum);

        reindex(scope);
    }

    function init() {
        document.addEventListener('click', (event) => {
            const add = event.target.closest('[data-erp-add-line]');
            if (add) {
                const scope = scopeOf(add);
                const last = rows(scope).pop();
                if (!scope || !last) return;

                const clone = last.cloneNode(true);
                clear(clone);
                $('[data-erp-lines-body]', scope)?.appendChild(clone);

                const firstField = $('select, input', clone);
                firstField?.focus();

                recalc(scope);
                return;
            }

            const remove = event.target.closest('[data-erp-remove-line]');
            if (remove) {
                const scope = scopeOf(remove);
                const row = remove.closest('[data-erp-line]');
                if (!scope || !row) return;

                if (rows(scope).length > 1) {
                    row.remove();
                } else {
                    clear(row);
                }

                recalc(scope);
            }
        });

        document.addEventListener('input', (event) => {
            const scope = scopeOf(event.target);
            if (scope) recalc(scope);
        });

        document.addEventListener('change', (event) => {
            const scope = scopeOf(event.target);
            if (scope) recalc(scope);
        });

        $$('[data-erp-lines]').forEach(recalc);
    }

    return { init, recalc };
})();

/* --------------------------------------------------------------- 8. poller */
const poller = (() => {
    function init() {
        const pollUrl = document.body.dataset.notifPoll;
        if (!pollUrl) return;

        const tick = async () => {
            try {
                const response = await fetch(pollUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const data = await response.json();

                const badge = $('[data-notif-count]');
                if (badge) badge.textContent = data.unread > 0 ? String(data.unread) : '';

                const label = $('[data-notif-unread]');
                if (label) label.textContent = `${data.unread} unread`;

                const list = $('[data-erp-notif-list]');
                if (list && Array.isArray(data.latest)) {
                    list.innerHTML = data.latest.length === 0
                        ? '<p class="dropdown-item-text erp-notif-loading">No notifications yet.</p>'
                        : data.latest.map((item) => `
                            <a class="dropdown-item${item.read_at ? '' : ' fw-semibold'}"
                               href="/app/notifications" style="white-space:normal">
                                ${escapeHtml(item.title)}
                                <small class="d-block fw-normal text-body-secondary">
                                    ${item.read_at ? 'read' : 'unread'} · ${escapeHtml(item.priority)}
                                </small>
                            </a>`).join('');
                }
            } catch {
                /* network hiccup — the next tick retries; never spam the console */
            }
        };

        const seconds = Number.parseInt(document.body.dataset.pollSeconds, 10) || 60;
        window.setInterval(tick, Math.max(15, seconds) * 1000);
    }

    return { init };
})();

/* -------------------------------------------------------------- 9. scanner */
const scanner = (() => {
    function init() {
        // Hardware scanners type + Enter. When a page asks for capture, keystrokes
        // land in the target field instead of scrolling the page.
        const targetId = document.body.dataset.erpScanTarget;
        if (!targetId) return;

        const field = document.getElementById(targetId);
        if (!field) return;

        let buffer = '';
        let last = 0;

        document.addEventListener('keydown', (event) => {
            if (event.target === field || event.metaKey || event.ctrlKey || event.altKey) return;
            const now = Date.now();
            if (now - last > 120) buffer = '';
            last = now;

            if (event.key === 'Enter' && buffer.length > 3) {
                field.value = buffer;
                buffer = '';
                field.form?.requestSubmit();
                return;
            }

            if (event.key.length === 1) buffer += event.key;
        });
    }

    return { init };
})();

/* ------------------------------------------------------------------ bootstrap */
shell.init();
palette.init();
toasts.init();
tables.init();
forms.init();
matrix.init();
repeaters.init();
docLines.init();
poller.init();
scanner.init();

window.erpUI = { toasts, palette, shell, tables, syncApproverTypes, docLines };
