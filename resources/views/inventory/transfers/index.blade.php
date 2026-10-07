@extends('layouts.app')

@section('page_title', 'Stock Transfers')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Stock Transfers"
        subtitle="Dispatch posts TRANSIT_OUT at the origin; receive posts TRANSIT_IN at the destination. A short receive opens a discrepancy — never a silent loss. Above the threshold a transfer waits, and dispatch refuses it until somebody else clears it."
        :pin="true">
        <x-slot:actions>
            @if ($perm('inventory.transfers.create'))
                <a class="btn btn-primary" href="{{ route('inventory.transfers.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New transfer
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Waiting for approval" :value="number_format($counts['pending_approval'])" icon="bi-hourglass-split"
                  hint="{{ $counts['pending_approval'] > 0 ? number_format($pendingValue, 2).' of stock held back' : 'Nothing is waiting' }}" />
        <x-ui.kpi label="Ready to dispatch" :value="number_format($counts['draft'])" icon="bi-box-arrow-up"
                  hint="Drafts — nothing has left the warehouse yet" />
        <x-ui.kpi label="In transit" :value="number_format($counts['dispatched'])" icon="bi-truck"
                  hint="Gone from the origin, not yet in the destination" />
        <x-ui.kpi label="Approval threshold" :value="$threshold > 0 ? number_format($threshold, 2) : 'not set'"
                  icon="bi-sliders" hint="Settings › Inventory — 0 dispatches every transfer as always" />
    </div>

    @error('transfer') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($perm('inventory.transfers.approve') && $counts['pending_approval'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong>{{ $counts['pending_approval'] }} transfer(s) are waiting for you.</strong>
                Approving makes one dispatchable; rejecting ends it. Neither moves stock.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.transfers.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $q }}" placeholder="Number or narration">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                @foreach (\App\Domain\Inventory\StockTransfer::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }} ({{ $counts[$key] ?? 0 }})</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($q || $status)
                <a class="btn btn-link" href="{{ route('inventory.transfers.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$transfers->total().' transfer'.($transfers->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Transfer</th>
                <th>Date</th>
                <th>Route</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
                <tbody>
                    @forelse ($transfers as $transfer)
                        <tr>
                            <td data-label="Transfer">
                                <span class="erp-cell-strong"><code>{{ $transfer->transfer_no }}</code></span>
                                @if ($transfer->creator)
                                    <span class="d-block erp-td-muted small">raised by {{ $transfer->creator->name }}</span>
                                @endif
                            </td>
                            <td data-label="Date" class="erp-td-muted">{{ $transfer->transfer_date?->format('d M Y') }}</td>
                            <td data-label="Route">
                                {{ $transfer->fromWarehouse?->name }}
                                <span class="d-block erp-td-muted small">→ {{ $transfer->toWarehouse?->name }}</span>
                            </td>
                            <td data-label="Lines" class="erp-td-num">{{ number_format($transfer->lines->count()) }}</td>
                            <td data-label="Value" class="erp-td-num">{{ number_format((float) $transfer->total_value, 2) }}</td>
                            <td data-label="Status">
                                <x-ui.status :value="$transfer->status" :label="$transfer->statusLabel()" />
                                @if ($transfer->approver)
                                    <span class="d-block erp-td-muted small">decided by {{ $transfer->approver->name }}</span>
                                @endif
                            </td>
                            <td data-label="" class="erp-td-actions">
                                @if ($transfer->isPending() && $perm('inventory.transfers.approve'))
                                    <form method="POST" class="d-inline"
                                          action="{{ route('inventory.transfers.approve', $transfer) }}"
                                          data-confirm="Approve {{ $transfer->transfer_no }}? It becomes dispatchable — nothing moves yet.">
                                        @csrf
                                        <button class="btn btn-sm btn-primary" type="submit">Approve</button>
                                    </form>
                                    <form method="POST" class="d-inline"
                                          action="{{ route('inventory.transfers.reject', $transfer) }}">
                                        @csrf
                                        <input type="hidden" name="note" value="Rejected from the transfer register.">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                    </form>
                                @elseif ($transfer->isPending())
                                    <span class="erp-td-muted small">Waiting on someone who may approve</span>
                                @endif
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
                            <td colspan="7">
                                <x-ui.empty icon="bi-arrow-left-right" title="No transfer matches this filter"
                                    text="A transfer is stock moving between your own warehouses: it leaves the origin, it is in transit, and it arrives — with a discrepancy if the two ends disagree." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $transfers->links() }}</div>

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

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
