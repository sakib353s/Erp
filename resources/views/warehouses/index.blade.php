@extends('layouts.app')

@section('page_title', 'Warehouses')

@section('content')
    <x-ui.page-header
        eyebrow="Warehouse"
        title="Warehouses"
        subtitle="Where stock lives. Every warehouse belongs to exactly one branch, and you only see branches you have access to — opening one shows its zones, bins and the map."
        :pin="true">
        <x-slot:actions>
            @if ($perm('warehouses.create'))
                <a class="btn btn-primary" href="{{ route('warehouses.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New warehouse
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('warehouses.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $q }}" placeholder="Name or code">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="branch_id">Branch</label>
            <select class="form-select" id="branch_id" name="branch_id">
                <option value="">All my branches</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected((int) request('branch_id') === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($q || request('branch_id'))
                <a class="btn btn-link" href="{{ route('warehouses.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$warehouses->total().' warehouse(s)'">
        <thead>
            <tr>
                <th>Warehouse</th>
                <th>Branch</th>
                <th>Address</th>
                <th class="erp-th-num">Zones</th>
                <th class="erp-th-num">Bins</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($warehouses as $warehouse)
                <tr>
                    <td data-label="Warehouse">
                        <a class="erp-cell-strong" href="{{ route('warehouses.show', $warehouse) }}">{{ $warehouse->name }}</a>
                        <span class="d-block erp-td-muted small"><code>{{ $warehouse->code }}</code>
                            @if ($warehouse->is_default) · default for this branch @endif
                        </span>
                    </td>
                    <td data-label="Branch" class="erp-td-muted">{{ $warehouse->branch?->name }}</td>
                    <td data-label="Address" class="erp-td-muted">{{ $warehouse->address ?: '—' }}</td>
                    <td data-label="Zones" class="erp-td-num">
                        @if ($warehouse->zones_count === 0)
                            <span class="erp-td-muted">not laid out</span>
                        @else
                            {{ number_format($warehouse->zones_count) }}
                        @endif
                    </td>
                    <td data-label="Bins" class="erp-td-num">
                        {{ $warehouse->bins_count === 0 ? '—' : number_format($warehouse->bins_count) }}
                    </td>
                    <td data-label="Status">
                        <x-ui.status :value="$warehouse->is_active ? 'active' : 'inactive'" />
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('warehouses.show', $warehouse) }}">
                            <i class="bi bi-map" aria-hidden="true"></i> Layout
                        </a>
                        @if ($perm('warehouses.update'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('warehouses.edit', $warehouse) }}">Edit</a>
                        @endif
                        @if ($perm('warehouses.delete'))
                            <form class="d-inline" method="POST" action="{{ route('warehouses.destroy', $warehouse) }}"
                                  data-confirm="Delete warehouse {{ $warehouse->name }}? Only one that has never moved stock can go.">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-buildings" title="No warehouse matches"
                            text="A warehouse is where stock physically sits. Open one to lay out its zones and bins, which is what makes a bin location or a pick instruction possible." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $warehouses->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
