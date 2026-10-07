@extends('layouts.app')

@section('page_title', 'Stock reservations')

@section('content')
    @php($statusLabels = [
        'open' => 'Open holds',
        'overdue' => 'Past their deadline',
        'released' => 'Released',
        'consumed' => 'Consumed (issued)',
        'expired' => 'Expired',
        'all' => 'Every hold',
    ])

    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Stock reservations"
        subtitle="What the order desk has promised away. A hold does not move stock — it takes quantity out of available, so the shelf is not sold twice."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.stock.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">
                    <i class="bi bi-boxes" aria-hidden="true"></i> Stock overview
                </a>
            @endif
            @if ($perm('inventory.ledger.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.movements') }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Movement ledger
                </a>
            @endif
            @if ($perm('inventory.reservations.manage') && $counts['overdue'] > 0)
                <form method="POST" action="{{ route('inventory.reservations.expire') }}"
                      onsubmit="return confirm('Release every hold whose deadline has passed? The quantity becomes available again and the release is audited.');">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        Release {{ $counts['overdue'] }} overdue hold(s)
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @error('reservation')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Open holds" :value="number_format($counts['open'])" icon="bi-lock"
                  hint="Still holding stock for an order" />
        <x-ui.kpi label="Quantity held" :value="number_format($counts['qty_held'], 4)" icon="bi-box-seam"
                  hint="Removed from available, not from on hand" />
        <x-ui.kpi label="Past deadline" :value="number_format($counts['overdue'])" icon="bi-hourglass-split"
                  hint="These give stock back when expired" />
        <x-ui.kpi label="Released / expired" :value="number_format($counts['released']).' / '.number_format($counts['expired'])"
                  icon="bi-arrow-counterclockwise" hint="History of holds that let go" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reservations.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="SKU or product name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                @foreach ($statusLabels as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
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
            <label class="form-label" for="source">Held by</label>
            <select class="form-select" id="source" name="source">
                <option value="">Any document</option>
                @foreach (\App\Domain\Sales\StockReservation::SOURCE_LABELS as $key => $label)
                    <option value="{{ $key }}" @selected($filters['source'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'] || $filters['source'])
                <a class="btn btn-link" href="{{ route('inventory.reservations.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$holds->count().' hold(s)'">
        <thead>
            <tr>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Held by</th>
                <th class="erp-th-num">Quantity</th>
                <th>Deadline</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($holds as $hold)
                <tr>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $hold->product?->name ?? 'Removed product' }}</span>
                        <span class="d-block erp-td-muted">{{ $hold->product?->sku }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $hold->warehouse?->name ?? '—' }}</td>
                    <td data-label="Held by">
                        {{ $hold->sourceKind() }}
                        <span class="d-block erp-td-muted">
                            {{ $documents[$hold->source_id] ?? '#'.$hold->source_id }}
                        </span>
                    </td>
                    <td data-label="Quantity" class="erp-td-num erp-cell-strong">{{ number_format((float) $hold->qty, 4) }}</td>
                    <td data-label="Deadline" class="erp-td-muted">
                        {{ $hold->expires_at?->format('d M Y H:i') ?? 'No deadline' }}
                    </td>
                    <td data-label="Status">
                        @if ($hold->isOverdue())
                            <x-ui.status value="overdue" label="Past deadline" />
                        @else
                            <x-ui.status :value="$hold->status" />
                        @endif
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if ($hold->status === \App\Domain\Sales\StockReservation::STATUS_ACTIVE)
                            @if ($perm('inventory.reservations.manage'))
                                <form class="erp-inline-form" method="POST"
                                      action="{{ route('inventory.reservations.release', $hold) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="Released from the reservations desk — the order is not going ahead.">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                        <i class="bi bi-unlock" aria-hidden="true"></i> Release
                                    </button>
                                </form>
                            @else
                                <span class="erp-td-muted">Active</span>
                            @endif
                        @else
                            <span class="erp-td-muted">Settled</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-lock"
                                    :title="'No holds in “'.($statusLabels[$filters['status']] ?? 'this list').'”'"
                                    text="Reservations are created when a sales order is confirmed or a POS order is held — this screen only ever shows real ones, never sample rows." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        A hold reduces available quantity, never on-hand: the goods are still on the shelf and still in the valuation, they
        are simply spoken for. Consuming a hold happens when stock is actually issued — releasing one puts the quantity
        straight back into what the next customer can be sold.
    </div>

    <x-ui.related-pages />
@endsection
