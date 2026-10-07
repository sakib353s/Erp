@extends('layouts.app')

@section('page_title', 'Failed Deliveries')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Failed Delivery Management</h1>
            <p class="erp-page-sub">Open delivery failures waiting for a decision — retry with the courier, return the goods to the warehouse, or reship. Stock reverses only on return/reship (the shipment's own dispatch), and nothing here notifies the customer.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->has('failed_delivery'))
        <div class="alert alert-warning">{{ $errors->first('failed_delivery') }}</div>
    @endif

    <div class="erp-card">
        <h2 class="erp-h3">Open failures</h2>
        @if ($open->isEmpty())
            <p class="text-muted mb-0">No failed deliveries — every dispatched shipment is on track.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Failed at</th>
                            <th>Shipment</th>
                            <th>Order</th>
                            <th>Courier</th>
                            <th>Attempt</th>
                            <th>Reason</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($open as $failure)
                            <tr>
                                <td>{{ $failure->failed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    Shipment #{{ $failure->shipment_id }}
                                    <span class="small text-muted">{{ $failure->shipment?->external_ref ?? '—' }}</span>
                                </td>
                                <td>
                                    {{ $failure->order?->order_no ?? '—' }}
                                    <span class="small text-muted">{{ $failure->order?->customer?->name ?? '' }}</span>
                                </td>
                                <td>{{ $failure->courier?->name ?? '—' }}</td>
                                <td>{{ $failure->attempt_no }}</td>
                                <td class="small">{{ $failure->reason ?? '—' }}</td>
                                <td>
                                    <div class="d-flex justify-content-end gap-1">
                                        <form method="POST" action="{{ route('sales.delivery.failed.retry', $failure) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Retry</button>
                                        </form>
                                        <form method="POST" action="{{ route('sales.delivery.failed.return', $failure) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Return</button>
                                        </form>
                                        <form method="POST" action="{{ route('sales.delivery.failed.reship', $failure) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Reship</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0 mt-2">Retry puts the same shipment back out with the courier (no stock movement). Return brings the goods back into stock (reverses this shipment's dispatch). Reship returns the goods, closes the failed shipment, and creates its replacement. An issued invoice means the return belongs to the sales return flow — this screen says so instead of forcing a reversal.</p>
        @endif
    </div>
@endsection
