@extends('layouts.app')

@section('page_title', 'Pick Lists')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="Pick Lists"
        subtitle="A pick list is the walk behind an order: which bin to go to, how much to take, and — for batch-tracked goods — which batch the shelf gives up first. Nothing here moves stock; dispatch still does that."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.putaway-lists.index') }}">
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Putaway lists
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> Bins &amp; map
            </a>
            @if ($perm('warehouses.update'))
                <a class="btn btn-primary" href="{{ route('inventory.pick-lists.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New pick list
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Open walks" :value="number_format($stats['open'])" icon="bi-basket"
                  hint="{{ $stats['unassigned'] > 0 ? number_format($stats['unassigned']).' not handed to anyone yet' : 'Every open walk has a picker' }}" />
        <x-ui.kpi label="Orders waiting on a walk" :value="number_format($stats['orders_waiting'])" icon="bi-cart-check"
                  hint="Confirmed orders with goods still to pick" :href="route('inventory.pick-lists.create')" />
        <x-ui.kpi label="Lines with no bin" :value="number_format($stats['no_bin'])" icon="bi-question-circle"
                  hint="Nobody has said where these products live yet" />
        <x-ui.kpi label="Picked in the last 7 days" :value="number_format($stats['done_week'])" icon="bi-check2-circle"
                  hint="Walks finished — the goods left the shelf" />
    </div>

    @if ($stats['no_bin'] > 0 && $perm('warehouses.update'))
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>
            <div>
                <strong>{{ number_format($stats['no_bin']) }} line(s) cannot say where to walk.</strong>
                A pick list points at a bin, so a product nobody has placed yet has no address. Give it one on
                <a href="{{ route('warehouses.index') }}">the warehouse map</a> and the next pick list will point at it.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.pick-lists.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}" placeholder="List number or order number">
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
            <label class="form-label" for="mine">Whose walk</label>
            <select class="form-select" id="mine" name="mine">
                <option value="">Everyone</option>
                <option value="1" @selected($mine)>Mine</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] || $filters['status'] || $filters['warehouse_id'] || $mine)
                <a class="btn btn-link" href="{{ route('inventory.pick-lists.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @error('pick_list') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <x-ui.table-shell :count="$lists->total().' list'.($lists->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Pick list</th>
                <th>Warehouse</th>
                <th>Picker</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Progress</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lists as $list)
                <tr>
                    <td data-label="Pick list">
                        <a class="erp-cell-strong text-decoration-none" href="{{ route('inventory.pick-lists.show', $list) }}">
                            <code>{{ $list->code }}</code>
                        </a>
                        <span class="d-block erp-td-muted small">
                            @if ($list->order)
                                for <a href="{{ route('sales.orders.show', $list->order) }}">{{ $list->order->order_no }}</a>
                            @else
                                manual list
                            @endif
                            · {{ $list->created_at?->format('d M Y H:i') }}
                        </span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $list->warehouse?->name }}</td>
                    <td data-label="Picker" class="erp-td-muted">{{ $list->assignee?->name ?? '— nobody yet' }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ number_format($list->lines_count) }}</td>
                    <td data-label="Progress" class="erp-td-num">
                        {{ number_format($list->pickedQty(), 2) }} / {{ number_format($list->requiredQty(), 2) }}
                        <span class="d-block erp-td-muted small">{{ $list->progressPct() }}%</span>
                    </td>
                    <td data-label="Status">
                        <x-ui.status :value="$list->stateTone()" :label="$list->stateLabel()" />
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.pick-lists.show', $list) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-basket" title="No pick list matches this filter"
                            text="Raise one from a confirmed order, or write the lines by hand for a counter sale. The list is what the picker walks; the ledger stays untouched until dispatch." />
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
