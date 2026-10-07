@extends('layouts.app')

@section('page_title', $receipt->code)

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Goods receipt"
        :title="$receipt->code"
        :subtitle="($receipt->supplier?->name ?? 'Removed supplier').' · received '.$receipt->received_date?->format('d M Y').($receipt->challan_no ? ' · challan '.$receipt->challan_no : '')"
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.receipts.post') && $receipt->isDraft())
                <form method="POST" action="{{ route('purchase.receipts.post', $receipt) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Post to stock
                    </button>
                </form>
            @endif
            @if ($receipt->order)
                <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.show', $receipt->order) }}">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> {{ $receipt->order->code }}
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All receipts
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('receipt')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror
    @error('cancel_reason')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror

    @if ($receipt->isDraft())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>Draft — nothing has moved yet.</strong>
                Stock, valuation layers and {{ $receipt->order?->code ?? 'the order' }} stay untouched until this receipt is posted.
                Whoever posts it needs the posting permission.
            </div>
        </div>
    @elseif ($receipt->isPosted())
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                <strong>Posted {{ $receipt->posted_at?->format('d M Y H:i') }}.</strong>
                Stock movements were written for every line at the cost below, and the linked order's received quantities were advanced.
                A posted receipt is immutable — a mistake is corrected with a purchase return, not an edit.
            </div>
        </div>
    @else
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <div><strong>Cancelled.</strong> {{ $receipt->cancel_reason }}</div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Receipt value" value="৳ {{ number_format((float) $receipt->total, 2) }}" icon="bi-cash-stack" hint="Quantity × unit cost" />
        <x-ui.kpi label="Lines" :value="number_format($receipt->lines->count())" icon="bi-list-ol" hint="Items in this delivery" />
        <x-ui.kpi label="Units" :value="number_format((float) $receipt->lines->sum('qty_received'), 2)" icon="bi-boxes" hint="Total quantity received" />
        <x-ui.kpi label="Into" :value="$receipt->warehouse?->name ?? '—'" icon="bi-building" :hint="$receipt->branch?->name" />
    </div>

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Received items</h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="erp-th-num">Quantity</th>
                            <th class="erp-th-num">Unit cost</th>
                            <th class="erp-th-num">Line value</th>
                            <th>Batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipt->lines as $line)
                            <tr>
                                <td data-label="Product">
                                    <span class="erp-cell-strong">{{ $line->product?->name ?? 'Removed product' }}</span>
                                    <span class="erp-td-muted d-block small">{{ $line->product?->sku }}</span>
                                </td>
                                <td data-label="Quantity" class="erp-td-num">{{ number_format((float) $line->qty_received, 4) }}</td>
                                <td data-label="Unit cost" class="erp-td-num">৳ {{ number_format((float) $line->unit_cost, 4) }}</td>
                                <td data-label="Line value" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $line->line_total, 2) }}</td>
                                <td data-label="Batch" class="erp-td-muted">
                                    {{ $line->batch_no ?: '—' }}
                                    @if ($line->manufactured_on || $line->expires_on)
                                        <span class="d-block small">
                                            @if ($line->manufactured_on) made {{ $line->manufactured_on->format('d M Y') }}@endif
                                            @if ($line->manufactured_on && $line->expires_on) · @endif
                                            @if ($line->expires_on) expires {{ $line->expires_on->format('d M Y') }}@endif
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="erp-table-opening">
                            <th colspan="3" class="text-end">Total</th>
                            <th class="erp-th-num">৳ {{ number_format((float) $receipt->total, 2) }}</th>
                            <th></th>
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
                <dd><x-ui.status :value="$receipt->status" /></dd>
                <dt>Supplier</dt>
                <dd><a href="{{ route('suppliers.show', $receipt->supplier) }}">{{ $receipt->supplier?->name }}</a></dd>
                <dt>Against order</dt>
                <dd>
                    @if ($receipt->order)
                        <a href="{{ route('purchase.orders.show', $receipt->order) }}">{{ $receipt->order->code }}</a>
                        <span class="erp-td-muted d-block small">status {{ str_replace('_', ' ', $receipt->order->status) }}</span>
                    @else
                        Direct receipt — no purchase order
                    @endif
                </dd>
                <dt>Warehouse</dt>
                <dd>{{ $receipt->warehouse?->name ?? '—' }} <span class="erp-td-muted">· {{ $receipt->branch?->name }}</span></dd>
                <dt>Challan no.</dt>
                <dd>{{ $receipt->challan_no ?: '—' }}</dd>
                <dt>Received by</dt>
                <dd>{{ $receipt->receiver?->name ?? '—' }}</dd>
                <dt>Posted</dt>
                <dd>
                    @if ($receipt->posted_at)
                        {{ $receipt->posted_at->format('d M Y H:i') }}
                        <span class="erp-td-muted d-block small">{{ $receipt->poster?->name }}</span>
                    @else
                        <span class="erp-td-muted">Not posted yet</span>
                    @endif
                </dd>
                @if ($receipt->notes)
                    <dt>Notes</dt>
                    <dd>{{ $receipt->notes }}</dd>
                @endif
            </dl>

            @if ($perm('purchase.receipts.cancel') && $receipt->isDraft())
                <div class="erp-inline-form mx-3 mb-3">
                    <form method="POST" action="{{ route('purchase.receipts.cancel', $receipt) }}">
                        @csrf
                        <label class="form-label" for="cancel_reason">Cancel this draft</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" id="cancel_reason" name="cancel_reason" maxlength="500" required
                                   placeholder="Why — recorded in the audit trail">
                            <button class="btn btn-outline-danger" type="submit">Cancel</button>
                        </div>
                        <div class="erp-help mt-1">Only a draft can be cancelled; once posted, the way back is a purchase return.</div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <x-ui.related-pages />
@endsection
