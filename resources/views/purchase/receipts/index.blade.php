@extends('layouts.app')

@section('page_title', 'Goods receipts')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Goods receipts"
        subtitle="A draft receipt is paperwork. Posting it is what moves stock, at the cost you actually paid — which is why drafting and posting are different permissions."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Purchase orders
            </a>
            @if ($perm('purchase.receipts.create'))
                <a class="btn btn-primary" href="{{ route('purchase.receipts.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New receipt
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Received this month" value="৳ {{ number_format($summary['receipts_month_value'], 2) }}" icon="bi-box-arrow-in-down" :hint="$summary['receipts_month'].' posted receipt(s)'" />
        <x-ui.kpi label="Open orders" :value="number_format($summary['open_orders'])" icon="bi-clipboard-check" hint="Waiting for goods" />
        <x-ui.kpi label="Open order value" value="৳ {{ number_format($summary['open_value'], 2) }}" icon="bi-cash-stack" hint="Committed but not received" />
        <x-ui.kpi label="Awaiting approval" :value="number_format($summary['awaiting_approval'])" icon="bi-hourglass-split" hint="Orders, not receipts" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('purchase.receipts.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Receipt code, supplier challan or supplier name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                <option value="draft" @selected($filters['status'] === 'draft')>Draft — not yet in stock</option>
                <option value="posted" @selected($filters['status'] === 'posted')>Posted — in stock</option>
                <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelled</option>
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="supplier">Supplier</label>
            <select class="form-select" id="supplier" name="supplier">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected($filters['supplier'] === $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('purchase.receipts.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @if ($filters['status'] === 'draft')
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>These receipts are not in stock yet.</strong>
                Stock, valuation layers and the order's received quantities change only when a receipt is posted.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="$receipts->total().' receipts'">
        <thead>
            <tr>
                <th>Receipt</th>
                <th>Supplier</th>
                <th>Against</th>
                <th>Warehouse</th>
                <th>Received</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($receipts as $receipt)
                <tr>
                    <td data-label="Receipt">
                        <a class="erp-row-link" href="{{ route('purchase.receipts.show', $receipt) }}">{{ $receipt->code }}</a>
                        @if ($receipt->challan_no)
                            <span class="erp-td-muted d-block small">challan {{ $receipt->challan_no }}</span>
                        @endif
                    </td>
                    <td data-label="Supplier">{{ $receipt->supplier?->name ?? 'Removed supplier' }}</td>
                    <td data-label="Against" class="erp-td-muted">
                        @if ($receipt->order)
                            <a href="{{ route('purchase.orders.show', $receipt->order) }}">{{ $receipt->order->code }}</a>
                        @else
                            Direct receipt
                        @endif
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $receipt->warehouse?->name ?? '—' }}</td>
                    <td data-label="Received">{{ $receipt->received_date?->format('d M Y') }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ $receipt->lines_count }}</td>
                    <td data-label="Value" class="erp-td-num">৳ {{ number_format((float) $receipt->total, 2) }}</td>
                    <td data-label="Status"><x-ui.status :value="$receipt->status" /></td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.receipts.show', $receipt) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty icon="bi-box-arrow-in-down" title="No goods receipts match this filter"
                                    text="A receipt can be raised against an approved order, or directly when goods arrive without paperwork." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $receipts->links() }}</div>

    <x-ui.related-pages />
@endsection
