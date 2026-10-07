@extends('layouts.app')

@section('page_title', 'Putaway Lists')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="Putaway Lists"
        subtitle="Goods arrive on a dock and have to end up in a bin. A putaway list is that decision written down — and it is how the system learns where a product lives."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.pick-lists.index') }}">
                <i class="bi bi-basket" aria-hidden="true"></i> Pick lists
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> Bins &amp; map
            </a>
            @if ($perm('warehouses.update'))
                <a class="btn btn-primary" href="{{ route('inventory.putaway-lists.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New putaway list
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Open putaways" :value="number_format($stats['open'])" icon="bi-box-arrow-in-down"
                  hint="{{ $stats['unassigned'] > 0 ? number_format($stats['unassigned']).' not handed to anyone yet' : 'Every open list has somebody' }}" />
        <x-ui.kpi label="Receipts still on the dock" :value="number_format($stats['receipts_waiting'])" icon="bi-truck"
                  hint="Posted receipts nobody has put away yet" :href="route('inventory.putaway-lists.create')" />
        <x-ui.kpi label="Lines with no target bin" :value="number_format($stats['no_bin'])" icon="bi-signpost-split"
                  hint="The system has no home for these products yet" />
        <x-ui.kpi label="Put away in the last 7 days" :value="number_format($stats['done_week'])" icon="bi-check2-circle"
                  hint="Lists finished — the dock is empty" />
    </div>

    @if ($stats['receipts_waiting'] > 0 && $perm('warehouses.update'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-truck" aria-hidden="true"></i>
            <div>
                <strong>{{ number_format($stats['receipts_waiting']) }} posted receipt(s) have no putaway list.</strong>
                The stock is in the ledger, but nobody has said where it physically is —
                <a href="{{ route('inventory.putaway-lists.create') }}">raise a putaway list</a> and the bins will know.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.putaway-lists.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}" placeholder="List, receipt or challan number">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
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
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] === (int) $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="mine">Whose job</label>
            <select class="form-select" id="mine" name="mine">
                <option value="">Everyone</option>
                <option value="1" @selected($mine)>Mine</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] || $filters['status'] || $filters['warehouse_id'] || $mine)
                <a class="btn btn-link" href="{{ route('inventory.putaway-lists.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @error('putaway_list') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <x-ui.table-shell :count="$lists->total().' list'.($lists->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Putaway list</th>
                <th>Warehouse</th>
                <th>Receiver</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Placed</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lists as $list)
                <tr>
                    <td data-label="Putaway list">
                        <a class="erp-cell-strong text-decoration-none" href="{{ route('inventory.putaway-lists.show', $list) }}">
                            <code>{{ $list->code }}</code>
                        </a>
                        <span class="d-block erp-td-muted small">
                            @if ($list->receipt)
                                from <a href="{{ route('purchase.receipts.show', $list->receipt) }}">{{ $list->receipt->code }}</a>
                                @if ($list->receipt->challan_no) · supplier challan {{ $list->receipt->challan_no }} @endif
                            @else
                                manual list
                            @endif
                            · {{ $list->created_at?->format('d M Y H:i') }}
                        </span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $list->warehouse?->name }}</td>
                    <td data-label="Receiver" class="erp-td-muted">{{ $list->assignee?->name ?? '— nobody yet' }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ number_format($list->lines_count) }}</td>
                    <td data-label="Placed" class="erp-td-num">
                        {{ number_format($list->placedQty(), 2) }} / {{ number_format($list->requiredQty(), 2) }}
                        <span class="d-block erp-td-muted small">{{ $list->progressPct() }}%</span>
                    </td>
                    <td data-label="Status">
                        <x-ui.status :value="$list->stateTone()" :label="$list->stateLabel()" />
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.putaway-lists.show', $list) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-box-arrow-in-down" title="No putaway list matches this filter"
                            text="Raise one from a posted receipt: the stock is already in the ledger, and the list is where somebody says which bin it is actually in." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $lists->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
