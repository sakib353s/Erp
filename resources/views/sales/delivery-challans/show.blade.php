@extends('layouts.app')

@section('page_title', ($challan->printed_title ?? 'DELIVERY CHALLAN').' '.$challan->challan_no)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $challan->printed_title ?? 'DELIVERY CHALLAN' }} {{ $challan->challan_no }}</h1>
            <p class="erp-page-sub">
                Status: <span class="erp-status erp-status-{{ str_replace('_', '-', strtolower((string) ($challan->status))) }}">{{ $challan->status }}</span>
                · {{ optional($challan->challan_date)->toDateString() }}
                @if ($challan->order)
                    · Order {{ $challan->order->order_no }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($perm('sales.delivery.dispatch') && in_array($challan->status, ['draft', 'ready'], true))
                <form method="POST" action="{{ route('sales.delivery-challans.dispatch', $challan) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary" type="submit">Dispatch</button>
                </form>
            @endif
            @if ($perm('sales.delivery.dispatch') && in_array($challan->status, ['dispatched', 'ready'], true))
                <form method="POST" action="{{ route('sales.delivery-challans.deliver', $challan) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-primary" type="submit">Mark delivered</button>
                </form>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('sales.delivery-challans.index') }}">All challans</a>
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
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($challan->lines as $line)
                                <tr>
                                    <td>{{ $line->line_no }}</td>
                                    <td>{{ $line->product?->name ?? $line->description ?? '—' }}</td>
                                    <td class="text-end">{{ $line->qty }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Details</h2>
                <dl class="mb-0">
                    @if ($challan->courier_name)
                        <div class="d-flex justify-content-between"><dt>Courier</dt><dd>{{ $challan->courier_name }}</dd></div>
                    @endif
                    @if ($challan->tracking_no)
                        <div class="d-flex justify-content-between"><dt>Tracking</dt><dd>{{ $challan->tracking_no }}</dd></div>
                    @endif
                    @if ($challan->notes)
                        <div class="mt-2"><dt>Notes</dt><dd>{{ $challan->notes }}</dd></div>
                    @endif
                </dl>
                <p class="small text-muted mb-0 mt-3">DOC lifecycle — stock/GL posted at invoice issue stage, not at challan create.</p>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
