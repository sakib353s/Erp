@extends('layouts.app')

@section('page_title', 'Shipments')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Shipments</h1>
            <p class="erp-page-sub">Courier handoffs — dispatch moves stock into transit and advances the order; nothing is dispatched before the goods actually leave.</p>
        </div>
    </div>

    @if ($perm('sales.delivery.shipments.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3">Create shipment</h2>
            <form method="GET" action="{{ route('sales.shipments.index') }}" class="row g-2 align-items-end mb-3">
                <div class="col-md-5">
                    <label class="form-label" for="order_id">Order</label>
                    <select class="form-select" id="order_id" name="order_id" onchange="this.form.submit()">
                        <option value="">Select an order…</option>
                        @foreach ($openOrders as $order)
                            <option value="{{ $order->id }}" @selected($selectedOrder?->id === $order->id)>
                                {{ $order->order_no }} — {{ $order->status }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-outline-secondary" type="submit">Load lines</button>
                </div>
            </form>

            @if ($selectedOrder)
                <form method="POST" action="{{ route('sales.shipments.store') }}">
                    @csrf
                    <input type="hidden" name="order_id" value="{{ $selectedOrder->id }}">

                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label" for="courier_id">Courier</label>
                            <select class="form-select" id="courier_id" name="courier_id" required>
                                <option value="">Select courier…</option>
                                @foreach ($couriers as $courier)
                                    <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table erp-table align-middle">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Shipped before</th>
                                    <th class="text-end">Qty to ship</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($selectedOrder->lines as $line)
                                    @php($left = $remaining[(int) $line->product_id] ?? 0.0)
                                    <tr @if($left <= 0) class="table-secondary" @endif>
                                        <td>
                                            {{ $line->product?->name ?? 'Product #'.$line->product_id }}
                                            <code class="small">{{ $line->product?->sku }}</code>
                                        </td>
                                        <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</td>
                                        <td class="text-end">
                                            {{ rtrim(rtrim(number_format((float) $line->qty - $left, 4), '0'), '.') }}
                                        </td>
                                        <td class="text-end" style="max-width: 9rem;">
                                            @if ($left > 0)
                                                <input class="form-control form-control-sm text-end" type="number"
                                                       name="lines[{{ $loop->index }}][product_id]"
                                                       value="{{ $line->product_id }}" hidden>
                                                <input class="form-control form-control-sm text-end" type="number"
                                                       step="0.0001" min="0.0001" max="{{ $left }}"
                                                       name="lines[{{ $loop->index }}][qty]"
                                                       value="{{ $left }}" required
                                                       aria-label="Qty to ship for {{ $line->product?->name }}">
                                            @else
                                                <span class="text-muted">fully shipped</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($remaining !== [])
                        <button class="btn btn-primary" type="submit">Create shipment</button>
                    @else
                        <p class="text-muted mb-0">Nothing left to ship on this order.</p>
                    @endif
                </form>
            @endif
        </div>

        <div class="erp-card mb-3">
            <h2 class="erp-h3">Bulk shipment</h2>
            <p class="small text-muted">Ships each selected order's remaining lines — per-order outcomes, one failure never blocks the rest.</p>
            <form method="POST" action="{{ route('sales.shipments.bulk') }}">
                @csrf
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label" for="bulk_courier_id">Courier</label>
                        <select class="form-select" id="bulk_courier_id" name="courier_id" required>
                            <option value="">Select courier…</option>
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Ship selected</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table erp-table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 2rem;">
                                    <input class="form-check-input" type="checkbox" id="check_all"
                                           aria-label="Select all orders"
                                           onclick="var c=this.checked; document.querySelectorAll('.order-check').forEach(function(x){x.checked=c;})">
                                </th>
                                <th>Order</th>
                                <th>Status</th>
                                <th class="text-end">Grand total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($openOrders as $order)
                                <tr>
                                    <td>
                                        <input class="form-check-input order-check" type="checkbox" name="order_ids[]"
                                               value="{{ $order->id }}" aria-label="Select {{ $order->order_no }}">
                                    </td>
                                    <td><code>{{ $order->order_no }}</code></td>
                                    <td>{{ $order->status }}</td>
                                    <td class="text-end">{{ number_format((float) $order->grand_total, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">No open orders.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Order</th>
                        <th>Courier</th>
                        <th>Status</th>
                        <th class="text-end">Lines</th>
                        <th>External ref</th>
                        <th>Dispatched</th>
                        @if ($perm('sales.delivery.shipments.create') || $perm('sales.delivery.view'))
                            <th class="text-end">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shipments as $shipment)
                        <tr>
                            <td>{{ $shipment->id }}</td>
                            <td>
                                <a href="{{ route('sales.orders.show', $shipment->sales_order_id) }}">
                                    <code>{{ $shipment->order?->order_no ?? '—' }}</code>
                                </a>
                            </td>
                            <td>{{ $shipment->courier?->name ?? '—' }}</td>
                            <td>
                                <span class="erp-status {{
                                    $shipment->status === 'dispatched' ? 'erp-status-active'
                                        : ($shipment->status === 'assigned' ? 'erp-status-pending' : 'erp-status-disabled')
                                }}">{{ str_replace('_', ' ', $shipment->status) }}</span>
                            </td>
                            <td class="text-end">{{ $shipment->lines->count() }}</td>
                            <td class="small">{{ $shipment->external_ref ?? '—' }}</td>
                            <td class="small">{{ $shipment->dispatched_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            @if ($perm('sales.delivery.shipments.create') || $perm('sales.delivery.view'))
                                <td class="text-end">
                                    @if ($perm('sales.delivery.view'))
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="{{ route('sales.shipments.tracking', $shipment) }}">Track</a>
                                    @endif
                                    @if ($perm('sales.delivery.shipments.create'))
                                        @if ($shipment->status !== 'dispatched')
                                            <form method="POST"
                                                  action="{{ route('sales.shipments.dispatch', $shipment) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Dispatch</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">shipped</span>
                                        @endif
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No shipments yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
