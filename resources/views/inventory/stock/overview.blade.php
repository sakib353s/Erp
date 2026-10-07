@extends('layouts.app')

@section('page_title', 'Stock Overview')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Stock Overview</h1>
            <p class="erp-page-sub">Derived from the immutable stock movement ledger — rebuildable at any time.</p>
        </div>
        <div class="d-flex gap-2">
            @if ($perm('inventory.adjustments.create'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.stock.opening.create') }}">Opening stock</a>
            @endif
            @if ($perm('inventory.ledger.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.movements') }}">Movement ledger</a>
            @endif
            @if ($perm('inventory.stock.view'))
                <form method="POST" action="{{ route('inventory.stock.rebuild') }}">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">Rebuild balances</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">On hand</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['on_hand'], 2) }}</div></div></div>
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">Reserved</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['reserved'], 2) }}</div></div></div>
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">Available</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['available'], 2) }}</div></div></div>
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">In transit</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['in_transit'], 2) }}</div></div></div>
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">Damaged</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['damaged'], 2) }}</div></div></div>
        <div class="col-md-2"><div class="erp-card"><div class="small text-body-secondary">Quarantined</div>
            <div class="fs-5 fw-semibold">{{ number_format($totals['quarantined'], 2) }}</div></div></div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="SKU or name">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="warehouse">Warehouse</label>
                <select class="form-select" id="warehouse" name="warehouse">
                    <option value="">All</option>
                    @foreach ($warehouses as $wh)
                        <option value="{{ $wh->id }}" @selected(request('warehouse') == $wh->id)>
                            {{ $wh->name }}
                        </option>
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
                        <th>SKU</th>
                        <th>Name</th>
                        <th>Warehouse</th>
                        <th class="text-end">On hand</th>
                        <th class="text-end">Reserved</th>
                        <th class="text-end">Available</th>
                        <th class="text-end">In transit</th>
                        <th class="text-end">Damaged</th>
                        <th class="text-end">Quarantined</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td><code>{{ $row['product']->sku ?? '—' }}</code></td>
                            <td>
                                <a class="text-decoration-none"
                                   href="{{ route('inventory.products.ledger', $row['product']) }}">
                                    {{ $row['product']->name ?? '—' }}
                                </a>
                            </td>
                            <td class="small">{{ $row['warehouse']->name ?? '—' }}</td>
                            <td class="text-end">{{ number_format($row['on_hand'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['reserved'], 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row['available'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['in_transit'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['damaged'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['quarantined'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-4 text-body-secondary">
                                No stock balances yet. Post opening stock or adjustments.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
