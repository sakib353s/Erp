@extends('layouts.app')

@section('page_title', 'Stock report')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reports"
        title="Stock report"
        subtitle="What is on hand, what is promised to orders, and what the shelf is worth — per product and warehouse, straight from the balance cache that replays the movement ledger."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.aging', request()->query()) }}">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i> Ageing
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.dead-stock', request()->query()) }}">
                <i class="bi bi-hourglass-bottom" aria-hidden="true"></i> Dead stock
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.stock', request()->query()) }}">
                <i class="bi bi-boxes" aria-hidden="true"></i> Stock overview
            </a>
            <a class="btn btn-primary" href="{{ route('inventory.reports.stock', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Stock value" :value="number_format($totals['value'] ?? 0, 2)" icon="bi-cash-stack"
                  hint="Remaining valuation layers" />
        <x-ui.kpi label="On hand" :value="number_format($totals['on_hand'] ?? 0, 4)" icon="bi-box-seam"
                  hint="Across the rows below" />
        <x-ui.kpi label="Promised to orders" :value="number_format($totals['reserved'] ?? 0, 4)" icon="bi-lock"
                  hint="Reserved, not available" />
        <x-ui.kpi label="In transit / damaged" :value="number_format($totals['in_transit'] ?? 0, 4).' / '.number_format($totals['damaged'] ?? 0, 4)"
                  icon="bi-truck" hint="On the road, or written down in place" />
    </div>

    @include('inventory.reports.partials.filters', ['action' => route('inventory.reports.stock')])

    <x-ui.table-shell :count="$rows->count().' row(s)'">
        <thead>
            <tr>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Cost method</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Reserved</th>
                <th class="erp-th-num">Available</th>
                <th class="erp-th-num">In transit</th>
                <th class="erp-th-num">Damaged</th>
                <th class="erp-th-num">Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Product">
                        @if ($perm('inventory.ledger.view'))
                            <a class="erp-row-link" href="{{ route('inventory.products.ledger', $row['product']) }}">
                                {{ $row['product']->name }}
                            </a>
                        @else
                            <span class="erp-cell-strong">{{ $row['product']->name }}</span>
                        @endif
                        <span class="d-block erp-td-muted">{{ $row['product']->sku }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $row['warehouse']?->name ?? '—' }}</td>
                    <td data-label="Cost method">
                        <span class="erp-chip erp-chip-outline">{{ strtoupper((string) $row['product']->cost_method) }}</span>
                    </td>
                    <td data-label="On hand" class="erp-td-num">{{ number_format($row['on_hand'], 4) }}</td>
                    <td data-label="Reserved" class="erp-td-num erp-td-muted">{{ number_format($row['reserved'], 4) }}</td>
                    <td data-label="Available" class="erp-td-num erp-cell-strong">{{ number_format($row['available'], 4) }}</td>
                    <td data-label="In transit" class="erp-td-num erp-td-muted">{{ number_format($row['in_transit'], 4) }}</td>
                    <td data-label="Damaged" class="erp-td-num erp-td-muted">{{ number_format($row['damaged'], 4) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty icon="bi-clipboard-data" title="No stock in this selection"
                                    text="Nothing is on hand for the filters you chose. Clear them, or receive goods in — the report only shows real balances." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="erp-table-opening">
                <th colspan="3">Totals</th>
                <th class="erp-th-num">{{ number_format($totals['on_hand'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['reserved'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['available'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['in_transit'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['damaged'] ?? 0, 4) }}</th>
                <th class="erp-th-num">{{ number_format($totals['value'] ?? 0, 2) }}</th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        The value column is the sum of the remaining valuation layers for that product in that warehouse (FIFO / LIFO /
        weighted average / standard, whichever the product is set to); when a product carries no layers the standard cost
        stands in, and the cost method column says which rule was used.
    </div>

    <x-ui.related-pages />
@endsection
