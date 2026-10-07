@extends('layouts.app')

@section('page_title', 'Packaging stock')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Packaging"
        title="Packaging on the shelf"
        subtitle="The same ledger as every other item in the warehouse — a box is stock, and the only reason it is listed apart from the rest is that somebody has to remember to buy it before a busy week."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.index') }}">
                <i class="bi bi-box-seam" aria-hidden="true"></i> Packaging types
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.packaging.cost') }}">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Packaging cost
            </a>
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">
                    <i class="bi bi-boxes" aria-hidden="true"></i> All stock
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Shelves holding packaging" :value="number_format($totals['shelves'] ?? 0)" icon="bi-pin-map"
                  hint="One row per type per warehouse with a balance" />
        <x-ui.kpi label="Packaging on hand" :value="number_format($totals['on_hand'] ?? 0, 4)" icon="bi-box"
                  hint="Across the rows shown" />
        <x-ui.kpi label="Value on hand" :value="number_format($totals['value'] ?? 0, 2)" icon="bi-cash-stack"
                  hint="From the valuation layers" />
        <x-ui.kpi label="Warehouses involved" :value="number_format($totals['warehouses'] ?? 0)" icon="bi-building"
                  hint="Where packaging actually sits" />
    </div>

    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            A warehouse nobody has delivered packaging to has no row here, because the ledger has nothing to say about it —
            a row of zeros would suggest a shelf that was counted when it never was.
        </div>
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.packaging.stock') }}" role="search">
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
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'])
                <a class="btn btn-link" href="{{ route('inventory.packaging.stock') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="count($rows).' shelf/shelves'" :title="'Packaging stock'">
        <thead>
            <tr>
                <th>Type</th>
                <th>Warehouse</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Reserved</th>
                <th class="erp-th-num">Available</th>
                <th class="erp-th-num">In transit</th>
                <th class="erp-th-num">Damaged</th>
                <th class="erp-th-num">Value</th>
                <th class="erp-th-num">Avg unit cost</th>
                <th class="erp-th-num">Lasts about</th>
                <th>Last movement</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Type">
                        <span class="erp-cell-strong">{{ $row['type']->code }}</span>
                        <span class="d-block erp-td-muted">{{ $row['type']->name }}</span>
                        @unless ($row['type']->is_active)
                            <x-ui.status value="inactive" label="Retired" />
                        @endunless
                    </td>
                    <td data-label="Warehouse">{{ $row['warehouse']?->name ?? '—' }}</td>
                    <td data-label="On hand" class="erp-td-num erp-cell-strong">{{ number_format($row['on_hand'], 4) }}</td>
                    <td data-label="Reserved" class="erp-td-num erp-td-muted">{{ number_format($row['reserved'], 4) }}</td>
                    <td data-label="Available" class="erp-td-num">{{ number_format($row['available'], 4) }}</td>
                    <td data-label="In transit" class="erp-td-num erp-td-muted">{{ number_format($row['in_transit'], 4) }}</td>
                    <td data-label="Damaged" class="erp-td-num erp-td-muted">
                        {{ number_format($row['damaged'], 4) }}
                        @if ($row['damaged'] > 0)
                            <span class="d-block erp-td-muted">a crushed box is a loss, not stock</span>
                        @endif
                    </td>
                    <td data-label="Value" class="erp-td-num">{{ number_format($row['value'], 2) }}</td>
                    <td data-label="Avg unit cost" class="erp-td-num erp-td-muted">
                        {{ $row['avg_cost'] !== null ? number_format($row['avg_cost'], 4) : '—' }}
                    </td>
                    <td data-label="Lasts about" class="erp-td-num">
                        @if ($row['run_out_days'] !== null)
                            {{ number_format($row['run_out_days']) }} d
                            <span class="d-block erp-td-muted">at the last 30 days' rate</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Last movement" class="erp-td-muted">
                        {{ $row['last_moved_at'] !== null ? \Illuminate\Support\Carbon::parse($row['last_moved_at'])->format('d M Y') : '—' }}
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if ($perm('inventory.ledger.view'))
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('inventory.products.ledger', $row['product']) }}">
                                Ledger
                            </a>
                        @endif
                        @if ($perm('purchase.orders.create'))
                            <a class="btn btn-sm btn-primary"
                               href="{{ route('purchase.orders.create', ['product' => $row['product']->id]) }}">
                                <i class="bi bi-cart-plus" aria-hidden="true"></i> Order
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12">
                        <x-ui.empty icon="bi-box"
                                    title="No packaging stock yet"
                                    :text="$filters['q'] !== '' || $filters['warehouse']
                                        ? 'Nothing matches this filter — try Reset.'
                                        : 'Declare a type under Packaging types and receive it like any other stock. Until then the ledger has nothing to report, and this screen will not pretend otherwise.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            <span class="erp-td-muted">
                “Lasts about” divides on hand by the rate this type has actually been consumed over the last 30 days —
                it is a warning, not a forecast, and it stays blank until there is a rate worth dividing by.
            </span>
        </x-slot:footer>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
