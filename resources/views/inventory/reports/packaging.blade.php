@extends('layouts.app')

@section('page_title', 'Packaging report')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reports"
        title="Packaging consumption by month"
        subtitle="Money leaves the business in cardboard too. This report answers the only two questions a packaging cost raises: did we use more, or did it get dearer? Quantity and unit cost sit on every row so the two can never be confused for each other."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.cost', request()->query()) }}">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Packaging cost
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.stock', request()->query()) }}">
                <i class="bi bi-clipboard-data" aria-hidden="true"></i> Stock report
            </a>
            <a class="btn btn-primary" href="{{ route('inventory.reports.packaging', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('report')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Cost in the period" :value="number_format($totals['cost'] ?? 0, 2)" icon="bi-cash-stack"
                  hint="Layer-valued, from the consumption rows" />
        <x-ui.kpi label="Units consumed" :value="number_format($totals['qty'] ?? 0, 4)" icon="bi-box-arrow-up"
                  hint="Across the types and months below" />
        <x-ui.kpi label="Orders packed" :value="number_format($totals['orders'] ?? 0)" icon="bi-bag-check"
                  hint="Orders that took packaging" />
        <x-ui.kpi label="Types used" :value="number_format($totals['types'] ?? 0)" icon="bi-box-seam"
                  :hint="'Across '.number_format($totals['months'] ?? 0).' month(s)'" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reports.packaging') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Packaging code or name…" autocomplete="off">
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
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'])
                <a class="btn btn-link" href="{{ route('inventory.reports.packaging') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.packaging', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> CSV
            </a>
        </div>
    </form>

    <x-ui.table-shell :count="count($rows).' month × type row(s)'" :title="'Consumption by month and type'">
        <thead>
            <tr>
                <th>Month</th>
                <th>Type</th>
                <th>Packaging</th>
                <th class="erp-th-num">Quantity</th>
                <th class="erp-th-num">Cost</th>
                <th class="erp-th-num">Avg unit cost</th>
                <th class="erp-th-num">Orders packed</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Month" class="erp-cell-strong">{{ $row['period_label'] }}</td>
                    <td data-label="Type">{{ $row['type']?->code ?? '—' }}</td>
                    <td data-label="Packaging" class="erp-td-muted">{{ $row['type']?->name ?? '—' }}</td>
                    <td data-label="Quantity" class="erp-td-num">{{ number_format($row['qty'], 4) }}</td>
                    <td data-label="Cost" class="erp-td-num erp-cell-strong">{{ number_format($row['cost'], 2) }}</td>
                    <td data-label="Avg unit cost" class="erp-td-num erp-td-muted">
                        {{ $row['unit_cost'] !== null ? number_format($row['unit_cost'], 4) : '—' }}
                    </td>
                    <td data-label="Orders packed" class="erp-td-num erp-td-muted">{{ number_format($row['orders']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-clipboard-data"
                                    title="No packaging was consumed in this period"
                                    :text="$filters['q'] !== '' || $filters['warehouse']
                                        ? 'Nothing matches this filter — try Reset or widen the dates.'
                                        : 'A month with no dispatch of packaged goods is a real answer. Widen the dates to look further back.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($months->isNotEmpty())
        <x-ui.table-shell :count="$months->count().' month(s)'" :title="'The same money, month by month'" class="mt-3">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="erp-th-num">Quantity</th>
                    <th class="erp-th-num">Cost</th>
                    <th class="erp-th-num">Avg unit cost</th>
                    <th class="erp-th-num">Orders packed</th>
                    <th class="erp-th-num">Types used</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($months as $month)
                    <tr>
                        <td data-label="Month" class="erp-cell-strong">{{ $month['label'] }}</td>
                        <td data-label="Quantity" class="erp-td-num">{{ number_format($month['qty'], 4) }}</td>
                        <td data-label="Cost" class="erp-td-num erp-cell-strong">{{ number_format($month['cost'], 2) }}</td>
                        <td data-label="Avg unit cost" class="erp-td-num erp-td-muted">
                            {{ $month['qty'] > 0 ? number_format($month['cost'] / $month['qty'], 4) : '—' }}
                        </td>
                        <td data-label="Orders packed" class="erp-td-num erp-td-muted">{{ number_format($month['orders']) }}</td>
                        <td data-label="Types used" class="erp-td-num erp-td-muted">{{ number_format($month['types']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <x-slot:footer>
                <span class="erp-td-muted">
                    A month whose cost rose while its quantity fell is a price story, not a usage story — the average
                    unit cost column is the one to read.
                </span>
            </x-slot:footer>
        </x-ui.table-shell>
    @endif

    <x-ui.related-pages />
@endsection
