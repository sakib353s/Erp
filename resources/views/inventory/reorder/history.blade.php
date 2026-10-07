@extends('layouts.app')

@section('page_title', 'Reorder history')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reorder"
        title="What was proposed, and what was decided"
        subtitle="Every proposal keeps the figures it was judged by — the window, the average day, the cover, what was already on order — and the answer somebody gave it. A dismissal is a decision too: it stays here with its reason rather than disappearing."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.reorder.suggestions.index') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Reorder desk
            </a>
            @if ($perm('settings.view'))
                <a class="btn btn-outline-secondary" href="{{ route('settings.show', 'reorder') }}">
                    <i class="bi bi-gear" aria-hidden="true"></i> Settings
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Drafted into orders"
            :value="number_format($stats['accepted'])"
            icon="bi-cart-check"
            hint="Answered by raising a purchase order" />
        <x-ui.kpi
            label="Dismissed"
            :value="number_format($stats['dismissed'])"
            icon="bi-hand-thumbs-down"
            hint="Answered with a reason" />
        <x-ui.kpi
            label="Superseded"
            :value="number_format($stats['superseded'])"
            icon="bi-arrow-repeat"
            hint="A later look proposed a new figure" />
        <x-ui.kpi
            label="Still waiting"
            :value="number_format($stats['open'])"
            icon="bi-clipboard"
            hint="On the desk now" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reorder.history') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="SKU or product name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Decision</label>
            <select class="form-select" id="status" name="status">
                <option value="">Every decision</option>
                @foreach ($statuses as $key => $label)
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
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['warehouse'] || $filters['status'] !== '')
                <a class="btn btn-link" href="{{ route('inventory.reorder.history') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$suggestions->total().' answered proposal(s)'">
        <thead>
            <tr>
                <th>Proposal</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th>Decision</th>
                <th class="erp-th-num">Proposed</th>
                <th class="erp-th-num">Avg / day</th>
                <th class="erp-th-num">Cover then</th>
                <th>Why the figure</th>
                <th>Outcome</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suggestions as $suggestion)
                <tr>
                    <td data-label="Proposal">
                        <span class="erp-cell-strong">{{ $suggestion->code }}</span>
                        <span class="d-block erp-td-muted">{{ $suggestion->run_date?->format('d M Y') }}</span>
                    </td>
                    <td data-label="Product">
                        {{ $suggestion->product?->name }}
                        <span class="d-block erp-td-muted">{{ $suggestion->product?->sku }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $suggestion->warehouse?->name ?? '—' }}</td>
                    <td data-label="Decision">
                        <x-ui.status :value="$suggestion->status" :label="$suggestion->stateLabel()" />
                        @if ($suggestion->decidedBy)
                            <span class="d-block erp-td-muted">{{ $suggestion->decidedBy->name }}</span>
                        @endif
                    </td>
                    <td data-label="Proposed" class="erp-td-num erp-cell-strong">
                        {{ number_format($suggestion->effectiveQty(), 4) }}
                        @if ($suggestion->final_qty !== null)
                            <span class="d-block erp-td-muted">suggested {{ number_format((float) $suggestion->suggested_qty, 4) }}</span>
                        @endif
                    </td>
                    <td data-label="Avg / day" class="erp-td-num erp-td-muted">{{ number_format($suggestion->avgDaily(), 4) }}</td>
                    <td data-label="Cover then" class="erp-td-num erp-td-muted">
                        {{ $suggestion->daysCover() !== null ? $suggestion->daysCover().' d' : '—' }}
                    </td>
                    <td data-label="Why the figure" class="erp-td-muted">
                        {{ $suggestion->demand_window_days }} d window ·
                        {{ number_format((float) $suggestion->available, 4) }} available ·
                        trigger {{ number_format((float) $suggestion->trigger_qty, 4) }} ·
                        {{ number_format((float) $suggestion->in_transit, 4) }} on order
                    </td>
                    <td data-label="Outcome">
                        @if ($suggestion->purchaseOrder !== null)
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('purchase.orders.show', $suggestion->purchaseOrder) }}">
                                <i class="bi bi-receipt" aria-hidden="true"></i> {{ $suggestion->purchaseOrder->code }}
                            </a>
                            <span class="d-block erp-td-muted">{{ $suggestion->purchaseOrder->status }}</span>
                        @else
                            <span class="erp-td-muted">{{ $suggestion->decision_note ?? '—' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty icon="bi-clock-history"
                                    title="No proposal has an answer yet"
                                    :text="$filters['q'] !== '' || $filters['warehouse'] || $filters['status'] !== ''
                                        ? 'Nothing with an answer matches this filter — try Reset.'
                                        : 'Once a proposal is drafted into a purchase order or dismissed with a reason, it moves here with the figures it was judged by.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            <span class="erp-td-muted">
                Figures are the ones stored on the proposal the day it was written — not recomputed now. A suggestion read
                next month must still explain the number it printed that day.
            </span>
        </x-slot:footer>
    </x-ui.table-shell>

    @if ($suggestions->hasPages())
        <div class="mt-3">{{ $suggestions->links() }}</div>
    @endif

    <x-ui.related-pages />
@endsection
