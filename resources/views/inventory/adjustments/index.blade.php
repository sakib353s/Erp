@extends('layouts.app')

@section('page_title', 'Stock Adjustments')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Stock Adjustments</h1>
            <p class="erp-page-sub">Posted adjustments create ADJUST_IN/ADJUST_OUT ledger movements with reason.</p>
        </div>
        @if ($perm('inventory.adjustments.create'))
            <a class="btn btn-primary" href="{{ route('inventory.adjustments.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New adjustment
            </a>
        @endif
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Number</th>
                        <th>Date</th>
                        <th>Warehouse</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th class="text-end">Lines</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($adjustments as $adjustment)
                        <tr>
                            <td><code>{{ $adjustment->adjustment_no }}</code></td>
                            <td>{{ $adjustment->adjustment_date?->format('d M Y') }}</td>
                            <td>{{ $adjustment->warehouse?->name }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($adjustment->reason, 60) }}</td>
                            <td>
                                <span class="erp-status erp-status-{{ str_replace('_', '-', strtolower((string) ($adjustment->status))) }}">{{ $adjustment->status }}</span>
                            </td>
                            <td class="text-end">{{ $adjustment->lines->count() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-body-secondary">No adjustments yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            {{ $adjustments->links() }}
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
