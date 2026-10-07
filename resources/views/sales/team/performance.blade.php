@extends('layouts.app')

@section('page_title', 'Sales Performance')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Performance</h1>
            <p class="erp-page-sub">
                Period {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }}
                · {{ $report['period_type'] }}
                · sample size {{ $report['sample_size'] }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.leaderboard') }}">Leaderboard</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.achievement') }}">Achievement</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.targets.index') }}">Targets</a>
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
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Total target</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['target'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Total revenue</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['revenue'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Variance</div>
                <div class="fs-5 fw-semibold {{ $report['totals']['variance'] < 0 ? 'text-danger' : 'text-success' }}">
                    {{ number_format($report['totals']['variance'], 2) }}
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Invoices</div>
                <div class="fs-5 fw-semibold">{{ $report['totals']['invoice_count'] }}</div>
            </div>
        </div>
    </div>

    @if (! $report['field_visits_available'])
        <div class="alert alert-secondary py-2 small" role="alert">
            {{ $report['field_visits_note'] }}
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sales person</th>
                        <th class="text-end">Target</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Variance</th>
                        <th class="text-end">%</th>
                        <th class="text-end">Invoices</th>
                        <th class="text-end">Avg</th>
                        <th class="text-end">Due</th>
                        @if ($report['field_visits_available'])
                            <th class="text-end">Visits</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td>
                                {{ $row['employee']->full_name }}
                                <code class="ms-1">{{ $row['employee']->code }}</code>
                                @unless ($row['has_target'])
                                    <span class="erp-status erp-status-inactive ms-1">no target</span>
                                @endunless
                            </td>
                            <td class="text-end">{{ number_format($row['target_amount'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end {{ $row['variance'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ number_format($row['variance'], 2) }}
                            </td>
                            <td class="text-end">
                                {{ $row['pct'] !== null ? number_format($row['pct'], 1).'%' : '—' }}
                            </td>
                            <td class="text-end">{{ $row['invoice_count'] }}</td>
                            <td class="text-end">{{ number_format($row['avg_invoice'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['due'], 2) }}</td>
                            @if ($report['field_visits_available'])
                                <td class="text-end">{{ $row['field_visit_count'] ?? 0 }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $report['field_visits_available'] ? 9 : 8 }}" class="text-center text-muted py-4">
                                No sales persons or targets for this period (no fake data).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="small text-muted mb-0 mt-3">
            Method: <code>{{ $report['method'] }}</code>
            · Period {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }}
            · Rows: {{ $report['sample_size'] }}
        </p>
    </div>
@endsection
