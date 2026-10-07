@extends('layouts.app')

@php
    $titles = [
        'product' => 'Sales by Product',
        'category' => 'Sales by Category',
        'brand' => 'Sales by Brand',
        'customer' => 'Sales by Customer',
        'employee' => 'Sales by Employee',
        'branch' => 'Sales by Branch',
        'zone' => 'Sales by Area/Zone',
        'method' => 'Sales by Payment Method',
    ];
    $dimLabels = [
        'product' => 'Product',
        'category' => 'Category',
        'brand' => 'Brand',
        'customer' => 'Customer',
        'employee' => 'Employee',
        'branch' => 'Branch',
        'zone' => 'Zone',
        'method' => 'Method',
    ];
    $isLines = $report['source'] === 'lines';
    $isPayments = $report['source'] === 'payments';
    $colspan = $isPayments ? 4 : ($isLines ? ($report['dim'] === 'product' ? 6 : 5) : 7);
@endphp

@section('page_title', $titles[$report['dim']])

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $titles[$report['dim']] }}</h1>
            <p class="erp-page-sub">
                {{ $report['filters']['date_from'] }} → {{ $report['filters']['date_to'] }}
                ·
                @if ($isPayments)
                    {{ $report['totals']['payments'] }} receipts · {{ $report['totals']['rows'] }} methods
                @else
                    {{ $report['totals']['invoices'] }} invoices · {{ $report['totals']['rows'] }} {{ $report['dim'] }} rows
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.summary') }}">Summary</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.invoice-aging') }}">Aging</a>
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
            <div class="col-md-4 text-end">
                <div class="btn-group flex-wrap justify-content-end" role="group" aria-label="Breakdown dimensions">
                    @foreach (\App\Domain\Reporting\SalesBreakdownReport::DIMS as $dim)
                        @continue($dim === 'branch' && ! ($can_compare_branch ?? false))
                        <a class="btn btn-sm {{ $report['dim'] === $dim ? 'btn-primary' : 'btn-outline-secondary' }}"
                           href="{{ route('sales.reports.by-'.$dim, request()->query()) }}">
                            {{ $dimLabels[$dim] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </form>
    </div>

    @if ($isLines)
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="erp-card">
                    <div class="text-muted small">Line net (excl. tax)</div>
                    <div class="fs-5 fw-semibold">{{ number_format($report['totals']['net'], 2) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="erp-card">
                    <div class="text-muted small">Quantity</div>
                    <div class="fs-5 fw-semibold">{{ number_format($report['totals']['qty'], 2) }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="erp-card">
                    <div class="text-muted small">Invoices</div>
                    <div class="fs-5 fw-semibold">{{ $report['totals']['invoices'] }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="erp-card">
                    <div class="text-muted small">Rows</div>
                    <div class="fs-5 fw-semibold">{{ $report['totals']['rows'] }}</div>
                </div>
            </div>
        </div>
    @elseif ($isPayments)
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="erp-card">
                    <div class="text-muted small">Collected</div>
                    <div class="fs-5 fw-semibold">{{ number_format($report['totals']['amount'], 2) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="erp-card">
                    <div class="text-muted small">Receipts</div>
                    <div class="fs-5 fw-semibold">{{ $report['totals']['payments'] }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="erp-card">
                    <div class="text-muted small">Methods</div>
                    <div class="fs-5 fw-semibold">{{ $report['totals']['rows'] }}</div>
                </div>
            </div>
        </div>
    @else
        <div class="row g-3 mb-3">
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
            <div class="col-md-3">
                <div class="erp-card">
                    <div class="text-muted small">Invoices / rows</div>
                    <div class="fs-5 fw-semibold">
                        {{ $report['totals']['invoices'] }} / {{ $report['totals']['rows'] }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ $titles[$report['dim']] }}</th>
                        @if ($isLines && $report['dim'] === 'product')
                            <th>SKU</th>
                        @endif
                        @if ($isPayments)
                            <th class="text-end">Receipts</th>
                            <th class="text-end">Collected</th>
                        @else
                            <th class="text-end">Invoices</th>
                            @if ($isLines)
                                <th class="text-end">Qty</th>
                                <th class="text-end">Line net</th>
                            @else
                                <th class="text-end">Gross</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Due</th>
                            @endif
                        @endif
                        <th class="text-end">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold">{{ $row['label'] }}</td>
                            @if ($isLines && $report['dim'] === 'product')
                                <td><code>{{ $row['code'] ?? '—' }}</code></td>
                            @endif
                            @if ($isPayments)
                                <td class="text-end">{{ $row['payments'] }}</td>
                                <td class="text-end">{{ number_format($row['amount'], 2) }}</td>
                            @else
                                <td class="text-end">{{ $row['invoices'] }}</td>
                                @if ($isLines)
                                    <td class="text-end">{{ number_format($row['qty'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['net'], 2) }}</td>
                                @else
                                    <td class="text-end">{{ number_format($row['gross'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['paid'], 2) }}</td>
                                    <td class="text-end">{{ number_format($row['due'], 2) }}</td>
                                @endif
                            @endif
                            <td class="text-end">{{ number_format($row['share'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $colspan }}"
                                class="text-center text-muted py-4">
                                {{ $isPayments ? 'No receipts in this window.' : 'No revenue lines in this window.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        @if ($isPayments)
            Posted inbound receipts only (void and outbound payments excluded), grouped by the receipt method.
        @elseif ($isLines)
            Line net = qty × unit price − line discount, excluding tax — aggregated straight from invoice lines of
            issued, partial and paid invoices.
        @else
            Invoice totals aggregated from issued, partial and paid invoices;
            @if ($report['dim'] === 'branch')
                the branch rows are limited to branches you may access.
            @elseif ($report['dim'] === 'zone')
                zone attribution follows the customer's district (a district in several zones claims the lowest
                zone id); unzoned customers fall into No zone.
            @endif
        @endif
    </p>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
