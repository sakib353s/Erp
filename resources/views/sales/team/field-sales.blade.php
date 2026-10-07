@extends('layouts.app')

@section('page_title', 'Field Sales')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Field Sales</h1>
            <p class="erp-page-sub">Field visits, attributed orders, and calls per salesperson — real rows only.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.field-visits.index') }}">Field visits</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.calls.index') }}">Call log</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="period_type">Period</label>
                <select class="form-select" id="period_type" name="period_type">
                    @foreach (['daily', 'monthly', 'yearly'] as $p)
                        <option value="{{ $p }}" @selected($report['period_type'] === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="at">As of</label>
                <input class="form-control" id="at" name="at" type="date" value="{{ $at }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Apply</button>
            </div>
        </form>
        <p class="small text-muted mb-0 mt-2">
            Window {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }} ·
            sample {{ $report['sample_size'] }} ·
            method: {{ $report['method'] }}
        </p>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sales person</th>
                        <th class="text-end">Visits</th>
                        <th class="text-end">Completed</th>
                        <th class="text-end">Orders</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Calls</th>
                        <th class="text-end">Visits/Order</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td>{{ $row['employee']->full_name }}</td>
                            <td class="text-end">{{ $row['visit_count'] }}</td>
                            <td class="text-end">{{ $row['completed_visits'] }}</td>
                            <td class="text-end">{{ $row['order_count'] }}</td>
                            <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end">{{ $row['call_count'] }}</td>
                            <td class="text-end">{{ $row['visits_per_order'] !== null ? number_format($row['visits_per_order'], 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No field activity in this window.</td>
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
