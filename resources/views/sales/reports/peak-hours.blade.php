@extends('layouts.app')

@section('page_title', 'Peak Hours Analysis')

@php
    $total = $report['totals']['invoices'];
    $maxCount = $report['buckets']->max('count') ?: 0;
@endphp

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Peak Hours Analysis</h1>
            <p class="erp-page-sub">
                {{ $report['filters']['date_from'] }} → {{ $report['filters']['date_to'] }}
                · {{ $total }} invoices sampled
                · {{ $report['filters']['timezone'] }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.summary') }}">Summary</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.by-product') }}">Breakdowns</a>
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
                <div class="text-muted small">Peak hour</div>
                <div class="fs-5 fw-semibold">
                    @if ($report['peak'] !== null)
                        {{ $report['peak']['label'] }}
                        <span class="text-muted fs-6">({{ $report['peak']['count'] }})</span>
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Sample</div>
                <div class="fs-5 fw-semibold">{{ $total }} invoices</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Period</div>
                <div class="fs-6 fw-semibold">
                    {{ $report['filters']['date_from'] }} → {{ $report['filters']['date_to'] }}
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Timezone</div>
                <div class="fs-6 fw-semibold">{{ $report['filters']['timezone'] }}</div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 18%">Hour</th>
                        <th class="text-end" style="width: 10%">Invoices</th>
                        <th class="text-end" style="width: 10%">Share</th>
                        <th>Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['buckets'] as $bucket)
                        @php($isPeak = $report['peak'] !== null && $bucket['hour'] === $report['peak']['hour'])
                        <tr class="{{ $isPeak ? 'table-primary' : '' }}">
                            <td class="fw-semibold">{{ $bucket['label'] }}{{ $isPeak ? ' · peak' : '' }}</td>
                            <td class="text-end">{{ $bucket['count'] }}</td>
                            <td class="text-end">{{ number_format($bucket['share'], 1) }}%</td>
                            <td style="min-width: 220px">
                                <div class="progress" style="height: 10px">
                                    <div class="progress-bar {{ $isPeak ? '' : 'bg-secondary' }}"
                                         role="progressbar"
                                         style="width: {{ $maxCount > 0 ? round($bucket['count'] / $maxCount * 100) : 0 }}%"
                                         aria-valuenow="{{ $bucket['count'] }}" aria-valuemin="0"
                                         aria-valuemax="{{ $maxCount }}"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No buckets in this window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        Hours are read from invoice creation timestamps rendered in {{ $report['filters']['timezone'] }}.
        @if ($total === 0)
            No invoices landed in this window — no peak is claimed.
        @else
            Sample size {{ $total }} invoices; every hour 00–23 is listed and zero rows are real gaps in
            that sample, not synthetic data. Ties resolve to the earliest hour.
        @endif
    </p>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
