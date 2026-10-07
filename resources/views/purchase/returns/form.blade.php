@extends('layouts.app')

@section('page_title', 'Raise a purchase return')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Supplier returns"
        title="Raise a purchase return"
        subtitle="Record what is going back and why. Saving keeps it as a draft; approving is what takes the goods out of stock and posts the debit note against accounts payable."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.returns.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Returns
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('return')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if (! $receipt && ! $bill)
        <div class="erp-split">
            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Return against a receipt</h2>
                </div>
                @if ($returnableReceipts->isNotEmpty())
                    <form class="erp-inline-form px-3 pb-3" method="GET" action="{{ route('purchase.returns.create') }}">
                        <label class="form-label" for="receipt">Which delivery is going back?</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="receipt" name="receipt" required>
                                <option value="">Choose a posted receipt…</option>
                                @foreach ($returnableReceipts as $row)
                                    <option value="{{ $row['receipt']->id }}">
                                        {{ $row['receipt']->code }} · {{ $row['receipt']->supplier?->name }} ·
                                        {{ number_format($row['returnable'], 4) }} returnable
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-secondary" type="submit">Open</button>
                        </div>
                        <div class="erp-help mt-1">
                            Only posted receipts with something left to return are listed — the quantity already returned is
                            subtracted, so the same goods cannot go back twice.
                        </div>
                    </form>
                @else
                    <x-ui.empty icon="bi-box-arrow-in-down" title="Nothing is returnable right now"
                                text="A return needs a posted goods receipt with quantity that has not already been sent back." />
                @endif
            </div>

            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Return against a bill</h2>
                </div>
                @if ($creditableBills->isNotEmpty())
                    <form class="erp-inline-form px-3 pb-3" method="GET" action="{{ route('purchase.returns.create') }}">
                        <label class="form-label" for="bill">Which bill is being corrected?</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="bill" name="bill" required>
                                <option value="">Choose an open bill…</option>
                                @foreach ($creditableBills as $open)
                                    <option value="{{ $open->id }}">
                                        {{ $open->code }} · {{ $open->supplier?->name }} · ৳ {{ number_format((float) $open->due_amount, 2) }} outstanding
                                    </option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-secondary" type="submit">Open</button>
                        </div>
                        <div class="erp-help mt-1">
                            For a service bill, or goods billed without a receipt on file. The credit is capped at what the bill
                            still owes, so the bill and accounts payable never disagree.
                        </div>
                    </form>
                @else
                    <x-ui.empty icon="bi-receipt" title="No open bill to correct"
                                text="A bill that is fully paid or credited leaves nothing to return against — start from the receipt instead." />
                @endif
            </div>
        </div>
    @endif

    @if ($receipt || $bill)
        <form method="POST" action="{{ route('purchase.returns.store') }}">
            @csrf
            <input type="hidden" name="goods_receipt_id" value="{{ $receipt?->id }}">
            <input type="hidden" name="purchase_bill_id" value="{{ $bill?->id }}">
            @if ($receipt?->purchase_order_id)
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
                            @foreach (collect($receipt ? [$receipt->supplier] : [$bill?->supplier])->filter()->unique('id') as $preset)
                                <option value="{{ $preset->id }}" @selected((int) old('supplier_id', $preset->id) === $preset->id)>
                                    {{ $preset->name }} ({{ $preset->code }})
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="return_date">Return date <span class="text-danger">*</span></label>
                        <input class="form-control @error('return_date') is-invalid @enderror" type="date" id="return_date" name="return_date"
                               value="{{ old('return_date', now()->toDateString()) }}" required>
                        @error('return_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="reason_code">Reason <span class="text-danger">*</span></label>
                        <select class="form-select @error('reason_code') is-invalid @enderror" id="reason_code" name="reason_code" required>
                            @foreach (\App\Domain\Purchase\Models\PurchaseReturn::REASONS as $code => $label)
                                <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="erp-help">The category the supplier settlement will be argued on.</div>
                        @error('reason_code')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="warehouse_id">Goods leave from</label>
                        <select class="form-select @error('warehouse_id') is-invalid @enderror" id="warehouse_id" name="warehouse_id">
                            <option value="">No warehouse — nothing stock-managed on this return</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}"
                                        @selected((int) old('warehouse_id', $receipt?->warehouse_id) === $warehouse->id)>
                                    {{ $warehouse->name }} ({{ $warehouse->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="erp-help">Required for stock-managed lines: stock has to come out of a real shelf.</div>
                        @error('warehouse_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="reason">What happened <span class="text-danger">*</span></label>
                        <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="2" maxlength="500"
                                  placeholder="Damaged in transit, wrong size supplied, quality rejected on inspection…" required>{{ old('reason') }}</textarea>
                        @error('reason')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field erp-form-field-wide">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="goods_dispatched" name="goods_dispatched"
                                   @checked(old('goods_dispatched'))>
                            <label class="form-check-label" for="goods_dispatched">
                                The goods have already gone back with the supplier's transport
                            </label>
                            <div class="erp-help mt-1">
                                Stock leaves sellable stock when the return is approved either way — this only records whether
                                the goods are physically gone or still waiting for the supplier to collect them.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @php($rows = old('lines', $receiptLines ?: [
                ['description' => '', 'qty' => 1, 'unit_cost' => 0, 'tax_rate' => 0, 'qty_returnable' => null],
            ]))

            <div class="erp-card mb-3" data-erp-lines>
                <div class="erp-card-head">
                    <h2 class="erp-card-title">
                        Returning
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
                                <th class="erp-th-num">Received</th>
                                <th class="erp-th-num">Already returned</th>
                                <th class="erp-th-num">Returning</th>
                                <th class="erp-th-num">Unit cost</th>
                                <th class="erp-th-num">Tax %</th>
                                <th class="erp-th-num">Line value</th>
                                <th class="erp-th-actions"></th>
                            </tr>
                        </thead>
                        <tbody data-erp-lines-body>
                            @foreach ($rows as $index => $row)
                                <tr data-erp-line>
                                    <td data-label="Item">
                                        @if (! empty($row['goods_receipt_line_id']))
                                            <input type="hidden" name="lines[{{ $index }}][goods_receipt_line_id]" value="{{ $row['goods_receipt_line_id'] }}">
                                        @endif
                                        @if (! empty($row['product_id']))
                                            <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $row['product_id'] }}">
                                        @endif
                                        @if (! empty($row['batch_no']))
                                            <input type="hidden" name="lines[{{ $index }}][batch_no]" value="{{ $row['batch_no'] }}">
                                        @endif
                                        <input class="form-control form-control-sm" name="lines[{{ $index }}][description]" maxlength="191"
                                               value="{{ $row['description'] ?? '' }}" placeholder="What is going back" required>
                                        @if (! empty($row['batch_no']))
                                            <div class="erp-help">Batch {{ $row['batch_no'] }}</div>
                                        @endif
                                    </td>
                                    <td data-label="Received" class="erp-td-num erp-td-muted">
                                        {{ $row['qty_received'] !== null ? number_format((float) $row['qty_received'], 4) : '—' }}
                                    </td>
                                    <td data-label="Already returned" class="erp-td-num erp-td-muted">
                                        {{ number_format((float) ($row['qty_returned'] ?? 0), 4) }}
                                    </td>
                                    <td data-label="Returning">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                               @if ($row['qty_returnable'] !== null) max="{{ $row['qty_returnable'] }}" @endif
                                               name="lines[{{ $index }}][qty]" value="{{ $row['qty'] ?? 1 }}"
                                               data-erp-line-qty required aria-label="Returning quantity">
                                    </td>
                                    <td data-label="Unit cost">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0"
                                               name="lines[{{ $index }}][unit_cost]" value="{{ $row['unit_cost'] ?? 0 }}"
                                               data-erp-line-price required aria-label="Unit cost">
                                    </td>
                                    <td data-label="Tax %">
                                        <input class="form-control form-control-sm erp-num" type="number" step="0.0001" min="0" max="100"
                                               name="lines[{{ $index }}][tax_rate]" value="{{ $row['tax_rate'] ?? 0 }}" aria-label="Tax rate">
                                    </td>
                                    <td data-label="Line value" class="erp-td-num erp-cell-strong" data-erp-line-total>
                                        ৳ {{ number_format((float) (($row['qty'] ?? 1) * ($row['unit_cost'] ?? 0)), 2) }}
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
                                <th colspan="6" class="text-end">Return value before tax</th>
                                <th class="erp-th-num" data-erp-lines-sum>—</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="erp-help px-3 pb-3">
                    Quantities are capped against what actually arrived — returned quantity is subtracted, so the same goods
                    cannot be claimed from the supplier twice. Everything is recomputed on save.
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Save as draft
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('purchase.returns.index') }}">Cancel</a>
            </div>
        </form>
    @endif

    <x-ui.related-pages />
@endsection
