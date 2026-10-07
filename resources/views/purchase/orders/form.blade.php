@extends('layouts.app')

@section('page_title', 'New purchase order')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Purchase orders"
        title="New purchase order"
        subtitle="Totals are computed from the lines — the header never accepts a figure the lines cannot reproduce."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All orders
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('order')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($suppliers->isEmpty())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>No orderable supplier exists yet.</strong>
                A supplier must be active and not blacklisted before an order can be raised against them.
                @if ($perm('suppliers.create'))
                    <a href="{{ route('suppliers.create') }}">Add the first supplier</a>.
                @endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchase.orders.store') }}">
        @csrf

        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Header</h2>
            </div>
            <div class="erp-form-grid px-3 pb-3">
                <div class="erp-form-field">
                    <label class="form-label" for="supplier_id">Supplier <span class="text-danger">*</span></label>
                    <select class="form-select @error('supplier_id') is-invalid @enderror" id="supplier_id" name="supplier_id" required>
                        <option value="">Choose a supplier…</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', request('supplier')) === $supplier->id)>
                                {{ $supplier->name }} ({{ $supplier->code }})@if ($supplier->payment_terms_days > 0) · {{ $supplier->payment_terms_days }}d @endif
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="warehouse_id">Deliver to <span class="text-danger">*</span></label>
                    <select class="form-select @error('warehouse_id') is-invalid @enderror" id="warehouse_id" name="warehouse_id" required>
                        <option value="">Choose a warehouse…</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id') === $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="order_date">Order date <span class="text-danger">*</span></label>
                    <input class="form-control @error('order_date') is-invalid @enderror" type="date" id="order_date" name="order_date"
                           value="{{ old('order_date', now()->toDateString()) }}" required>
                    @error('order_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="expected_date">Expected delivery</label>
                    <input class="form-control @error('expected_date') is-invalid @enderror" type="date" id="expected_date" name="expected_date"
                           value="{{ old('expected_date') }}">
                    @error('expected_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="reference">Supplier reference</label>
                    <input class="form-control" id="reference" name="reference" maxlength="64" value="{{ old('reference') }}"
                           placeholder="Their quotation / proforma number">
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="payment_terms">Payment terms (as agreed)</label>
                    <input class="form-control" id="payment_terms" name="payment_terms" maxlength="64" value="{{ old('payment_terms') }}"
                           placeholder="e.g. 30 days from GRN">
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>

        <div class="erp-card mb-3" data-erp-lines>
            <div class="erp-card-head">
                <h2 class="erp-card-title">Lines <span class="erp-chip erp-chip-outline" data-erp-line-count>0</span></h2>
                <div class="erp-card-actions">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-erp-add-line>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add line
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="min-width: 200px">Product</th>
                            <th style="min-width: 200px">Description</th>
                            <th class="erp-th-num">Qty</th>
                            <th class="erp-th-num">Unit price</th>
                            <th class="erp-th-num">Discount</th>
                            <th class="erp-th-num">Tax %</th>
                            <th class="erp-th-num">Line total</th>
                            <th class="erp-th-actions"></th>
                        </tr>
                    </thead>
                    <tbody data-erp-lines-body>
                        @php($oldLines = old('lines', ($suggestedLines ?? []) ?: [['description' => '', 'qty_ordered' => 1, 'unit_price' => 0, 'discount' => 0, 'tax_rate' => 0]]))
                        @foreach ($oldLines as $index => $line)
                            <tr data-erp-line>
                                <td data-label="Product">
                                    <select class="form-select form-select-sm" name="lines[{{ $index }}][product_id]">
                                        <option value="">— free-text item —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}" @selected((int) ($line['product_id'] ?? 0) === $product->id)>
                                                {{ $product->sku }} · {{ \Illuminate\Support\Str::limit($product->name, 42) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td data-label="Description">
                                    <input class="form-control form-control-sm" name="lines[{{ $index }}][description]" maxlength="191"
                                           value="{{ $line['description'] ?? '' }}" required>
                                </td>
                                <td data-label="Qty">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0.0001"
                                           name="lines[{{ $index }}][qty_ordered]" value="{{ $line['qty_ordered'] ?? 1 }}" data-erp-line-qty required>
                                </td>
                                <td data-label="Unit price">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                           name="lines[{{ $index }}][unit_price]" value="{{ $line['unit_price'] ?? 0 }}" data-erp-line-price required>
                                </td>
                                <td data-label="Discount">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                           name="lines[{{ $index }}][discount]" value="{{ $line['discount'] ?? 0 }}">
                                </td>
                                <td data-label="Tax %">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.01" min="0" max="100"
                                           name="lines[{{ $index }}][tax_rate]" value="{{ $line['tax_rate'] ?? 0 }}">
                                </td>
                                <td data-label="Line total" class="erp-td-num erp-cell-strong" data-erp-line-total>—</td>
                                <td class="erp-td-actions">
                                    <button class="btn btn-sm btn-link text-danger" type="button" data-erp-remove-line aria-label="Remove line">✕</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="erp-table-opening">
                            <th colspan="6" class="text-end">Order total (recomputed on save)</th>
                            <th class="erp-th-num" data-erp-lines-sum>—</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="erp-help px-3 pb-3">
                Discounts cannot exceed the line value, tax is applied after discount, and the service recomputes every
                figure on save — what you see here is an estimate, the stored total is authoritative.
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Save as draft
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">Cancel</a>
        </div>
    </form>

    <x-ui.related-pages />
@endsection
