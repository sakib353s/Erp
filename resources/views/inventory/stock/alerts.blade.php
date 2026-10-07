@extends('layouts.app')

@section('page_title', 'Stock alerts')

@section('content')
    @php($labels = [
        'low' => ['Low stock', 'At or below the minimum level on the policy that governs them.', 'bi-graph-down-arrow'],
        'out' => ['Out of stock', 'Nothing on hand in that warehouse — every sale is a lost sale until it arrives.', 'bi-exclamation-octagon'],
        'over' => ['Overstock', 'Above the maximum level: capital sitting on a shelf instead of in the bank.', 'bi-graph-up-arrow'],
    ])

    <x-ui.page-header
        eyebrow="Inventory · Stock"
        :title="$labels[$type][0]"
        :subtitle="$labels[$type][1]"
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">
                    <i class="bi bi-boxes" aria-hidden="true"></i> Stock overview
                </a>
            @endif
            @if ($perm('inventory.reorder.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reorder.index') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Reorder levels
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reorder.suggestions.index') }}">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Reorder desk
                </a>
            @endif
            @if ($perm('inventory.ledger.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.movements') }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Movement ledger
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        @foreach (['low' => 'Low stock', 'out' => 'Out of stock', 'over' => 'Overstock'] as $key => $label)
            <x-ui.kpi
                :label="$label"
                :value="number_format($counts[$key])"
                :icon="$labels[$key][2]"
                :hint="$type === $key ? 'Currently shown below' : 'Switch to this list'"
                :href="route('inventory.stock.alerts', array_filter(['type' => $key, 'warehouse' => $filters['warehouse'], 'q' => $filters['q']]))" />
        @endforeach
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.stock.alerts') }}" role="search">
        <input type="hidden" name="type" value="{{ $type }}">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="SKU or product name…" autocomplete="off">
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
                <a class="btn btn-link" href="{{ route('inventory.stock.alerts', ['type' => $type]) }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="count($rows).' product(s)'">
        <thead>
            <tr>
                <th>Product</th>
                <th>Warehouse</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Reserved</th>
                <th class="erp-th-num">Available</th>
                <th class="erp-th-num">Avg / day</th>
                <th class="erp-th-num">Cover</th>
                <th class="erp-th-num">Minimum</th>
                <th class="erp-th-num">Maximum</th>
                <th class="erp-th-num">{{ $type === 'over' ? 'Over by' : 'Short by' }}</th>
                <th class="erp-th-num">Suggested order</th>
                <th></th>
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
                    <td data-label="Reserved" class="erp-td-num erp-td-muted">{{ number_format($row['reserved'], 4) }}</td>
                    <td data-label="Available" class="erp-td-num">{{ number_format($row['available'], 4) }}</td>
                    <td data-label="Avg / day" class="erp-td-num">
                        @if ($row['avg_daily_demand'] > 0)
                            {{ number_format($row['avg_daily_demand'], 4) }}
                        @else
                            <span class="erp-td-muted">no movement</span>
                        @endif
                    </td>
                    <td data-label="Cover" class="erp-td-num erp-td-muted">
                        {{ $row['days_cover'] !== null ? $row['days_cover'].' d' : '—' }}
                    </td>
                    <td data-label="Minimum" class="erp-td-num erp-td-muted">{{ number_format((float) $row['policy']->min_level, 4) }}</td>
                    <td data-label="Maximum" class="erp-td-num erp-td-muted">{{ number_format((float) $row['policy']->max_level, 4) }}</td>
                    <td data-label="{{ $type === 'over' ? 'Over by' : 'Short by' }}" class="erp-td-num erp-cell-strong">
                        @if ($type === 'over')
                            {{ number_format(max(0, $row['on_hand'] - (float) $row['policy']->max_level), 4) }}
                        @else
                            {{ number_format($row['shortage'], 4) }}
                        @endif
                    </td>
                    <td data-label="Suggested order" class="erp-td-num">
                        @if ($type === 'over')
                            <span class="erp-td-muted">Nothing to order</span>
                        @else
                            {{ number_format($row['suggested_qty'], 4) }}
                        @endif
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <div class="d-flex gap-1 justify-content-end">
                            @if ($perm('inventory.ledger.view'))
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('inventory.products.ledger', $row['product']) }}">
                                    Ledger
                                </a>
                            @endif
                            @if ($perm('purchase.orders.create'))
                                <a class="btn btn-sm btn-primary"
                                   href="{{ route('purchase.orders.create', ['product' => $row['product']->id, 'qty' => $row['suggested_qty']]) }}">
                                    <i class="bi bi-cart-plus" aria-hidden="true"></i> Order
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12">
                        <x-ui.empty :icon="$labels[$type][2]"
                                    title="Nothing in this list"
                                    :text="$filters['q'] !== '' || $filters['warehouse']
                                        ? 'No product with a reorder policy matches this filter — a product without a policy is never alerted, so it may simply not be watched yet.'
                                        : 'Nothing is '.\Illuminate\Support\Str::lower($labels[$type][0]).' right now. Policies live under Reorder levels.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        Every row is judged against the policy for that product in that warehouse — a warehouse-specific rule wins over the
        company-wide one. Products without a policy are deliberately absent: an alert is only raised where somebody has said
        what "too low" means. <strong>Avg / day</strong> is what actually left this shelf over the last {{ $demandDays }} days —
        real outbound movements only, so a transfer, a write-off or a correction cannot make a product look popular — and
        <strong>Cover</strong> is how long today's stock lasts at that rate.
    </div>

    <x-ui.related-pages />
@endsection
