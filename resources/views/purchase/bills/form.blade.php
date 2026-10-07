@extends('layouts.app')

@section('page_title', 'Enter a purchase bill')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Purchase bills"
        title="Enter a purchase bill"
        subtitle="Totals are built from the lines — this screen never sends a header figure. Approving the bill later is what posts it to accounts payable."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All bills
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('bill')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @unless ($receipt)
        @if ($billableReceipts->isNotEmpty())
            <form class="erp-inline-form" method="GET" action="{{ route('purchase.bills.create') }}">
                <label class="form-label" for="receipt">Bill a delivery we have already received?</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="receipt" name="receipt">
                        <option value="">No receipt — enter a direct bill below</option>
                        @foreach ($billableReceipts as $billable)
                            <option value="{{ $billable->id }}">
                                {{ $billable->code }} · {{ $billable->supplier?->name }} · {{ $billable->received_date?->format('d M Y') }} · ৳ {{ number_format((float) $billable->total, 2) }}
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-outline-secondary" type="submit">Load its lines</button>
                </div>
                <div class="erp-help mt-1">
                    Only posted receipts that have not been billed yet are listed, so the same delivery cannot be turned into
                    two payables.
                </div>
            </form>
        @endif
    @endunless

    @if ($receipt)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>Billing goods receipt {{ $receipt->code }}</strong> from {{ $receipt->supplier?->name }},
                received {{ $receipt->received_date?->format('d M Y') }}.
                Quantities and costs are taken from the receipt; correct them only if the supplier's paper genuinely differs —
                the difference is recorded as the three-way match result rather than hidden.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchase.bills.store') }}">
        @csrf
        <input type="hidden" name="goods_receipt_id" value="{{ $receipt?->id }}">
        @if ($receipt)
            {{-- The order comes from the receipt; the picker below is only for direct bills. --}}
            <input type="hidden" name="purchase_order_id" value="{{ $receipt->purchase_order_id }}">
        @endif

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
                            <option value="{{ $supplier->id }}"
                                    data-terms="{{ (int) $supplier->payment_terms_days }}"
                                    @selected((int) old('supplier_id', $receipt?->supplier_id) === $supplier->id)>
                                {{ $supplier->name }} ({{ $supplier->code }})@if ((int) $supplier->payment_terms_days > 0) — {{ (int) $supplier->payment_terms_days }} day terms @endif
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="supplier_bill_no">Their bill number</label>
                    <input class="form-control @error('supplier_bill_no') is-invalid @enderror" id="supplier_bill_no" name="supplier_bill_no"
                           maxlength="64" value="{{ old('supplier_bill_no') }}" placeholder="As printed on their invoice">
                    @error('supplier_bill_no')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="bill_date">Bill date <span class="text-danger">*</span></label>
                    <input class="form-control @error('bill_date') is-invalid @enderror" type="date" id="bill_date" name="bill_date"
                           value="{{ old('bill_date', now()->toDateString()) }}" required>
                    @error('bill_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="due_date">Due date</label>
                    <input class="form-control @error('due_date') is-invalid @enderror" type="date" id="due_date" name="due_date"
                           value="{{ old('due_date') }}">
                    <div class="erp-help">Left empty, the supplier's payment terms decide it — no terms means the bill is due on demand.</div>
                    @error('due_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="form-label" for="purchase_order_id">Cite an open order <span class="erp-td-muted">(optional)</span></label>
                    @if ($receipt?->purchase_order_id)
                        <input class="form-control" value="{{ $receipt->order?->code ?? ('order #'.$receipt->purchase_order_id) }}" disabled>
                        <div class="erp-help">Taken from goods receipt {{ $receipt->code }} — the bill matches against that order.</div>
                    @else
                        <select class="form-select" id="purchase_order_id" name="purchase_order_id">
                            <option value="">No order — bill the supplier directly</option>
                            @foreach ($openOrders as $open)
                                <option value="{{ $open->id }}" @selected((int) old('purchase_order_id') === $open->id)>
                                    {{ $open->code }} · {{ $open->supplier?->name }} · ৳ {{ number_format((float) $open->total, 2) }}
                                </option>
                            @endforeach
                        </select>
                        <div class="erp-help">Only approved or partially received orders are listed — a draft order has not been agreed with anybody.</div>
                    @endif
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>

        @php($rows = old('lines', $lines ?: [['description' => '', 'qty' => 1, 'unit_cost' => 0, 'tax_rate' => 0]]))

        <div class="erp-card mb-3" data-erp-lines>
            <div class="erp-card-head">
                <h2 class="erp-card-title">
                    Billed lines
                    <span class="erp-chip erp-chip-outline" data-erp-line-count>{{ count($rows) }}</span>
                </h2>
                <div class="erp-card-actions">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-erp-add-line>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a line
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="erp-th-num">Qty</th>
                            <th class="erp-th-num">Unit cost</th>
                            <th class="erp-th-num">Discount</th>
                            <th class="erp-th-num">Tax %</th>
                            <th class="erp-th-num">Line total</th>
                            <th class="erp-th-actions"></th>
                        </tr>
                    </thead>
                    <tbody data-erp-lines-body>
                        @foreach ($rows as $index => $row)
                            <tr data-erp-line>
                                <td data-label="Item">
                                    @if (! empty($row['product_id']))
                                        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $row['product_id'] }}">
                                    @endif
                                    @if (! empty($row['goods_receipt_line_id']))
                                        <input type="hidden" name="lines[{{ $index }}][goods_receipt_line_id]" value="{{ $row['goods_receipt_line_id'] }}">
                                    @endif
                                    @if (! empty($row['purchase_order_line_id']))
                                        <input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $row['purchase_order_line_id'] }}">
                                    @endif
                                    <input class="form-control form-control-sm" name="lines[{{ $index }}][description]" maxlength="500"
                                           value="{{ $row['description'] ?? '' }}" placeholder="What was billed" required>
                                </td>
                                <td data-label="Qty">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                           name="lines[{{ $index }}][qty]" value="{{ $row['qty'] ?? 1 }}"
                                           data-erp-line-qty required aria-label="Billed quantity">
                                </td>
                                <td data-label="Unit cost">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                           name="lines[{{ $index }}][unit_cost]" value="{{ $row['unit_cost'] ?? 0 }}"
                                           data-erp-line-price required aria-label="Unit cost">
                                </td>
                                <td data-label="Discount">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                           name="lines[{{ $index }}][discount]" value="{{ $row['discount'] ?? 0 }}" aria-label="Line discount">
                                </td>
                                <td data-label="Tax %">
                                    <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0" max="100"
                                           name="lines[{{ $index }}][tax_rate]" value="{{ $row['tax_rate'] ?? 0 }}" aria-label="Tax rate">
                                </td>
                                <td data-label="Line total" class="erp-td-num erp-cell-strong" data-erp-line-total>
                                    ৳ {{ number_format((float) (($row['qty'] ?? 1) * ($row['unit_cost'] ?? 0)) - (float) ($row['discount'] ?? 0), 2) }}
                                </td>
                                <td data-label="" class="erp-td-actions">
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-erp-remove-line
                                            aria-label="Remove this line">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="erp-table-opening">
                            <th colspan="5" class="text-end">Billed value before tax</th>
                            <th class="erp-th-num" data-erp-lines-sum>—</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="erp-help px-3 pb-3">
                The screen keeps a running figure honest while you type, but the server recomputes every line on save —
                the stored value is always qty × unit cost − discount + tax.
            </div>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Save as draft
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index') }}">Cancel</a>
        </div>
    </form>

    <x-ui.related-pages />
@endsection
