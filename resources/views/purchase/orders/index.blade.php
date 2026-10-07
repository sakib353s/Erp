@extends('layouts.app')

@section('page_title', 'Purchase orders')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Purchase orders"
        subtitle="What you have committed to buy. Approval is a separate permission from raising, and nobody approves their own order."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Goods receipts
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.index') }}">
                <i class="bi bi-truck" aria-hidden="true"></i> Suppliers
            </a>
            @if ($perm('purchase.orders.create'))
                <a class="btn btn-primary" href="{{ route('purchase.orders.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New purchase order
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Open orders" :value="number_format($summary['open_orders'])" icon="bi-clipboard-check" hint="Approved, goods outstanding" />
        <x-ui.kpi label="Open value" value="৳ {{ number_format($summary['open_value'], 2) }}" icon="bi-cash-stack" hint="Committed, not yet received" />
        <x-ui.kpi label="Awaiting approval" :value="number_format($summary['awaiting_approval'])" icon="bi-hourglass-split" hint="Submitted by somebody else" />
        <x-ui.kpi label="Receipts this month" value="৳ {{ number_format($summary['receipts_month_value'], 2) }}" icon="bi-box-arrow-in-down" :hint="$summary['receipts_month'].' posted receipt(s)'" />
    </div>

    @php($spark = array_values($sparkline))
    @php($sparkMax = max(1, max($spark)))
    <div class="erp-card erp-card-tight mb-3">
        <div class="erp-card-head">
            <h2 class="erp-card-title">Received value · last 14 days</h2>
            <span class="erp-chip erp-chip-outline">Peak ৳ {{ number_format($sparkMax, 0) }}</span>
        </div>
        <div class="erp-spark" role="img" aria-label="Posted receipt value per day, last 14 days">
            @foreach ($spark as $index => $value)
                <span class="erp-spark-bar" style="height: {{ max(3, (int) round($value / $sparkMax * 100)) }}%"
                      title="{{ array_keys($sparkline)[$index] }}: ৳ {{ number_format($value, 2) }}"></span>
            @endforeach
        </div>
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('purchase.orders.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Order code, supplier reference or supplier name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                <option value="open" @selected($filters['status'] === 'open')>Open (approved, awaiting goods)</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                @endforeach
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
        <div class="erp-filter">
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <option value="">Newest first</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                <option value="value" @selected($filters['sort'] === 'value')>Highest value</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('purchase.orders.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$orders->total().' orders'">
        <thead>
            <tr>
                <th>Order</th>
                <th>Supplier</th>
                <th>Warehouse</th>
                <th>Dates</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                <tr>
                    <td data-label="Order">
                        <a class="erp-row-link" href="{{ route('purchase.orders.show', $order) }}">{{ $order->code }}</a>
                        @if ($order->reference)
                            <span class="erp-td-muted d-block small">ref {{ $order->reference }}</span>
                        @endif
                    </td>
                    <td data-label="Supplier">
                        {{ $order->supplier?->name ?? 'Removed supplier' }}
                        <span class="erp-td-muted d-block small">{{ $order->branch?->name }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $order->warehouse?->name ?? '—' }}</td>
                    <td data-label="Dates">
                        {{ $order->order_date?->format('d M Y') }}
                        @if ($order->expected_date)
                            <span class="erp-td-muted d-block small">due {{ $order->expected_date->format('d M Y') }}</span>
                        @endif
                    </td>
                    <td data-label="Lines" class="erp-td-num">{{ $order->lines_count }}</td>
                    <td data-label="Value" class="erp-td-num">৳ {{ number_format((float) $order->total, 2) }}</td>
                    <td data-label="Status"><x-ui.status :value="$order->status" /></td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.orders.show', $order) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-clipboard-check" title="No purchase orders match this filter"
                                    text="Raise the first order, or clear a filter." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $orders->links() }}</div>

    <x-ui.related-pages />
@endsection
