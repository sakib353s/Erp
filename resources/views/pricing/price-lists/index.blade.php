@extends('layouts.app')

@section('page_title', 'Price Lists')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Price Lists</h1>
            <p class="erp-page-sub">Server-authoritative list prices — the default list feeds unit price resolution.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('pricing.price-lists.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add price list
        </a>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Code or name">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Validity</th>
                        <th class="text-end">Price rows</th>
                        <th>Default</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lists as $list)
                        <tr>
                            <td><code>{{ $list->code }}</code></td>
                            <td class="fw-semibold">{{ $list->name }}</td>
                            <td class="small">
                                {{ $list->valid_from?->toDateString() ?? '—' }} → {{ $list->valid_to?->toDateString() ?? 'open' }}
                            </td>
                            <td class="text-end">{{ $list->items_count }}</td>
                            <td>
                                @if ($list->is_default)
                                    <span class="erp-status erp-status-active">default</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="erp-status {{ $list->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $list->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('pricing.price-lists.edit', $list) }}">Edit</a>
                                    <form method="POST" action="{{ route('pricing.price-lists.destroy', $list) }}"
                                          onsubmit="return confirm('Delete price list {{ $list->code }} and its price rows?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No price lists yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $lists->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
