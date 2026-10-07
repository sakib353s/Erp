@extends('layouts.app')

@section('page_title', 'Route Optimization')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Route Optimization</h1>
            <p class="erp-page-sub">Deterministic stop order per rider — every position is explained, nothing is estimated.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">How this order was produced</h2>
        <p class="mb-1">{{ $plan['method_note'] }}</p>
        <p class="mb-1 text-muted">{{ $plan['distance_note'] }}</p>
        <p class="small text-muted mb-0">
            Generated {{ $plan['generated_at'] }} ·
            {{ $plan['totals']['stops'] }} stop(s) across {{ $plan['totals']['riders'] }} rider(s) ·
            {{ $plan['totals']['unassigned_open_shipments'] }} open shipment(s) with no rider assignment (not part of any route) ·
            {{ $plan['totals']['closed_shipments_excluded'] }} assigned shipment(s) already delivered or failed (excluded)
        </p>
    </div>

    @forelse ($plan['groups'] as $group)
        <div class="erp-card mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="erp-h3 mb-0">
                    {{ $group['rider_label'] }}
                    <span class="badge text-bg-light border">{{ $group['stop_count'] }} stop(s)</span>
                </h2>
                <span class="small text-muted">{{ $group['position']['text'] }}</span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Seq</th>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>District</th>
                            <th>Zone</th>
                            <th>Shipment</th>
                            <th>Assignment</th>
                            <th>Why here</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['stops'] as $stop)
                            <tr>
                                <td class="fw-semibold">{{ $stop['seq'] }}</td>
                                <td>{{ $stop['order_no'] }}</td>
                                <td>{{ $stop['customer_name'] }}</td>
                                <td>{{ $stop['district_name'] ?? '—' }}</td>
                                <td>
                                    @if ($stop['zone_name'] !== null)
                                        <span class="badge text-bg-light border">{{ $stop['zone_name'] }}</span>
                                    @else
                                        <span class="text-muted small">No zone</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="erp-status erp-status-pending">{{ $stop['shipment_status'] }}</span>
                                </td>
                                <td>
                                    <span class="erp-status {{
                                        $stop['assignment_status'] === 'accepted' ? 'erp-status-active' : 'erp-status-pending'
                                    }}">{{ $stop['assignment_status'] }}</span>
                                </td>
                                <td class="small">{{ $stop['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="erp-card">
            <p class="text-muted mb-0">No assigned stops to route yet — assign riders from the Rider Assignment screen first.</p>
        </div>
    @endforelse

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
