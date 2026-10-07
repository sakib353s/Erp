@extends('layouts.app')

@section('page_title', 'Order '.$order->order_no)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Order {{ $order->order_no }}</h1>
            <p class="erp-page-sub">
                Status: <span class="erp-status erp-status-active">{{ $order->status }}</span>
                · {{ optional($order->order_date)->toDateString() }}
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($perm('sales.orders.edit') && $order->status === 'pending')
                <a class="btn btn-outline-secondary" href="{{ route('sales.orders.edit', $order) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            @if ($perm('sales.orders.confirm') && in_array($order->status, ['pending'], true))
                <form method="POST" action="{{ route('sales.orders.confirm', $order) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Confirm &amp; reserve</button>
                </form>
            @endif
            @if ($perm('sales.invoices.create') && in_array($order->status, ['confirmed', 'processing', 'ready_to_ship', 'delivered'], true))
                <form method="POST" action="{{ route('sales.orders.invoice', $order) }}">
                    @csrf
                    <button class="btn btn-outline-primary" type="submit">Create invoice</button>
                </form>
            @endif
            @if ($perm('sales.orders.cancel') && ! in_array($order->status, ['cancelled', 'completed'], true))
                <form method="POST" action="{{ route('sales.orders.cancel', $order) }}">
                    @csrf
                    <button class="btn btn-outline-danger" type="submit">Cancel</button>
                </form>
            @endif
            @if ($perm('sales.delivery.create') && in_array($order->status, ['confirmed', 'processing', 'ready_to_ship', 'picked_up', 'in_transit', 'out_for_delivery'], true))
                <form method="POST" action="{{ route('sales.orders.challan', $order) }}">
                    @csrf
                    <button class="btn btn-outline-primary" type="submit">Delivery challan</button>
                </form>
            @endif
            @if ($perm('sales.delivery.view'))
                <a class="btn btn-outline-secondary" href="{{ route('sales.delivery-challans.index') }}">Challans</a>
            @endif
            @if ($perm('returns.view'))
                <a class="btn btn-outline-secondary" href="{{ route('sales.returns.index') }}">Returns</a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Lines</h2>
                <div class="table-responsive">
                    <table class="table erp-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->lines as $line)
                                <tr>
                                    <td>{{ $line->line_no }}</td>
                                    <td>{{ $line->product?->name ?? '—' }}</td>
                                    <td class="text-end">{{ $line->qty }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Totals</h2>
                <dl class="mb-0">
                    <div class="d-flex justify-content-between"><dt>Subtotal</dt><dd>{{ number_format((float) $order->subtotal, 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Tax</dt><dd>{{ number_format((float) $order->tax, 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Shipping</dt><dd>{{ number_format((float) $order->shipping, 2) }}</dd></div>
                    <div class="d-flex justify-content-between fw-bold border-top pt-2 mt-2">
                        <dt>Grand total</dt>
                        <dd>{{ number_format((float) $order->grand_total, 2) }}</dd>
                    </div>
                </dl>
                @if ($order->customer)
                    <hr>
                    <p class="mb-0 small text-muted">Customer: {{ $order->customer->name }}</p>
                @endif
            </div>

            @if ($order->invoices->isNotEmpty())
                <div class="erp-card mt-3">
                    <h2 class="erp-h3 mb-2">Invoices</h2>
                    <ul class="list-unstyled mb-0">
                        @foreach ($order->invoices as $inv)
                            <li>
                                <a href="{{ route('sales.invoices.show', $inv) }}">{{ $inv->invoice_no }}</a>
                                — {{ $inv->status }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection
