@extends('layouts.app')

@section('page_title', 'Dead stock')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reports"
        title="Dead stock"
        subtitle="Stock that has stopped moving. The threshold is a setting, not a hard-coded guess — and the list says which threshold produced it."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.aging', request()->query()) }}">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i> Stock ageing
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.stock', request()->query()) }}">
                <i class="bi bi-clipboard-data" aria-hidden="true"></i> Stock report
            </a>
            <a class="btn btn-primary" href="{{ route('inventory.reports.dead-stock', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            A product counts as dead when nothing has moved for it in that warehouse for <strong>{{ number_format($threshold) }} days</strong>
            or more, and there is still stock on hand. The threshold lives in Inventory settings (<code>dead_stock_days</code>).
        </div>
    </div>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Dead stock rows" :value="number_format($totals['rows'] ?? 0)" icon="bi-hourglass-bottom"
                  hint="Products idle past the threshold" />
        <x-ui.kpi label="Capital tied up" :value="number_format($totals['value'] ?? 0, 2)" icon="bi-cash-stack"
                  hint="Value from the valuation layers" />
        <x-ui.kpi label="Quantity idle" :value="number_format($totals['on_hand'] ?? 0, 4)" icon="bi-box-seam"
                  hint="On hand in those rows" />
        <x-ui.kpi label="Threshold in force" :value="number_format($threshold).' days'" icon="bi-sliders"
                  hint="From Inventory settings" />
    </div>

    @include('inventory.reports.partials.filters', ['action' => route('inventory.reports.dead-stock'), 'showDays' => true])

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
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-check2-circle" title="Nothing has gone dead"
                                    text="No product with stock on hand has been idle for the threshold in force. This is the good outcome — the screen is not hiding anything." />
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
                <th colspan="2"></th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        Dead stock is a report, never an automatic write-off: deciding to discount, return or scrap the goods is a
        write-off decision with its own permission and approval, and it posts its own entry.
    </div>

    <x-ui.related-pages />
@endsection
