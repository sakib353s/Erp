@extends('layouts.app')

@section('page_title', $order->code)

@section('content')
    @php($outstanding = $order->outstandingQty())
    @php($receivedPct = (float) $order->lines->sum('qty_ordered') > 0
        ? (int) round((float) $order->lines->sum('qty_received') / (float) $order->lines->sum('qty_ordered') * 100)
        : 0)

    <x-ui.page-header
        eyebrow="Purchase · Purchase order"
        :title="$order->code"
        :subtitle="($order->supplier?->name ?? 'Removed supplier').' · ordered '.$order->order_date?->format('d M Y').($order->expected_date ? ' · expected '.$order->expected_date->format('d M Y') : '')"
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.receipts.create') && $order->isOpen())
                <a class="btn btn-primary" href="{{ route('purchase.receipts.create', ['order' => $order->id]) }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive goods
                </a>
            @endif
            @if ($perm('purchase.orders.create') && $order->status === 'draft')
                <form method="POST" action="{{ route('purchase.orders.submit', $order) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Submit for approval</button>
                </form>
            @endif
            @if ($perm('purchase.orders.approve') && $order->status === 'pending_approval')
                <form method="POST" action="{{ route('purchase.orders.approve', $order) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Approve</button>
                </form>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All orders
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('order')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror
    @error('cancel_reason')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror

    @if ($order->status === 'cancelled')
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <div><strong>Cancelled.</strong> {{ $order->cancel_reason }}</div>
        </div>
    @elseif ($order->status === 'pending_approval')
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong>Waiting for approval.</strong>
                Nothing may be received against this order until somebody with the approval permission signs it off —
                and whoever raised it cannot be that person.
            </div>
        </div>
    @elseif ($order->isOpen())
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                <strong>Approved and open.</strong>
                {{ number_format($outstanding, 2) }} unit(s) still outstanding across {{ $order->lines->filter(fn ($l) => $l->outstandingQty() > 0)->count() }} line(s).
            </div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Order value" value="৳ {{ number_format((float) $order->total, 2) }}" icon="bi-cash-stack" hint="Lines minus discounts plus tax" />
        <x-ui.kpi label="Received" :value="$receivedPct.'%'" icon="bi-box-arrow-in-down" hint="By quantity, from posted receipts" />
        <x-ui.kpi label="Outstanding" :value="number_format($outstanding, 2)" icon="bi-hourglass" hint="Units still to arrive" />
        <x-ui.kpi label="Receipts" :value="number_format($order->receipts->count())" icon="bi-clipboard-data" hint="Drafts and posted" />
    </div>

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Lines</h2>
                <span class="erp-chip erp-chip-outline">{{ $order->lines->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="erp-th-num">Ordered</th>
                            <th class="erp-th-num">Received</th>
                            <th class="erp-th-num">Short</th>
                            <th class="erp-th-num">Unit price</th>
                            <th class="erp-th-num">Tax</th>
                            <th class="erp-th-num">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->lines as $line)
                            <tr>
                                <td data-label="Item">
                                    <span class="erp-cell-strong">{{ $line->product?->name ?? $line->description }}</span>
                                    <span class="erp-td-muted d-block small">
                                        {{ $line->product?->sku }}{{ $line->product ? ' · '.$line->description : '' }}
                                    </span>
                                </td>
                                <td data-label="Ordered" class="erp-td-num">{{ number_format((float) $line->qty_ordered, 2) }}</td>
                                <td data-label="Received" class="erp-td-num">{{ number_format((float) $line->qty_received, 2) }}</td>
                                <td data-label="Short" class="erp-td-num">
                                    @if ($line->outstandingQty() > 0)
                                        <span class="erp-amount">{{ number_format($line->outstandingQty(), 2) }}</span>
                                    @else
                                        <span class="erp-td-muted">—</span>
                                    @endif
                                </td>
                                <td data-label="Unit price" class="erp-td-num">৳ {{ number_format((float) $line->unit_price, 2) }}</td>
                                <td data-label="Tax" class="erp-td-num">{{ (float) $line->tax_rate }}%</td>
                                <td data-label="Line total" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $line->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="erp-table-opening">
                            <th colspan="6" class="text-end">Subtotal · discount · tax</th>
                            <th class="erp-th-num">
                                ৳ {{ number_format((float) $order->subtotal, 2) }} − {{ number_format((float) $order->discount_total, 2) }} + {{ number_format((float) $order->tax_total, 2) }}
                            </th>
                        </tr>
                        <tr>
                            <th colspan="6" class="text-end">Order total</th>
                            <th class="erp-th-num">৳ {{ number_format((float) $order->total, 2) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Document</h2>
            </div>
            <dl class="erp-dl erp-dl-tight px-3">
                <dt>Status</dt>
                <dd><x-ui.status :value="$order->status" /></dd>
                <dt>Supplier</dt>
                <dd><a href="{{ route('suppliers.show', $order->supplier) }}">{{ $order->supplier?->name }}</a></dd>
                <dt>Deliver to</dt>
                <dd>{{ $order->warehouse?->name ?? '—' }} <span class="erp-td-muted">· {{ $order->branch?->name }}</span></dd>
                <dt>Supplier reference</dt>
                <dd>{{ $order->reference ?: '—' }}</dd>
                <dt>Payment terms</dt>
                <dd>{{ $order->payment_terms ?: ($order->supplier?->payment_terms_days > 0 ? $order->supplier->payment_terms_days.' days' : 'Cash') }}</dd>
                <dt>Raised by</dt>
                <dd>{{ $order->creator?->name ?? '—' }}</dd>
                <dt>Approved</dt>
                <dd>
                    @if ($order->approved_at)
                        {{ $order->approved_at->format('d M Y H:i') }}
                        <span class="erp-td-muted d-block small">{{ $order->approver?->name }}</span>
                    @else
                        <span class="erp-td-muted">Not approved yet</span>
                    @endif
                </dd>
                @if ($order->notes)
                    <dt>Notes</dt>
                    <dd>{{ $order->notes }}</dd>
                @endif
            </dl>

            <div class="erp-card-head">
                <h2 class="erp-card-title">Receipts against this order</h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="erp-th-num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->receipts as $receipt)
                            <tr>
                                <td data-label="Receipt"><a class="erp-row-link" href="{{ route('purchase.receipts.show', $receipt) }}">{{ $receipt->code }}</a></td>
                                <td data-label="Date" class="erp-td-muted">{{ $receipt->received_date->format('d M Y') }}</td>
                                <td data-label="Status"><x-ui.status :value="$receipt->status" /></td>
                                <td data-label="Value" class="erp-td-num">৳ {{ number_format((float) $receipt->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty icon="bi-box" title="Nothing received yet"
                                                text="Posting a goods receipt is what puts stock on the shelves." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($perm('purchase.orders.cancel') && ! in_array($order->status, ['received', 'cancelled'], true))
                <div class="erp-inline-form mx-3 mb-3">
                    <form method="POST" action="{{ route('purchase.orders.cancel', $order) }}">
                        @csrf
                        <label class="form-label" for="cancel_reason">Cancel this order</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" id="cancel_reason" name="cancel_reason" maxlength="500" required
                                   placeholder="Why — recorded in the audit trail">
                            <button class="btn btn-outline-danger" type="submit">Cancel order</button>
                        </div>
                        <div class="erp-help mt-1">Once anything has been received the order cannot be cancelled.</div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <x-ui.related-pages />
@endsection
