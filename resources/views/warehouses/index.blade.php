@extends('layouts.app')

@section('page_title', 'Warehouses')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Warehouses</h1>
            <p class="erp-page-sub">Every warehouse belongs to exactly one branch; you only see branches you have access to.</p>
        </div>
        @if ($perm('warehouses.create'))
            <a class="btn btn-primary" href="{{ route('warehouses.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New warehouse
            </a>
        @endif
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('warehouses.index') }}">
        <div class="col-sm-5 col-md-4">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search name or code…" aria-label="Search warehouses">
        </div>
        <div class="col-sm-4 col-md-3">
            <select class="form-select" name="branch_id" aria-label="Filter by branch">
                <option value="">All my branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected((int) request('branch_id') === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q || request('branch_id'))
            <div class="col-auto"><a class="btn btn-link" href="{{ route('warehouses.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Branch</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($warehouses as $warehouse)
                        <tr>
                            <td><code>{{ $warehouse->code }}</code></td>
                            <td>
                                <span class="fw-semibold">{{ $warehouse->name }}</span>
                                @if($warehouse->is_default)<span class="erp-chip erp-chip-soft">default</span>@endif
                            </td>
                            <td class="text-body-secondary">{{ $warehouse->branch?->name }}</td>
                            <td class="text-body-secondary">{{ $warehouse->address ?: '—' }}</td>
                            <td>
                                <span class="erp-status {{ $warehouse->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $warehouse->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if ($perm('warehouses.update'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('warehouses.edit', $warehouse) }}">Edit</a>
                                @endif
                                @if ($perm('warehouses.delete'))
                                    <form class="d-inline" method="POST" action="{{ route('warehouses.destroy', $warehouse) }}"
                                          data-confirm="Delete warehouse {{ $warehouse->name }}? This cannot be undone.">
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
                        <tr><td colspan="6" class="text-center py-4 text-body-secondary">No warehouses match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $warehouses->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
