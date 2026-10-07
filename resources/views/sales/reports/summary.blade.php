@extends('layouts.app')

@section('page_title', 'Sales Summary')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Summary</h1>
            <p class="erp-page-sub">
                {{ $report['filters']['date_from'] }} → {{ $report['filters']['date_to'] }}
                · {{ $report['totals']['invoices'] }} invoices
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.invoice-aging') }}">Invoice aging</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.promotions') }}">Promotion reports</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.invoices.index') }}">Invoices</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label" for="date_from">From</label>
                <input class="form-control" id="date_from" name="date_from" type="date"
                       value="{{ $report['filters']['date_from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_to">To</label>
                <input class="form-control" id="date_to" name="date_to" type="date"
                       value="{{ $report['filters']['date_to'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="customer_id">Customer id</label>
                <input class="form-control" id="customer_id" name="customer_id" type="number" min="1"
                       value="{{ $report['filters']['customer_id'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (\App\Domain\Sales\Invoice::STATUSES as $s)
                        <option value="{{ $s }}" @selected($report['filters']['status'] === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="method">Payment method</label>
                <select class="form-select" id="method" name="method">
                    <option value="">All</option>
                    @foreach (['cash', 'bank', 'cheque', 'mobile'] as $m)
                        <option value="{{ $m }}" @selected($report['filters']['method'] === $m)>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-secondary w-100" type="submit">Apply</button>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Invoices</div>
                <div class="fs-5 fw-semibold">{{ $report['totals']['invoices'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Gross sales</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['gross'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Collected</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['paid'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="erp-card">
                <div class="text-muted small">Outstanding</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['due'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Payments received ({{ $report['payments']['count'] }})</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['payments']['amount'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Subtotal</div>
                <div class="fs-5 fw-semibold">{{ number_format($report['totals']['subtotal'], 2) }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="erp-card">
                <div class="text-muted small">Discount / Tax</div>
                <div class="fs-5 fw-semibold">
                    {{ number_format($report['totals']['discount'], 2) }} / {{ number_format($report['totals']['tax'], 2) }}
                </div>
            </div>
        </div>
    </div>

    @if ($report['by_status'] !== [])
        <div class="erp-card mb-3">
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th class="text-end">Invoices</th>
                            <th class="text-end">Gross</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['by_status'] as $status => $agg)
                            <tr>
                                <td>{{ $status }}</td>
                                <td class="text-end">{{ $agg['invoices'] }}</td>
                                <td class="text-end">{{ number_format($agg['gross'], 2) }}</td>
                                <td class="text-end">{{ number_format($agg['paid'], 2) }}</td>
                                <td class="text-end">{{ number_format($agg['due'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td>{{ $row['invoice_no'] }}</td>
                            <td>{{ $row['invoice_date'] }}</td>
                            <td>{{ $row['customer'] ?? '—' }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td class="text-end">{{ number_format($row['gross'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['paid'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['due'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No invoices in this window.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        Every figure aggregates invoices and posted receipts directly — no synthetic rows.
    </p>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
