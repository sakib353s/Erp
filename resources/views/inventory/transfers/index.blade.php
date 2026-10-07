@extends('layouts.app')

@section('page_title', 'Stock Transfers')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Stock Transfers</h1>
            <p class="erp-page-sub">Dispatch posts TRANSIT_OUT at origin; receive posts TRANSIT_IN at destination. Short receive = discrepancy.</p>
        </div>
        @if ($perm('inventory.transfers.create'))
            <a class="btn btn-primary" href="{{ route('inventory.transfers.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New transfer
            </a>
        @endif
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (['draft', 'dispatched', 'received', 'discrepancy', 'cancelled'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
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
                        <th>Transfer no</th>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th class="text-end">Lines</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transfers as $transfer)
                        <tr>
                            <td><code>{{ $transfer->transfer_no }}</code></td>
                            <td>{{ $transfer->transfer_date?->format('d M Y') }}</td>
                            <td>{{ $transfer->fromWarehouse?->name }}</td>
                            <td>{{ $transfer->toWarehouse?->name }}</td>
                            <td>
                                <span class="erp-status {{
                                    match ($transfer->status) {
                                        'received' => 'erp-status-active',
                                        'discrepancy' => 'erp-status-inactive',
                                        'cancelled' => 'erp-status-disabled',
                                        default => 'erp-status-draft',
                                    }
                                }}">{{ $transfer->status }}</span>
                            </td>
                            <td class="text-end">{{ $transfer->lines->count() }}</td>
                            <td class="text-end">
                                @if ($transfer->status === 'draft' && $perm('inventory.transfers.dispatch'))
                                    <form method="POST" class="d-inline"
                                          action="{{ route('inventory.transfers.dispatch', $transfer) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Dispatch</button>
                                    </form>
                                @endif
                                @if ($transfer->status === 'dispatched' && $perm('inventory.transfers.receive'))
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                            data-bs-target="#receiveModal"
                                            data-transfer-id="{{ $transfer->id }}"
                                            data-lines="{{ $transfer->lines->map(fn ($l) => [
                                                'product_id' => $l->product_id,
                                                'sku' => $l->product?->sku,
                                                'name' => $l->product?->name,
                                                'qty_sent' => (float) $l->qty_sent,
                                                'qty_received' => (float) ($l->qty_received ?? $l->qty_sent),
                                            ])->values() }}">
                                        Receive
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4 text-body-secondary">No transfers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $transfers->links() }}
        </div>
    </div>

    <div class="modal fade" id="receiveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" id="receive-form">
                @csrf
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Receive transfer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @error('transfer') <div class="alert alert-danger">{{ $message }}</div> @enderror
                        <div class="table-responsive">
                            <table class="table erp-table" id="receive-lines">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty sent</th>
                                        <th>Qty received</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Confirm receipt</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        (function () {
            const modal = document.getElementById('receiveModal');
            if (!modal) return;
            modal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                const transferId = btn.getAttribute('data-transfer-id');
                const lines = JSON.parse(btn.getAttribute('data-lines') || '[]');
                document.getElementById('receive-form').action =
                    '/app/inventory/transfers/' + transferId + '/receive';
                const tbody = document.querySelector('#receive-lines tbody');
                tbody.innerHTML = '';
                lines.forEach(function (line, i) {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><code>' + (line.sku || '') + '</code></td>' +
                        '<td>' + (line.name || '') + '</td>' +
                        '<td class="text-end">' + Number(line.qty_sent).toFixed(4) + '</td>' +
                        '<td><input type="number" step="0.0001" min="0" class="form-control" ' +
                        'name="lines[' + i + '][product_id]" value="' + line.product_id + '" hidden>' +
                        '<input type="number" step="0.0001" min="0" class="form-control" ' +
                        'name="lines[' + i + '][qty_received]" value="' + Number(line.qty_received).toFixed(4) + '"></td>';
                    tbody.appendChild(tr);
                });
            });
        })();
    </script>
    @endpush
@endsection
