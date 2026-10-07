@extends('layouts.app')

@section('page_title', 'New goods receipt')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Goods receipts"
        title="New goods receipt"
        subtitle="Record what physically arrived. Posting this draft is what puts the quantity on hand at the cost you paid."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All receipts
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('receipt')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @unless ($order)
        @if ($openOrders->isNotEmpty())
            <form class="erp-inline-form" method="GET" action="{{ route('purchase.receipts.create') }}">
                <label class="form-label" for="order">Receiving against an approved order?</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="order" name="order">
                        <option value="">No order — record a direct receipt below</option>
                        @foreach ($openOrders as $open)
                            <option value="{{ $open->id }}">
                                {{ $open->code }} · {{ $open->supplier?->name }} · {{ $open->order_date?->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-outline-secondary" type="submit">Load the order lines</button>
                </div>
                <div class="erp-help mt-1">
                    Loading an order pre-fills each line with what is still outstanding, so a short delivery is
                    recorded short rather than rounded up.
                </div>
            </form>
        @endif
    @endunless

    @if ($order)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Receiving against {{ $order->code }}.</strong>
                Only the outstanding quantity can be received — over-receipt is refused with the numbers rather than silently accepted.
                Leave a line blank to receive nothing of it.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchase.receipts.store') }}">
        @csrf
        <input type="hidden" name="purchase_order_id" value="{{ $order?->id }}">

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
                            <option value="{{ $supplier->id }}" @selected((int) old('supplier_id', $order?->supplier_id ?? request('supplier')) === $supplier->id)>
                                {{ $supplier->name }} ({{ $supplier->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="warehouse_id">Received into <span class="text-danger">*</span></label>
                    <select class="form-select @error('warehouse_id') is-invalid @enderror" id="warehouse_id" name="warehouse_id" required>
                        <option value="">Choose a warehouse…</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id', $order?->warehouse_id) === $warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="received_date">Received date <span class="text-danger">*</span></label>
                    <input class="form-control @error('received_date') is-invalid @enderror" type="date" id="received_date" name="received_date"
                           value="{{ old('received_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                    @error('received_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="challan_no">Supplier challan no.</label>
                    <input class="form-control" id="challan_no" name="challan_no" maxlength="64" value="{{ old('challan_no') }}">
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>

        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">What arrived</h2>
                @if (! $order)
                    <div class="erp-card-actions">
                        <span class="erp-help">No order selected — add the lines manually.</span>
                    </div>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="erp-th-num">Ordered</th>
                            <th class="erp-th-num">Already received</th>
                            <th class="erp-th-num">Outstanding</th>
                            <th class="erp-th-num">Receiving now</th>
                            <th class="erp-th-num">Unit cost</th>
                            <th>Batch &amp; dates</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if ($order)
                            @foreach ($order->lines as $index => $line)
                                <tr>
                                    <td data-label="Item">
                                        <span class="erp-cell-strong">{{ $line->label() }}</span>
                                        <span class="erp-td-muted d-block small">{{ $line->product?->sku }}</span>
                                        <input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line->id }}">
                                        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $line->product_id }}">
                                    </td>
                                    <td data-label="Ordered" class="erp-td-num">{{ number_format((float) $line->qty_ordered, 2) }}</td>
                                    <td data-label="Already received" class="erp-td-num">{{ number_format((float) $line->qty_received, 2) }}</td>
                                    <td data-label="Outstanding" class="erp-td-num">
                                        <span class="erp-cell-strong">{{ number_format($line->outstandingQty(), 2) }}</span>
                                    </td>
                                    <td data-label="Receiving now">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                               max="{{ $line->outstandingQty() }}"
                                               name="lines[{{ $index }}][qty_received]"
                                               value="{{ old('lines.'.$index.'.qty_received', $line->outstandingQty()) }}"
                                               aria-label="Quantity receiving for {{ $line->label() }}">
                                    </td>
                                    <td data-label="Unit cost">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                               name="lines[{{ $index }}][unit_cost]"
                                               value="{{ old('lines.'.$index.'.unit_cost', $line->unit_price) }}"
                                               aria-label="Unit cost for {{ $line->label() }}">
                                    </td>
                                    <td data-label="Batch &amp; dates">
                                        <input class="form-control form-control-sm" name="lines[{{ $index }}][batch_no]" maxlength="64"
                                               value="{{ old('lines.'.$index.'.batch_no') }}" aria-label="Batch number"
                                               @if ($line->product?->track_batch) required @endif
                                               placeholder="{{ $line->product?->track_batch ? 'Required — batch-tracked' : 'Optional' }}">
                                        <div class="d-flex gap-1 mt-1">
                                            <input class="form-control form-control-sm" type="date"
                                                   name="lines[{{ $index }}][manufactured_on]"
                                                   value="{{ old('lines.'.$index.'.manufactured_on') }}"
                                                   aria-label="Manufactured on" title="Manufactured on (optional)">
                                            <input class="form-control form-control-sm" type="date"
                                                   name="lines[{{ $index }}][expires_on]"
                                                   value="{{ old('lines.'.$index.'.expires_on') }}"
                                                   aria-label="Expires on" title="Expires on (optional)">
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            @php($manual = old('lines', [['product_id' => '', 'qty_received' => 1, 'unit_cost' => 0]]))
                            @foreach ($manual as $index => $line)
                                <tr>
                                    <td data-label="Item">
                                        <select class="form-select form-select-sm" name="lines[{{ $index }}][product_id]" required>
                                            <option value="">Choose the product that arrived…</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}" @selected((int) ($line['product_id'] ?? 0) === $product->id)>
                                                    {{ $product->sku }} · {{ \Illuminate\Support\Str::limit($product->name, 42) }}@if ($product->track_batch) · batch-tracked@endif
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="erp-td-muted" colspan="3">
                                        <span class="erp-help">Direct receipt — no order to measure against.</span>
                                    </td>
                                    <td data-label="Receiving now">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0.0001"
                                               name="lines[{{ $index }}][qty_received]" value="{{ $line['qty_received'] ?? 1 }}" required>
                                    </td>
                                    <td data-label="Unit cost">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                               name="lines[{{ $index }}][unit_cost]" value="{{ $line['unit_cost'] ?? 0 }}" required>
                                    </td>
                                    <td data-label="Batch &amp; dates">
                                        <input class="form-control form-control-sm" name="lines[{{ $index }}][batch_no]" maxlength="64"
                                               value="{{ $line['batch_no'] ?? '' }}" aria-label="Batch number">
                                        <div class="d-flex gap-1 mt-1">
                                            <input class="form-control form-control-sm" type="date"
                                                   name="lines[{{ $index }}][manufactured_on]"
                                                   value="{{ $line['manufactured_on'] ?? '' }}"
                                                   aria-label="Manufactured on" title="Manufactured on (optional)">
                                            <input class="form-control form-control-sm" type="date"
                                                   name="lines[{{ $index }}][expires_on]"
                                                   value="{{ $line['expires_on'] ?? '' }}"
                                                   aria-label="Expires on" title="Expires on (optional)">
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="erp-help px-3 pb-3">
                Unit cost defaults to the ordered price. Setting the real invoice cost here is what makes weighted-average
                and FIFO valuation stay true — the layer is written with this figure when the receipt is posted.<br>
                <strong>Batch and dates.</strong> A batch-tracked product must name its batch; the expiry date on the
                box is what the expiry desk watches, and a later receipt only fills a blank in — it never overwrites a
                date somebody already recorded. Correcting a date is done on the expiry desk, with a reason.
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Save as draft
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">Cancel</a>
        </div>
    </form>

    <x-ui.related-pages />
@endsection
