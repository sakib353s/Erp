@extends('layouts.app')

@section('page_title', 'Packaging cost')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Packaging"
        title="What packaging costs"
        subtitle="Two different numbers, shown apart on purpose: what is on the shelf is worth, and what actually went out cost. The shelf may hold last year's boxes; the dispatch desk takes today's, and neither figure is a price list."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.index') }}">
                <i class="bi bi-box-seam" aria-hidden="true"></i> Packaging types
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.stock') }}">
                <i class="bi bi-boxes" aria-hidden="true"></i> Packaging stock
            </a>
            @if ($perm('inventory.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.packaging', request()->query()) }}">
                    <i class="bi bi-clipboard-data" aria-hidden="true"></i> Monthly report
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Consumed in the window" :value="number_format($totals['consumed_qty'] ?? 0, 4)"
                  icon="bi-box-arrow-up" :hint="'Over the last '.($totals['days'] ?? 0).' days'" />
        <x-ui.kpi label="Cost of what went out" :value="number_format($totals['consumed_cost'] ?? 0, 2)"
                  icon="bi-cash-stack" hint="Priced by the valuation layers, not typed in" />
        <x-ui.kpi label="Value still on the shelf" :value="number_format($totals['stock_value'] ?? 0, 2)"
                  icon="bi-safe" hint="Remaining layers across every warehouse" />
        <x-ui.kpi label="Types with cost" :value="number_format($totals['types'] ?? 0)"
                  icon="bi-box-seam" hint="Declared types in this company" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.packaging.cost') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Code, name or product…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="warehouse">Warehouse</label>
            <select class="form-select" id="warehouse" name="warehouse">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse'] === $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="days">Window (days)</label>
            <input class="form-control erp-num" type="number" min="1" max="730" id="days" name="days"
                   value="{{ $filters['days'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'] || $filters['days'] !== 90)
                <a class="btn btn-link" href="{{ route('inventory.packaging.cost') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="count($rows).' type(s)'" :title="'Cost by type'">
        <thead>
            <tr>
                <th>Type</th>
                <th>Product</th>
                <th class="erp-th-num">Consumed</th>
                <th class="erp-th-num">Cost consumed</th>
                <th class="erp-th-num">Consumed unit cost</th>
                <th class="erp-th-num">Orders packed</th>
                <th class="erp-th-num">Value on shelf</th>
                <th>Last consumption</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Type">
                        <span class="erp-cell-strong">{{ $row['type']->code }}</span>
                        <span class="d-block erp-td-muted">{{ $row['type']->name }}</span>
                    </td>
                    <td data-label="Product" class="erp-td-muted">
                        {{ $row['product']?->name ?? '—' }}
                        <span class="d-block erp-td-muted">{{ $row['product']?->sku }}</span>
                    </td>
                    <td data-label="Consumed" class="erp-td-num">{{ number_format($row['consumed_qty'], 4) }}</td>
                    <td data-label="Cost consumed" class="erp-td-num erp-cell-strong">{{ number_format($row['consumed_cost'], 2) }}</td>
                    <td data-label="Consumed unit cost" class="erp-td-num">
                        {{ $row['consumed_unit_cost'] !== null ? number_format($row['consumed_unit_cost'], 4) : '—' }}
                        @if ($row['last_unit_cost'] !== null)
                            <span class="d-block erp-td-muted">last was {{ number_format($row['last_unit_cost'], 4) }}</span>
                        @endif
                    </td>
                    <td data-label="Orders packed" class="erp-td-num erp-td-muted">{{ number_format($row['consumed_orders']) }}</td>
                    <td data-label="Value on shelf" class="erp-td-num">{{ number_format($row['stock_value'], 2) }}</td>
                    <td data-label="Last consumption" class="erp-td-muted">
                        @if ($row['last_consumed_at'] !== null)
                            {{ \Illuminate\Support\Carbon::parse($row['last_consumed_at'])->format('d M Y') }}
                            @if ($row['last_order_no'])
                                <span class="d-block erp-td-muted">{{ $row['last_order_no'] }}</span>
                            @endif
                        @else
                            never
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-cash-stack"
                                    title="Nothing has been consumed in this window"
                                    :text="$filters['q'] !== '' || $filters['warehouse']
                                        ? 'No consumption matches this filter — try Reset, or widen the window.'
                                        : 'Packaging is consumed at dispatch against an order. Until an order is packed, there is no cost to show — and this screen will not invent one from the price the product was bought at.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            <span class="erp-td-muted">
                “Consumed unit cost” is what was actually paid for what went out: the layer cost at the moment of each
                consumption, averaged. It will differ from the shelf's own average whenever prices moved between buying
                and shipping — which is the point of showing both.
            </span>
        </x-slot:footer>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
