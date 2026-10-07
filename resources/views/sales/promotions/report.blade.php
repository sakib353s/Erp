@extends('layouts.app')

@section('page_title', 'Promotion Reports')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Promotion Reports</h1>
            <p class="erp-page-sub">
                Period {{ $report['bounds']['period_start'] }} → {{ $report['bounds']['period_end'] }}
                · {{ $report['period_type'] }}
                · sample size {{ $report['sample_size'] }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.promotions.index') }}">Promotions</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.promotions.flash') }}">Flash sales</a>
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
                <div class="text-muted small">Promotions</div>
                <div class="fs-5 fw-semibold">{{ $report['totals']['promotions'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Redemptions</div>
                <div class="fs-5 fw-semibold">{{ $report['totals']['redemptions'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Discount given</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['discount'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Attributed revenue</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['attributed_revenue'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Promotion</th>
                        <th>Type / kind</th>
                        <th class="text-end">Priority</th>
                        <th class="text-end">Redemptions</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end">Attributed revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        @php($promotion = $row['promotion'])
                        <tr>
                            <td>{{ $promotion->name }}</td>
                            <td>{{ $promotion->type }} / {{ $promotion->kind }}</td>
                            <td class="text-end">{{ $promotion->priority }}</td>
                            <td class="text-end">{{ $row['redemptions'] }}</td>
                            <td class="text-end">{{ number_format($row['discount'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['attributed_revenue'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No promotions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">method: {{ $report['method'] }}</p>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
