@extends('layouts.app')

@section('page_title', 'Sales Leaderboard')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Leaderboard</h1>
            <p class="erp-page-sub">
                Period {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }}
                · {{ $report['period_type'] }}
                · sample size {{ $report['sample_size'] }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.index') }}">Sales persons</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.performance') }}">Performance</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.achievement') }}">Achievement</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="period_type">Period type</label>
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
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Total revenue</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['totals']['revenue'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Invoices ({{ $report['totals']['invoice_count'] }})</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['totals']['invoice_count'], 0) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Sales persons on board</div>
                <div class="fs-4 fw-semibold">{{ $report['totals']['salespersons'] }}</div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Sales person</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Invoices</th>
                        <th class="text-end">Avg invoice</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td>{{ $row['rank'] }}</td>
                            <td>
                                {{ $row['employee']->full_name }}
                                <code class="ms-1">{{ $row['employee']->code }}</code>
                            </td>
                            <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end">{{ $row['invoice_count'] }}</td>
                            <td class="text-end">{{ number_format($row['avg_invoice'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['paid'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['due'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No sales persons or attributed invoices for this period (no fake data).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="small text-muted mb-0 mt-3">
            Method: <code>{{ $report['method'] }}</code>
            · Period {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }}
            · Rows with sales in period: {{ $report['sample_size'] }}
        </p>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
