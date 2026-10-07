@extends('layouts.app')

@section('page_title', 'Stock ageing')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reports"
        title="Stock ageing"
        subtitle="How long the stock on the shelf has been sitting there, dated from the last movement for that product in that warehouse — the older the row, the more capital is standing still."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.stock', request()->query()) }}">
                <i class="bi bi-clipboard-data" aria-hidden="true"></i> Stock report
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.dead-stock', request()->query()) }}">
                <i class="bi bi-hourglass-bottom" aria-hidden="true"></i> Dead stock
            </a>
            <a class="btn btn-primary" href="{{ route('inventory.reports.aging', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Products with stock" :value="number_format($totals['rows'] ?? 0)" icon="bi-boxes"
                  hint="Rows in this report" />
        <x-ui.kpi label="Stock value" :value="number_format($totals['value'] ?? 0, 2)" icon="bi-cash-stack"
                  hint="From the valuation layers, not a guess" />
        <x-ui.kpi label="Idle over 90 days" :value="number_format($buckets['90+']['rows'])"
                  :hint="'Worth '.number_format($buckets['90+']['value'], 2)" icon="bi-hourglass-bottom" />
        <x-ui.kpi label="Idle over 60 days" :value="number_format($buckets['61-90']['rows'] + $buckets['90+']['rows'])"
                  :hint="'Worth '.number_format($buckets['61-90']['value'] + $buckets['90+']['value'], 2)" icon="bi-graph-down-arrow" />
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <h2 class="erp-card-title">Age buckets</h2>
            <div class="erp-card-actions">
                <span class="erp-help">Days since the last movement of that product in that warehouse.</span>
            </div>
        </div>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Bucket</th>
                        <th class="erp-th-num">Rows</th>
                        <th class="erp-th-num">Value</th>
                        <th class="erp-th-num">Share of value</th>
                    </tr>
                </thead>
                <tbody>
                    @php($totalValue = max(0.0001, (float) ($totals['value'] ?? 0)))
                    @foreach ($buckets as $bucket => $figures)
                        <tr>
                            <td>
                                <span class="erp-cell-strong">{{ $bucket }} days</span>
                                @if ($bucket === '90+')
                                    <span class="erp-status erp-status-overdue ms-1">slowest</span>
                                @endif
                            </td>
                            <td class="erp-td-num">{{ number_format($figures['rows']) }}</td>
                            <td class="erp-td-num">{{ number_format($figures['value'], 2) }}</td>
                            <td class="erp-td-num erp-td-muted">{{ number_format($figures['value'] / $totalValue * 100, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @include('inventory.reports.partials.filters', ['action' => route('inventory.reports.aging')])

    <x-ui.table-shell :count="$rows->count().' row(s)'">
        <thead>
            <tr>
                <th>Product</th>
                <th>Warehouse</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Available</th>
                <th class="erp-th-num">Value</th>
                <th>Last movement</th>
                <th class="erp-th-num">Days idle</th>
                <th>Bucket</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $row['product']->name }}</span>
                        <span class="d-block erp-td-muted">{{ $row['product']->sku }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $row['warehouse']?->name ?? '—' }}</td>
                    <td data-label="On hand" class="erp-td-num">{{ number_format($row['on_hand'], 4) }}</td>
                    <td data-label="Available" class="erp-td-num erp-td-muted">{{ number_format($row['available'], 4) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    <td data-label="Last movement" class="erp-td-muted">
                        {{ $row['last_movement_at']?->format('d M Y') ?? 'Never moved' }}
                    </td>
                    <td data-label="Days idle" class="erp-td-num erp-cell-strong">{{ number_format($row['days_idle']) }}</td>
                    <td data-label="Bucket">
                        <x-ui.status :value="$row['bucket'] === '90+' ? 'overdue' : 'open'" :label="$row['bucket'].' days'" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-clipboard-data" title="Nothing to age"
                                    text="No product currently has stock on hand in the selected warehouse — ageing starts from real stock, so it will stay empty until goods are received." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="erp-table-opening">
                <th colspan="2">Totals</th>
                <th class="erp-th-num">{{ number_format($totals['on_hand'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['available'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['value'] ?? 0, 2) }}</th>
                <th colspan="3"></th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        A product is dated by the last time anything moved for it in that warehouse — a sale, a receipt, an adjustment,
        a transfer. Value comes from the remaining valuation layers, falling back to the standard cost for products that
        carry no layers.
    </div>

    <x-ui.related-pages />
@endsection
