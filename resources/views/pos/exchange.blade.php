@extends('layouts.app')

@section('page_title', 'POS Exchange')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Exchange</h1>
            <p class="erp-page-sub">Counter exchange runs both legs on one document: returned stock comes back in, new stock leaves, and only the price difference settles at the drawer.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    <div class="erp-card mb-3">
        <form method="GET" action="{{ route('pos.exchange.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="invoice">POS invoice number</label>
                <input class="form-control" type="search" id="invoice" name="invoice"
                       value="{{ $search }}" placeholder="INV-…" autocomplete="off">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Find invoice</button>
            </div>
        </form>
        @if ($search !== '' && $invoice === null)
            <p class="mb-0 mt-3 text-muted">No POS invoice matches <code>{{ $search }}</code>.</p>
        @elseif ($search === '')
            <p class="mb-0 mt-3 text-muted">Look a POS invoice up by its number to exchange items against it.</p>
        @endif
    </div>

    @if ($invoice !== null)
        <div class="erp-card mb-3">
            <h2 class="erp-h2 mb-1">Items to exchange for</h2>
            <p class="text-muted small">Search by name, SKU, code or barcode, then press Use (or type the product id into the exchange form below). Prices are the server's quote.</p>
            <form method="GET" action="{{ route('pos.exchange.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="invoice" value="{{ $search }}">
                <div class="col-md-4">
                    <label class="form-label" for="q">Product search</label>
                    <input class="form-control" type="search" id="q" name="q"
                           value="{{ $q }}" placeholder="SKU / name / barcode" autocomplete="off">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-primary w-100" type="submit">Find products</button>
                </div>
            </form>
            @if ($q !== '' && $products->isEmpty())
                <p class="mb-0 mt-3 text-muted">No active product matches <code>{{ $q }}</code>.</p>
            @elseif ($q === '')
                <p class="mb-0 mt-3 text-muted">No product searched yet.</p>
            @else
                <div class="table-responsive mt-3">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>SKU</th>
                                <th>Product</th>
                                <th class="text-end">Unit price</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($products as $product)
                                <tr>
                                    <td>{{ $product['id'] }}</td>
                                    <td><code>{{ $product['sku'] }}</code></td>
                                    <td>{{ $product['name'] }}</td>
                                    <td class="text-end">{{ number_format((float) $product['unit_price'], 2) }}</td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-product-id="{{ $product['id'] }}"
                                                onclick="addExchangeLine(this.dataset.productId)">Use</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="erp-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="erp-h2 mb-1">Exchange against {{ $invoice->invoice_no }}</h2>
                    <p class="mb-0 text-muted">
                        {{ $invoice->invoice_date }} ·
                        <span class="erp-status erp-status-{{ str_replace('_', '-', strtolower((string) ($invoice->status))) }}">{{ $invoice->status }}</span> ·
                        total {{ number_format((float) $invoice->grand_total, 2) }}
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('pos.exchange') }}">
                @csrf
                <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">

                <h3 class="erp-h3">Returned from this invoice</h3>
                <div class="table-responsive mb-3">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty invoiced</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end" style="width: 10rem;">Return qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->lines as $i => $line)
                                <tr>
                                    <td>{{ $line->line_no }}</td>
                                    <td>{{ $line->product?->name ?? $line->description }}</td>
                                    <td class="text-end">{{ number_format((float) $line->qty, 4) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="text-end">
                                        <input class="form-control form-control-sm text-end" type="number"
                                               name="lines[{{ $i }}][qty]" min="0" step="any" value="0"
                                               aria-label="Return qty line {{ $line->line_no }}">
                                        <input type="hidden" name="lines[{{ $i }}][invoice_line_id]"
                                               value="{{ $line->id }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <h3 class="erp-h3">Taken in exchange</h3>
                <div class="table-responsive mb-3">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 8rem;">Product ID</th>
                                <th class="text-end" style="width: 8rem;">Qty</th>
                                <th class="text-end" style="width: 10rem;">Unit price (blank = server)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="exchange-lines-body"></tbody>
                    </table>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="payment_method">Difference method</label>
                        <select class="form-select" id="payment_method" name="payment_method">
                            <option value="cash" @selected(request('payment_method', 'cash') === 'cash')>Cash (drawer)</option>
                            <option value="bank" @selected(request('payment_method') === 'bank')>Bank</option>
                            <option value="mobile" @selected(request('payment_method') === 'mobile')>Mobile wallet</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" type="text" id="notes" name="notes" maxlength="500">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary w-100" type="submit">Process exchange</button>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Return qty 0 skips the line; quantities beyond what was invoiced are refused. The price difference settles here: positive collects, negative refunds, zero is an equal-value swap.</p>
            </form>
        </div>
    @endif

    <script>
        (function () {
            let lines = @json(collect(old('exchange_lines', []))->values()->all());

            function rowHtml(index, line) {
                return '<tr>' +
                    '<td><input class="form-control form-control-sm" type="number" min="1" step="1" ' +
                        'name="exchange_lines[' + index + '][product_id]" value="' + (line.product_id ?? '') + '" aria-label="Exchange product id"></td>' +
                    '<td class="text-end"><input class="form-control form-control-sm text-end" type="number" min="0" step="any" ' +
                        'name="exchange_lines[' + index + '][qty]" value="' + (line.qty ?? 1) + '" aria-label="Exchange qty"></td>' +
                    '<td class="text-end"><input class="form-control form-control-sm text-end" type="number" min="0" step="any" ' +
                        'name="exchange_lines[' + index + '][unit_price]" value="' + (line.unit_price ?? '') + '" placeholder="server" aria-label="Exchange unit price"></td>' +
                    '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" ' +
                        'onclick="this.closest(\'tr\').remove()">Remove</button></td>' +
                    '</tr>';
            }

            function render() {
                const body = document.getElementById('exchange-lines-body');
                if (!body) {
                    return;
                }
                if (lines.length === 0) {
                    lines.push({ product_id: '', qty: 1, unit_price: '' });
                }
                body.innerHTML = lines.map((line, index) => rowHtml(index, line)).join('');
            }

            window.addExchangeLine = function (productId) {
                const empty = lines.findIndex((line) => !line.product_id);
                if (empty >= 0) {
                    lines[empty].product_id = productId;
                } else {
                    lines.push({ product_id: productId, qty: 1, unit_price: '' });
                }
                render();
            };

            document.addEventListener('DOMContentLoaded', render);
        })();
    </script>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
