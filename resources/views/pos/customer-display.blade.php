@extends('layouts.app')

@section('page_title', 'Customer Display')

@section('content')
    @if ($code === '')
        <div class="erp-page-head">
            <div>
                <h1 class="erp-h1">Customer Display</h1>
                <p class="erp-page-sub">Pair this screen with a till: enter the code shown under “Customer display pairing code” on the POS terminal.</p>
            </div>
        </div>
        <div class="erp-card" style="max-width: 480px;">
            <form method="GET" action="{{ route('pos.customer-display.index') }}">
                <label class="form-label" for="display-code">Pairing code</label>
                <input class="form-control mb-2" id="display-code" name="code" maxlength="16" required autofocus placeholder="e.g. K7Q2X9AB" autocomplete="off">
                <button class="btn btn-primary w-100" type="submit">Pair</button>
            </form>
        </div>
    @elseif (! $paired)
        <div class="erp-page-head">
            <div>
                <h1 class="erp-h1">Customer Display</h1>
                <p class="erp-page-sub">Code <code>{{ $code }}</code> is not paired.</p>
            </div>
        </div>
        <div class="erp-card">
            <div class="alert alert-warning mb-0">
                No open POS session is paired to code <code>{{ $code }}</code>. Check the pairing code on the terminal (sessions mint a fresh code each time they open), then try again.
            </div>
            <a class="btn btn-outline-secondary mt-3" href="{{ route('pos.customer-display.index') }}">Try another code</a>
        </div>
    @else
        <div id="display-root" data-code="{{ $code }}">
            <div class="erp-page-head">
                <div>
                    <h1 class="erp-h1">Customer Display</h1>
                    <p class="erp-page-sub">Paired with code <code>{{ $code }}</code></p>
                </div>
            </div>
            <div class="erp-card">
                <div id="display-status" class="mb-2 text-muted">Waiting for items…</div>
                <div id="display-lines"></div>
                <div class="d-flex justify-content-between align-items-baseline mt-3 border-top pt-2">
                    <span class="text-muted" id="display-count">0 items</span>
                    <span class="h2 mb-0 fw-bold" id="display-total">0.00</span>
                </div>
                <div class="small text-muted mt-1" id="display-updated"></div>
            </div>
        </div>
        <script>
            (function () {
                const root = document.getElementById('display-root');
                const statusEl = document.getElementById('display-status');
                const linesEl = document.getElementById('display-lines');
                const countEl = document.getElementById('display-count');
                const totalEl = document.getElementById('display-total');
                const updatedEl = document.getElementById('display-updated');
                const initial = @json($state);

                function esc(s) {
                    const d = document.createElement('div');
                    d.textContent = s;
                    return d.innerHTML;
                }

                function render(state) {
                    if (!state || state.paired !== true) {
                        statusEl.className = 'mb-2 text-danger';
                        statusEl.textContent = (state && state.reason) ? state.reason : 'Pairing lost.';
                        linesEl.innerHTML = '';
                        countEl.textContent = '0 items';
                        totalEl.textContent = '0.00';
                        updatedEl.textContent = '';
                        return;
                    }
                    if (!state.lines || state.lines.length === 0) {
                        statusEl.className = 'mb-2 text-muted';
                        statusEl.textContent = 'Waiting for items…';
                        linesEl.innerHTML = '';
                    } else {
                        statusEl.className = 'mb-2';
                        statusEl.textContent = 'Items';
                        linesEl.innerHTML = state.lines.map(function (l) {
                            return '<div class="d-flex justify-content-between">' +
                                '<span>' + esc(l.name) + ' \u00d7 ' + l.qty + '</span>' +
                                '<span>' + Number(l.line_total).toFixed(2) + '</span>' +
                                '</div>';
                        }).join('');
                    }
                    countEl.textContent = state.item_count + ' items';
                    totalEl.textContent = Number(state.total).toFixed(2);
                    updatedEl.textContent = state.updated_at
                        ? ('Last update ' + new Date(state.updated_at).toLocaleTimeString())
                        : '';
                }

                render(initial);

                setInterval(function () {
                    fetch('{{ route('pos.customer-display.state') }}?code=' + encodeURIComponent(root.dataset.code), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    })
                        .then(function (r) { return r.json(); })
                        .then(render)
                        .catch(function () {
                            statusEl.className = 'mb-2 text-danger';
                            statusEl.textContent = 'Display connection lost — retrying…';
                        });
                }, 1500);
            })();
        </script>
    @endif
@endsection
