@extends('layouts.app')

@section('page_title', 'Delivery Challans')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Delivery Challans</h1>
            <p class="erp-page-sub">DOC only — stock issue stays on invoice/transfer paths.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('sales.orders.index') }}">Orders</a>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Challan number">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (['draft', 'ready', 'dispatched', 'delivered', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
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
                        <th>Challan #</th>
                        <th>Date</th>
                        <th>Order</th>
                        <th>Courier</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($challans as $challan)
                        <tr>
                            <td><code><a href="{{ route('sales.delivery-challans.show', $challan) }}">{{ $challan->challan_no }}</a></code></td>
                            <td>{{ optional($challan->challan_date)->toDateString() }}</td>
                            <td>{{ $challan->order?->order_no ?? '—' }}</td>
                            <td>{{ $challan->courier_name ?? '—' }}</td>
                            <td><span class="erp-status erp-status-active">{{ $challan->status }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No delivery challans yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $challans->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
