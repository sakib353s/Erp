@extends('layouts.app')

@section('page_title', 'Reorder suggestions')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Reorder"
        title="What to buy, and why"
        subtitle="One row per shelf that has fallen below the line somebody drew — with the demand that got it there, the cover it has left, and what is already on order. Nothing is bought from this screen: a proposal is written down first, so the purchase order that follows can always say which figures asked for it."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.reorder.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock.alerts') }}">
                    <i class="bi bi-bell" aria-hidden="true"></i> Alerts
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reorder.index') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Reorder levels
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reorder.history') }}">
                    <i class="bi bi-clock-history" aria-hidden="true"></i> History
                </a>
            @endif
            @if ($perm('settings.view'))
                <a class="btn btn-outline-secondary" href="{{ route('settings.show', 'reorder') }}">
                    <i class="bi bi-gear" aria-hidden="true"></i> Settings
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @foreach (['reorder', 'rows', 'accept', 'dismiss'] as $bag)
        @error($bag)
            <div class="erp-note erp-note-danger mb-3">
                <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                <div>{{ $message }}</div>
            </div>
        @enderror
    @endforeach

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Proposals waiting"
            :value="number_format($stats['open'])"
            icon="bi-clipboard-check"
            hint="Written down, nobody has answered yet" />
        <x-ui.kpi
            label="Units proposed"
            :value="number_format($stats['units'], 4)"
            icon="bi-box-seam"
            hint="Across the proposals still waiting" />
        <x-ui.kpi
            label="Shelves below their line"
            :value="number_format($counts['short'])"
            icon="bi-graph-down-arrow"
            hint="Re-read from the ledger every time this page loads" />
        <x-ui.kpi
            label="Already on order"
            :value="number_format($counts['covered'])"
            icon="bi-truck"
            hint="Below the line, and nothing to buy — it is on its way" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.reorder.suggestions.index') }}" role="search">
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
            <span class="erp-chip erp-chip-outline">demand over {{ $windowDays }} day(s)</span>
            @if ($filters['q'] !== '' || $filters['warehouse'])
                <a class="btn btn-link" href="{{ route('inventory.reorder.suggestions.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    {{-- Writing the look down is its own POST, and it carries the filter that is
         on screen: a search narrows the view *and* what would be proposed, so the
         two can never disagree about which shelves are being looked at. --}}
    <form method="POST" action="{{ route('inventory.reorder.suggestions.store') }}">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $filters['warehouse'] }}">
        <input type="hidden" name="q" value="{{ $filters['q'] }}">

        <div class="erp-lookbar mb-2">
            <span class="erp-td-muted">
                Nothing is ordered from this screen. Writing a proposal down records the figures below as they are right
                now, and a proposal that is looked at again supersedes the earlier one for the same shelf.
            </span>
            @if ($perm('inventory.reorder.suggest'))
                <button class="btn btn-primary" type="submit" name="scope" value="short">
                    <i class="bi bi-clipboard-plus" aria-hidden="true"></i> Write down all {{ $counts['short'] }} short shelf(s)
                </button>
            @endif
        </div>

    <x-ui.table-shell :bulk="true" :count="count($rows).' shelf/shelves below the line'" :title="'The look right now'">
        <x-slot:bulkActions>
            @if ($perm('inventory.reorder.suggest'))
                <button class="btn btn-sm btn-primary" type="submit" name="scope" value="selected">
                    <i class="bi bi-clipboard-plus" aria-hidden="true"></i> Write down the ticked rows
                </button>
            @endif
        </x-slot:bulkActions>

        <thead>
            <tr>
                <th scope="col" style="width: 2.4rem;">
                    <input class="form-check-input" type="checkbox" data-erp-select-all
                           aria-label="Select every short shelf">
                </th>
                <th>Product</th>
                <th>Warehouse</th>
                <th class="erp-th-num">On hand</th>
                <th class="erp-th-num">Available</th>
                <th class="erp-th-num">On order</th>
                <th class="erp-th-num">Avg / day</th>
                <th class="erp-th-num">Cover</th>
                <th class="erp-th-num">Trigger</th>
                <th class="erp-th-num">Short by</th>
                <th class="erp-th-num">Propose</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Select">
                        {{-- Keyed product:warehouse, because a product can be short on
                             two shelves at once and ticking one must not write down both. --}}
                        <input class="form-check-input" type="checkbox" name="rows[]"
                               value="{{ $row['product']->id }}:{{ $row['warehouse']?->id }}" data-erp-row-select
                               aria-label="Record {{ $row['product']->sku }} in {{ $row['warehouse']?->name }}">
                    </td>
                    <td data-label="Product">
                        <span class="erp-cell-strong">{{ $row['product']->name }}</span>
                        <span class="d-block erp-td-muted">{{ $row['product']->sku }} · {{ $row['scope'] }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $row['warehouse']?->name ?? '—' }}</td>
                    <td data-label="On hand" class="erp-td-num erp-td-muted">{{ number_format($row['on_hand'], 4) }}</td>
                    <td data-label="Available" class="erp-td-num">{{ number_format($row['available'], 4) }}</td>
                    <td data-label="On order" class="erp-td-num erp-td-muted">
                        {{ number_format($row['in_transit'], 4) }}
                        @if ($row['covered_by_order'])
                            <span class="d-block erp-td-muted">already covered</span>
                        @endif
                    </td>
                    <td data-label="Avg / day" class="erp-td-num">
                        @if ($row['avg_daily_demand'] > 0)
                            {{ number_format($row['avg_daily_demand'], 4) }}
                            <span class="d-block erp-td-muted">{{ number_format($row['demand_qty'], 4) }} over lead time</span>
                        @else
                            <span class="erp-td-muted">no movement</span>
                        @endif
                    </td>
                    <td data-label="Cover" class="erp-td-num erp-td-muted">
                        {{ $row['cover_days'] !== null ? $row['cover_days'].' d' : '—' }}
                    </td>
                    <td data-label="Trigger" class="erp-td-num erp-td-muted">{{ number_format($row['trigger_qty'], 4) }}</td>
                    <td data-label="Short by" class="erp-td-num erp-cell-strong">{{ number_format($row['shortage'], 4) }}</td>
                    <td data-label="Propose" class="erp-td-num erp-cell-strong">
                        {{ number_format($row['suggested_qty'], 4) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">
                        <x-ui.empty icon="bi-clipboard-check"
                                    title="Nothing is short right now"
                                    :text="$filters['q'] !== '' || $filters['warehouse']
                                        ? 'No product with a reorder policy is below its line in this filter. A product without a policy is never proposed — policies live under Reorder levels.'
                                        : 'Every shelf with a policy is above the line somebody drew for it. This screen reads the ledger on every visit, so it will say so the moment that changes.'" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <x-slot:footer>
            <span class="erp-td-muted">
                Demand is measured over {{ $windowDays }} days from real outbound movements only — a transfer, a write-off or a
                correction is not a customer wanting something, so none of them are allowed to inflate this figure.
            </span>
        </x-slot:footer>
    </x-ui.table-shell>
    </form>

    @error('rows')
        <div class="erp-note erp-note-warn mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <h2 class="erp-h2 mt-4 mb-2">
        Proposals waiting for a decision
        @if ($stats['open'] > 0)
            <span class="erp-chip erp-chip-outline">{{ $stats['open'] }}</span>
        @endif
    </h2>

    <x-ui.table-shell :title="'Written down, not yet answered'" :count="$suggestions->total().' proposal(s)'">
        <thead>
            <tr>
                <th>Proposal</th>
                <th>Product</th>
                <th>Warehouse</th>
                <th class="erp-th-num">Proposed</th>
                <th class="erp-th-num">Avg / day</th>
                <th class="erp-th-num">Cover</th>
                <th>Why</th>
                <th class="erp-th-actions">Decision</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suggestions as $suggestion)
                <tr>
                    <td data-label="Proposal">
                        <span class="erp-cell-strong">{{ $suggestion->code }}</span>
                        <span class="d-block erp-td-muted">
                            <x-ui.status :value="$suggestion->status" :label="$suggestion->stateLabel()" />
                            {{ $suggestion->run_date?->format('d M Y') }}
                        </span>
                    </td>
                    <td data-label="Product">
                        {{ $suggestion->product?->name }}
                        <span class="d-block erp-td-muted">{{ $suggestion->product?->sku }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $suggestion->warehouse?->name ?? '—' }}</td>
                    <td data-label="Proposed" class="erp-td-num erp-cell-strong">
                        {{ number_format($suggestion->effectiveQty(), 4) }}
                        @if ($suggestion->final_qty !== null)
                            <span class="d-block erp-td-muted">changed from {{ number_format((float) $suggestion->suggested_qty, 4) }}</span>
                        @endif
                    </td>
                    <td data-label="Avg / day" class="erp-td-num erp-td-muted">{{ number_format($suggestion->avgDaily(), 4) }}</td>
                    <td data-label="Cover" class="erp-td-num erp-td-muted">
                        {{ $suggestion->daysCover() !== null ? $suggestion->daysCover().' d' : '—' }}
                    </td>
                    <td data-label="Why" class="erp-td-muted">
                        {{ $suggestion->demand_window_days }} d window ·
                        {{ number_format((float) $suggestion->available, 4) }} available ·
                        trigger {{ number_format((float) $suggestion->trigger_qty, 4) }}
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if ($perm('inventory.reorder.suggest') && $suggestion->isOpen())
                            <details class="erp-details">
                                <summary class="btn btn-sm btn-primary">
                                    <i class="bi bi-cart-plus" aria-hidden="true"></i> Draft order
                                </summary>
                                <form class="erp-details-body" method="POST"
                                      action="{{ route('inventory.reorder.suggestions.accept', $suggestion) }}">
                                    @csrf
                                    <label class="form-label" for="supplier_{{ $suggestion->id }}">Supplier</label>
                                    <select class="form-select form-select-sm" id="supplier_{{ $suggestion->id }}"
                                            name="supplier_id" required>
                                        <option value="">Choose a supplier…</option>
                                        @foreach ($suppliers as $supplier)
                                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                        @endforeach
                                    </select>
                                    <label class="form-label mt-2" for="qty_{{ $suggestion->id }}">
                                        Quantity <span class="erp-td-muted">(leave to take {{ number_format($suggestion->effectiveQty(), 4) }})</span>
                                    </label>
                                    <input class="form-control form-control-sm" type="number" step="0.0001" min="0.0001"
                                           id="qty_{{ $suggestion->id }}" name="quantity"
                                           placeholder="{{ number_format($suggestion->effectiveQty(), 4, '.', '') }}">
                                    <button class="btn btn-sm btn-primary mt-2" type="submit">
                                        Draft the purchase order
                                    </button>
                                    <p class="erp-help mt-1 mb-0">
                                        The order is drafted, numbered and audited by the purchase module and still needs
                                        approving — this screen never buys anything.
                                    </p>
                                </form>
                            </details>

                            <details class="erp-details mt-1">
                                <summary class="btn btn-sm btn-outline-secondary">Dismiss</summary>
                                <form class="erp-details-body" method="POST"
                                      action="{{ route('inventory.reorder.suggestions.dismiss', $suggestion) }}">
                                    @csrf
                                    <label class="form-label" for="note_{{ $suggestion->id }}">Why not</label>
                                    <input class="form-control form-control-sm" type="text" maxlength="500" required
                                           id="note_{{ $suggestion->id }}" name="note"
                                           placeholder="The customer cancelled / we already have a substitute">
                                    <button class="btn btn-sm btn-outline-secondary mt-2" type="submit">Dismiss</button>
                                    <p class="erp-help mt-1 mb-0">
                                        The reason is kept in the history — a "no" without a why forces the next person to buy it anyway.
                                    </p>
                                </form>
                            </details>
                        @elseif ($suggestion->purchaseOrder !== null)
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('purchase.orders.show', $suggestion->purchaseOrder) }}">
                                <i class="bi bi-receipt" aria-hidden="true"></i> {{ $suggestion->purchaseOrder->code }}
                            </a>
                        @elseif ($suggestion->decision_note !== null)
                            <span class="erp-td-muted">{{ $suggestion->decision_note }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-clipboard-plus"
                                    title="No proposal is waiting"
                                    text="Tick a shelf above — or write down the whole short list — and it appears here with the figures that produced it. The numbers are stored as they were read, so this row will still explain itself next month." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($suggestions->hasPages())
        <div class="mt-3">{{ $suggestions->links() }}</div>
    @endif

    <x-ui.related-pages />
@endsection
