@extends('layouts.app')

@section('page_title', 'Products')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Products</h1>
            <p class="erp-page-sub">Stock-managed catalogue. SKU is unique per company.</p>
        </div>
        @if ($perm('inventory.products.create'))
            <a class="btn btn-primary" href="{{ route('inventory.products.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add product
            </a>
        @endif
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="SKU, code or name">
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
                        <th>SKU</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Cost method</th>
                        <th>Stocked</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr>
                            <td><code>{{ $product->code }}</code></td>
                            <td>{{ $product->sku }}</td>
                            <td>
                                <a class="fw-semibold text-decoration-none"
                                   href="{{ route('inventory.products.ledger', $product) }}">
                                    {{ $product->name }}
                                </a>
                            </td>
                            <td class="small">{{ $product->category?->name ?? '—' }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $product->cost_method }}</span></td>
                            <td>{{ $product->is_stocked ? 'Yes' : 'No' }}</td>
                            <td>
                                <span class="erp-status {{ $product->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $product->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('inventory.products.ledger', $product) }}">Ledger</a>
                                @if ($perm('inventory.products.edit'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.products.edit', $product) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-body-secondary">
                                No products yet. Add your first product to start tracking stock.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $products->links() }}
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
