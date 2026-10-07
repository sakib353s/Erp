@extends('layouts.app')

@section('page_title', 'Invoice Aging')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Invoice Aging</h1>
            <p class="erp-page-sub">Open receivables as of {{ $report['as_of'] }} · {{ $report['totals']['invoices'] }} invoices</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.summary') }}">Sales summary</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.promotions') }}">Promotion reports</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.invoices.index') }}">Invoices</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="as_of">As of</label>
                <input class="form-control" id="as_of" name="as_of" type="date" value="{{ $report['as_of'] }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="branch_id">Branch id (optional)</label>
                <input class="form-control" id="branch_id" name="branch_id" type="number" min="1"
                       value="{{ $report['filters']['branch_id'] }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-3">
        @foreach ($report['buckets'] as $label => $amount)
            <div class="col">
                <div class="erp-card">
                    <div class="text-muted small">
                        {{ $label === 'current' ? 'Current (not yet due)' : $label.' days overdue' }}
                    </div>
                    <div class="fs-5 fw-semibold">{{ number_format($amount, 2) }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="erp-card mb-3">
        <div class="d-flex justify-content-between">
            <div>
                <div class="text-muted small">Total open AR</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['amount'], 2) }}</div>
            </div>
            <div class="text-end">
                <div class="text-muted small">Invoices in scope</div>
                <div class="fs-5 fw-semibold">{{ $report['totals']['invoices'] }}</div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Due date</th>
                        <th class="text-end">Days overdue</th>
                        <th>Bucket</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr @class(['table-danger' => $row['bucket'] !== 'current'])>
                            <td>{{ $row['invoice_no'] }}</td>
                            <td>{{ $row['customer'] ?? '—' }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['due_date'] ?? '—' }}</td>
                            <td class="text-end">{{ $row['days_overdue'] }}</td>
                            <td>{{ $row['bucket'] === 'current' ? 'current' : $row['bucket'].'d' }}</td>
                            <td class="text-end">{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No open balances.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        Buckets are a partition of every open invoice balance as of {{ $report['as_of'] }} — no modelled rows.
    </p>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
