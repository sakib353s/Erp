@extends('layouts.app')

@section('page_title', 'Sales Trend')

@php
    $trend = $report['trend'];
    $totals = $report['totals'];
    $maxRevenue = $report['series']->max('revenue') ?: 0;
    $directionLabels = ['up' => 'Upward', 'down' => 'Downward', 'flat' => 'Flat'];
@endphp

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Trend</h1>
            <p class="erp-page-sub">
                {{ $report['filters']['date_from'] }} → {{ $report['filters']['date_to'] }}
                · {{ $totals['days'] }} days · sample {{ $trend['sample'] }}/{{ $trend['min_sample'] }}
                · {{ $trend['method'] }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.summary') }}">Summary</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.peak-hours') }}">Peak hours</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="date_from">From</label>
                <input class="form-control" id="date_from" name="date_from" type="date"
                       value="{{ $report['filters']['date_from'] }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="date_to">To</label>
                <input class="form-control" id="date_to" name="date_to" type="date"
                       value="{{ $report['filters']['date_to'] }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Direction</div>
                <div class="fs-5 fw-semibold">
                    @if ($trend['status'] === 'ok')
                        {{ $directionLabels[$trend['direction']] }}
                        <span class="text-muted fs-6">
                            (slope {{ $trend['slope'] >= 0 ? '+' : '' }}{{ number_format($trend['slope'], 2) }}/day)
                        </span>
                    @else
                        Not enough data
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Sample</div>
                <div class="fs-5 fw-semibold">
                    {{ $trend['sample'] }} of {{ $trend['min_sample'] }} days
                    <span class="text-muted fs-6">(min sample)</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Revenue (window)</div>
                <div class="fs-5 fw-semibold">{{ number_format($totals['revenue'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Invoices (window)</div>
                <div class="fs-5 fw-semibold">{{ $totals['invoices'] }}</div>
            </div>
        </div>
    </div>

    @if ($trend['status'] !== 'ok')
        <div class="alert alert-warning" role="alert">
            {{ $trend['reason'] }}
            No trend direction is claimed for this window.
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Invoices</th>
                        <th class="text-end">Revenue</th>
                        <th>Daily revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['series'] as $point)
                        <tr>
                            <td class="fw-semibold">{{ $point['date'] }}</td>
                            <td class="text-end">{{ $point['invoices'] }}</td>
                            <td class="text-end">{{ number_format($point['revenue'], 2) }}</td>
                            <td style="min-width: 220px">
                                <div class="progress" style="height: 10px">
                                    <div class="progress-bar {{ $point['revenue'] > 0 ? '' : 'bg-secondary' }}"
                                         role="progressbar"
                                         style="width: {{ $maxRevenue > 0 ? round($point['revenue'] / $maxRevenue * 100) : 0 }}%"
                                         aria-valuenow="{{ $point['revenue'] }}" aria-valuemin="0"
                                         aria-valuemax="{{ $maxRevenue }}"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No days in this window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        Method: {{ $trend['method'] }} linear trend over daily invoice revenue
        (issued/partial/paid, {{ $totals['days'] }} calendar days, {{ $totals['observation_days'] }}
        observation days with data); min sample {{ $trend['min_sample'] }} observation days before a direction
        is claimed. Daily values are materialized from invoices into
        <code>bi_metrics_daily</code> on load, so the series always equals its source. Days without invoices are
        real zeros, not synthetic rows.
    </p>
@endsection
