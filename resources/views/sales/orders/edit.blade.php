@extends('layouts.app')

@section('page_title', 'Edit Order '.$order->order_no)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Edit {{ $order->order_no }}</h1>
            <p class="erp-page-sub">
                Draft/pending only — totals are re-derived on the server; no stock or GL effect.
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('sales.orders.show', $order) }}">Back</a>
    </div>

    @error('order') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <div class="erp-card" style="max-width: 1100px">
        <form method="POST" action="{{ route('sales.orders.update', $order) }}">
            @csrf
            @method('PUT')

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="customer_id">Customer</label>
                    <select class="form-select" id="customer_id" name="customer_id">
                        <option value="">— walk-in / none —</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}"
                                @selected((int) old('customer_id', $order->customer_id) === $customer->id)>
                                {{ $customer->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('customer_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id">
                        <option value="">— default —</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}"
                                @selected((int) old('warehouse_id', $order->warehouse_id) === $wh->id)>
                                {{ $wh->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="order_date">Order date</label>
                    <input type="date" class="form-control" id="order_date" name="order_date"
                        value="{{ old('order_date', $order->order_date?->toDateString()) }}">
                    @error('order_date') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>

            @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

            <div class="table-responsive mb-3">
                <table class="table erp-table" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width: 34%">Product</th>
                            <th style="width: 14%">Qty</th>
                            <th style="width: 18%">Unit price</th>
                            <th style="width: 18%">Discount</th>
                            <th style="width: 56px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->lines as $i => $line)
                            <tr class="line-row">
                                <td>
                                    <select class="form-select" name="lines[{{ $i }}][product_id]" required>
                                        <option value="">— select —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}"
                                                @selected((int) old("lines.$i.product_id", $line->product_id) === $product->id)>
                                                {{ $product->sku }} · {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0.0001" class="form-control"
                                        name="lines[{{ $i }}][qty]" required
                                        value="{{ old("lines.$i.qty", $line->qty) }}">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control"
                                        name="lines[{{ $i }}][unit_price]"
                                        value="{{ old("lines.$i.unit_price", $line->unit_price) }}">
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0" class="form-control"
                                        name="lines[{{ $i }}][discount]"
                                        value="{{ old("lines.$i.discount", $line->discount) }}">
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-line"
                                        aria-label="Remove line">&times;</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="add-line">+ Add line</button>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label" for="doc_discount">Document discount</label>
                    <input type="number" step="0.0001" min="0" class="form-control" id="doc_discount"
                        name="doc_discount" value="{{ old('doc_discount', $docDiscount) }}">
                    @error('doc_discount') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="shipping">Shipping</label>
                    <input type="number" step="0.0001" min="0" class="form-control" id="shipping"
                        name="shipping" value="{{ old('shipping', $order->shipping) }}">
                    @error('shipping') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="coupon_code">Coupon code</label>
                    <input class="form-control" id="coupon_code" name="coupon_code" maxlength="48"
                        value="{{ old('coupon_code', $order->coupon_code) }}" placeholder="optional">
                    @error('coupon_code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500"
                        value="{{ old('notes', $order->notes) }}">
                    @error('notes') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Save order</button>
                <a class="btn btn-outline-secondary" href="{{ route('sales.orders.show', $order) }}">Cancel</a>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
        (function () {
            const tbody = document.querySelector('#lines-table tbody');
            const addBtn = document.getElementById('add-line');

            function reindex() {
                tbody.querySelectorAll('.line-row').forEach(function (row, i) {
                    row.querySelectorAll('[name]').forEach(function (el) {
                        el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']');
                    });
                });
            }

            addBtn.addEventListener('click', function () {
                const clone = tbody.querySelector('.line-row').cloneNode(true);
                clone.querySelectorAll('select, input').forEach(function (el) {
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else el.value = '';
                });
                tbody.appendChild(clone);
                reindex();
            });

            tbody.addEventListener('click', function (e) {
                if (e.target.classList.contains('remove-line')) {
                    if (tbody.querySelectorAll('.line-row').length > 1) {
                        e.target.closest('tr').remove();
                        reindex();
                    }
                }
            });
        })();
    </script>
    @endpush
@endsection
