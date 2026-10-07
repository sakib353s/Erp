@extends('layouts.app')

@section('page_title', 'POS Terminal')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Terminal</h1>
            <p class="erp-page-sub">
                @if ($session)
                    Session <code>{{ $session->session_no }}</code> · open
                    @if ($session->warehouse_id)
                        · WH #{{ $session->warehouse_id }}
                    @endif
                @else
                    No open session
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('pos.sessions.index') }}">Sessions</a>
            @if ($perm('pos.sessions.open') && ! $session)
                <form method="POST" action="{{ route('pos.sessions.open') }}">
                    @csrf
                    <input type="hidden" name="opening_float" value="0">
                    <input type="hidden" name="warehouse_id" value="{{ optional(\App\Domain\Foundation\Warehouse::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)->orderBy('id')->first())->id }}">
                    <button class="btn btn-primary" type="submit">Open session</button>
                </form>
            @endif
            @if ($perm('pos.sessions.close') && $session)
                <form method="POST" action="{{ route('pos.sessions.close', $session) }}">
                    @csrf
                    <input type="hidden" name="closing_counted" value="{{ $session->expected_cash }}">
                    <button class="btn btn-outline-danger" type="submit">Close session</button>
                </form>
            @endif
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
            @if (session('last_receipt'))
                <a class="alert-link ms-2" href="{{ route('pos.receipt', session('last_receipt')) }}" target="_blank" rel="noopener">Print receipt</a>
            @endif
        </div>
    @endif

    @if ($perm('pos.price_check'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3">Price check</h2>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label" for="price-check-input">Product</label>
                    <input type="search" id="price-check-input" class="form-control" placeholder="SKU, code or name" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100" type="button" id="price-check-btn">Check price</button>
                </div>
                <div class="col-md-4">
                    <p class="mb-0" id="price-check-result"><span class="text-muted">Read-only — nothing is added to the cart.</span></p>
                </div>
            </div>
        </div>
        <script>
            (function () {
                const input = document.getElementById('price-check-input');
                const btn = document.getElementById('price-check-btn');
                const out = document.getElementById('price-check-result');

                function check() {
                    const term = input.value.trim();
                    if (!term) {
                        out.className = 'mb-0 text-danger';
                        out.textContent = 'Enter a SKU, code or product name.';
                        return;
                    }

                    out.className = 'mb-0';
                    out.textContent = 'Checking…';

                    fetch('{{ route('pos.price-check') }}?q=' + encodeURIComponent(term), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
                        .then(function (res) {
                            if (!res.ok) {
                                let msg = (res.body && res.body.message) || 'Lookup failed.';
                                if (res.body && res.body.errors) {
                                    msg = Object.values(res.body.errors).flat().join(' ');
                                }
                                out.className = 'mb-0 text-danger';
                                out.textContent = msg;
                                return;
                            }

                            const d = res.body.data;
                            out.className = 'mb-0 fw-semibold';
                            out.textContent = d.name + ' (' + d.sku + ') — ' +
                                Number(d.unit_price).toFixed(2) + ' ' + d.currency +
                                (d.price_list ? ' · ' + d.price_list : ' · no price list');
                        })
                        .catch(function () {
                            out.className = 'mb-0 text-danger';
                            out.textContent = 'Price check is unavailable right now.';
                        });
                }

                btn.addEventListener('click', check);
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        check();
                    }
                });
            })();
        </script>
    @endif

    @if (! $session)
        <div class="erp-card">
            <p class="text-muted mb-0">Open a POS session to start selling. Totals are always computed server-side.</p>
        </div>
    @else
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="erp-card">
                    <h2 class="erp-h3">Products</h2>
                    <input type="search" id="pos-search" class="form-control mb-2" placeholder="Search name, SKU, code, barcode…" autocomplete="off">
                    <div id="pos-results" class="list-group" style="max-height: 280px; overflow: auto;"></div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="erp-card">
                    <h2 class="erp-h3">Cart</h2>
                    <form method="POST" action="{{ route('pos.sales.store') }}" id="pos-sale-form">
                        @csrf
                        <input type="hidden" name="pos_session_id" value="{{ $session->id }}">
                        <input type="hidden" name="client_uuid" value="">
                        <div id="pos-cart-lines"></div>
                        <p id="pos-cart-empty" class="text-muted">Cart is empty.</p>
                        <div class="mb-2">
                            <label class="form-label" for="pos-payment-method">Payment</label>
                            <select name="payment_method" id="pos-payment-method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="mobile">Mobile wallet</option>
                                <option value="bank">Bank / card</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="pos-tendered">Tendered</label>
                            <input type="number" step="0.01" min="0" name="tendered" id="pos-tendered" class="form-control" value="">
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Estimated total</span>
                            <strong id="pos-cart-total">0.00</strong>
                        </div>
                        <p class="form-text">Server recomputes pricing and totals on submit.</p>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary" id="pos-commit" disabled>Commit sale</button>
                        </div>
                    </form>
                    @if ($perm('pos.hold'))
                        <form method="POST" action="{{ route('pos.hold.store') }}" class="mt-2" id="pos-hold-form">
                            @csrf
                            <input type="hidden" name="pos_session_id" value="{{ $session->id }}">
                            <div id="pos-hold-lines"></div>
                            <button type="submit" class="btn btn-outline-secondary w-100" id="pos-hold" disabled>Hold order</button>
                        </form>
                        <a class="btn btn-link btn-sm mt-1" href="{{ route('pos.holds.index') }}">View holds</a>
                    @endif
                    @if ($perm('sales.quotations.create'))
                        <form method="POST" action="{{ route('pos.quotation.store') }}" class="mt-2" id="pos-quote-form">
                            @csrf
                            <div id="pos-quote-lines"></div>
                            <button type="submit" class="btn btn-outline-secondary w-100" id="pos-quote" disabled>Save as quotation</button>
                        </form>
                    @endif
                    @if ($perm('pos.layaway'))
                        <form method="POST" action="{{ route('pos.layaway.store') }}" class="mt-2" id="pos-layaway-form">
                            @csrf
                            <input type="hidden" name="pos_session_id" value="{{ $session->id }}">
                            <div id="pos-layaway-lines"></div>
                            <div class="small text-muted">Layaway / advance deposit</div>
                            <input type="number" step="0.01" min="0.01" name="deposit_amount" class="form-control form-control-sm mb-1" placeholder="Deposit amount (less than total)" required>
                            <div class="d-flex gap-1 mb-1">
                                <input type="number" name="installment_count" class="form-control form-control-sm" title="Installments" placeholder="Installments" value="3" min="1" max="60" required>
                                <input type="number" name="interval_days" class="form-control form-control-sm" title="Days between installments" placeholder="Days apart" value="30" min="1" max="365" required>
                            </div>
                            <select name="payment_method" class="form-select form-select-sm mb-1" required>
                                <option value="cash">Cash deposit</option>
                                <option value="mobile">Mobile wallet</option>
                                <option value="bank">Bank / card</option>
                                <option value="cheque">Cheque</option>
                            </select>
                            <button type="submit" class="btn btn-outline-primary w-100" id="pos-layaway" disabled>Start layaway</button>
                        </form>
                    @endif
                    @if ($perm('pos.customer_display'))
                        <div class="mt-3 pt-2 border-top small">
                            <div class="text-muted">Customer display pairing code</div>
                            @if ($session->display_code)
                                <div class="fw-semibold" id="pos-display-code">{{ $session->display_code }}</div>
                                <a class="btn btn-link btn-sm p-0" href="{{ route('pos.customer-display.index', ['code' => $session->display_code]) }}" target="_blank" rel="noopener">Open display screen</a>
                            @else
                                <span class="text-muted">Reopen the session to mint a pairing code.</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <script>
            (function () {
                const search = document.getElementById('pos-search');
                const results = document.getElementById('pos-results');
                const linesEl = document.getElementById('pos-cart-lines');
                const emptyEl = document.getElementById('pos-cart-empty');
                const totalEl = document.getElementById('pos-cart-total');
                const commitBtn = document.getElementById('pos-commit');
                const form = document.getElementById('pos-sale-form');
                const holdForm = document.getElementById('pos-hold-form');
                const holdLines = document.getElementById('pos-hold-lines');
                const holdBtn = document.getElementById('pos-hold');
                const quoteForm = document.getElementById('pos-quote-form');
                const quoteLines = document.getElementById('pos-quote-lines');
                const quoteBtn = document.getElementById('pos-quote');
                const layawayForm = document.getElementById('pos-layaway-form');
                const layawayLines = document.getElementById('pos-layaway-lines');
                const layawayBtn = document.getElementById('pos-layaway');
                const displayCodeEl = document.getElementById('pos-display-code');
                let displayTimer = null;
                const tendered = document.getElementById('pos-tendered');
                const method = document.getElementById('pos-payment-method');
                const uuidField = form.querySelector('input[name="client_uuid"]');
                const cart = new Map();
                let timer = null;

                uuidField.value = (crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(16).slice(2));

                function renderCart() {
                    linesEl.innerHTML = '';
                    let total = 0;
                    let idx = 0;
                    cart.forEach((line, id) => {
                        const lineTotal = line.qty * line.unit_price;
                        total += lineTotal;
                        const row = document.createElement('div');
                        row.className = 'd-flex gap-2 align-items-center mb-2';
                        row.innerHTML =
                            '<div class="flex-grow-1">' +
                                '<div class="fw-semibold">' + escapeHtml(line.name) + '</div>' +
                                '<div class="small text-muted">' + escapeHtml(line.sku) + ' × ' + line.qty + ' @ ' + line.unit_price.toFixed(2) + '</div>' +
                                '<input type="hidden" name="lines[' + idx + '][product_id]" value="' + id + '">' +
                                '<input type="hidden" name="lines[' + idx + '][qty]" value="' + line.qty + '">' +
                                '<input type="hidden" name="lines[' + idx + '][unit_price]" value="' + line.unit_price + '">' +
                            '</div>' +
                            '<button type="button" class="btn btn-sm btn-outline-danger" data-remove="' + id + '">×</button>';
                        linesEl.appendChild(row);
                        idx += 1;
                    });
                    totalEl.textContent = total.toFixed(2);
                    emptyEl.style.display = cart.size ? 'none' : '';
                    commitBtn.disabled = cart.size === 0;
                    if (holdBtn) holdBtn.disabled = cart.size === 0;
                    if (holdForm) {
                        holdLines.innerHTML = '';
                        let hIdx = 0;
                        cart.forEach((line, id) => {
                            holdLines.innerHTML +=
                                '<input type="hidden" name="lines[' + hIdx + '][product_id]" value="' + id + '">' +
                                '<input type="hidden" name="lines[' + hIdx + '][qty]" value="' + line.qty + '">' +
                                '<input type="hidden" name="lines[' + hIdx + '][unit_price]" value="' + line.unit_price + '">';
                            hIdx += 1;
                        });
                    }
                    if (quoteBtn) quoteBtn.disabled = cart.size === 0;
                    if (quoteForm) {
                        quoteLines.innerHTML = '';
                        let qIdx = 0;
                        cart.forEach((line, id) => {
                            quoteLines.innerHTML +=
                                '<input type="hidden" name="lines[' + qIdx + '][product_id]" value="' + id + '">' +
                                '<input type="hidden" name="lines[' + qIdx + '][qty]" value="' + line.qty + '">' +
                                '<input type="hidden" name="lines[' + qIdx + '][unit_price]" value="' + line.unit_price + '">';
                            qIdx += 1;
                        });
                    }
                    if (layawayBtn) layawayBtn.disabled = cart.size === 0;
                    if (layawayForm) {
                        layawayLines.innerHTML = '';
                        let lIdx = 0;
                        cart.forEach((line, id) => {
                            layawayLines.innerHTML +=
                                '<input type="hidden" name="lines[' + lIdx + '][product_id]" value="' + id + '">' +
                                '<input type="hidden" name="lines[' + lIdx + '][qty]" value="' + line.qty + '">' +
                                '<input type="hidden" name="lines[' + lIdx + '][unit_price]" value="' + line.unit_price + '">';
                            lIdx += 1;
                        });
                    }
                    if (method.value === 'cash' && (!tendered.value || parseFloat(tendered.value) < total)) {
                        tendered.value = total.toFixed(2);
                    }
                    scheduleDisplayPush();
                }

                function pushDisplay() {
                    if (!displayCodeEl) return;
                    const items = [];
                    cart.forEach((line) => {
                        items.push({ name: line.name, qty: line.qty, unit_price: line.unit_price });
                    });
                    fetch('{{ route('pos.customer-display.push') }}', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({ code: displayCodeEl.textContent.trim(), lines: items })
                    }).catch(function () {});
                }

                function scheduleDisplayPush() {
                    if (!displayCodeEl) return;
                    clearTimeout(displayTimer);
                    displayTimer = setTimeout(pushDisplay, 400);
                }

                scheduleDisplayPush();

                    function escapeHtml(s) {
                        const d = document.createElement('div');
                        d.textContent = s;
                        return d.innerHTML;
                    }

                linesEl.addEventListener('click', function (e) {
                    const btn = e.target.closest('[data-remove]');
                    if (!btn) return;
                    cart.delete(btn.getAttribute('data-remove'));
                    renderCart();
                });

                method.addEventListener('change', renderCart);

                search.addEventListener('input', function () {
                    clearTimeout(timer);
                    const q = search.value.trim();
                    if (!q) {
                        results.innerHTML = '';
                        return;
                    }
                    timer = setTimeout(function () {
                        fetch('{{ route('pos.products') }}?q=' + encodeURIComponent(q), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (body) {
                                results.innerHTML = '';
                                (body.data || []).forEach(function (p) {
                                    const a = document.createElement('button');
                                    a.type = 'button';
                                    a.className = 'list-group-item list-group-item-action';
                                    a.innerHTML = '<div class="fw-semibold">' + escapeHtml(p.name) + '</div>' +
                                        '<div class="small text-muted">' + escapeHtml(p.sku) + ' · ' + Number(p.unit_price).toFixed(2) + '</div>';
                                    a.addEventListener('click', function () {
                                        const existing = cart.get(p.id);
                                        if (existing) {
                                            existing.qty += 1;
                                        } else {
                                            cart.set(p.id, { name: p.name, sku: p.sku, qty: 1, unit_price: Number(p.unit_price) });
                                        }
                                        renderCart();
                                        search.value = '';
                                        results.innerHTML = '';
                                    });
                                    results.appendChild(a);
                                });
                            });
                    }, 180);
                });
            })();
        </script>
    @endif
@endsection
