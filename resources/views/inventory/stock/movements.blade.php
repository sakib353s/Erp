@extends('layouts.app')

@section('page_title', 'Stock Movements')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Stock Movement Ledger</h1>
            <p class="erp-page-sub">Immutable append-only ledger. Corrections are compensating movements only.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.stock') }}">Stock overview</a>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="q">Search product</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="SKU or name">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                    @endforeach
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
                        <th>Occurred</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th>Type</th>
                        <th>State</th>
                        <th class="text-end">Qty signed</th>
                        <th class="text-end">Unit cost</th>
                        <th>Source</th>
                        <th>Actor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr>
                            <td class="small">{{ $movement->occurred_at?->format('d M Y H:i') }}</td>
                            <td>
                                <a class="text-decoration-none"
                                   href="{{ route('inventory.products.ledger', $movement->product) }}">
                                    {{ $movement->product?->sku }}
                                </a>
                                <div class="small text-body-secondary">{{ $movement->product?->name }}</div>
                            </td>
                            <td class="small">{{ $movement->warehouse?->name }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $movement->movement_type }}</span></td>
                            <td class="small">{{ $movement->state }}</td>
                            <td class="text-end {{ str_starts_with((string) $movement->qty_signed, '-') ? 'text-danger' : 'text-success' }}">
                                {{ number_format((float) $movement->qty_signed, 2) }}
                            </td>
                            <td class="text-end">{{ number_format((float) $movement->unit_cost, 4) }}</td>
                            <td class="small">{{ $movement->source_type }}{{ $movement->source_id ? '#'.$movement->source_id : '' }}</td>
                            <td class="small">{{ $movement->actor?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-body-secondary">No movements yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $movements->links() }}
        </div>
    </div>
@endsection
