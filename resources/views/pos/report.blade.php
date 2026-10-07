@extends('layouts.app')

@section('page_title', 'POS '.$kind.' Report')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS {{ $kind }} Report</h1>
            <p class="erp-page-sub">
                Session <code>{{ $session->session_no }}</code>
                · <span class="erp-status erp-status-active">{{ $session->status }}</span>
                @if ($kind === 'Z' && $session->status !== 'closed')
                    · preview while open
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('pos.sessions.index') }}">Sessions</a>
            <a class="btn btn-outline-primary" href="{{ route('pos.terminal') }}">Terminal</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Sales by method</h2>
                <table class="table erp-table mb-0">
                    <thead>
                        <tr><th>Method</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($report['by_method'] as $method => $total)
                            <tr>
                                <td>{{ $method }}</td>
                                <td class="text-end">{{ number_format((float) $total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-muted py-3">No sales in this session.</td></tr>
                        @endforelse
                        <tr class="fw-bold">
                            <td>Gross total ({{ $report['count'] }} txns)</td>
                            <td class="text-end">{{ number_format((float) $report['gross_total'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Cash position</h2>
                <dl class="mb-0">
                    <div class="d-flex justify-content-between"><dt>Opening float</dt><dd>{{ number_format((float) $report['opening_float'], 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Cash sales</dt><dd>{{ number_format((float) $report['cash_sales'], 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Cash in</dt><dd>{{ number_format((float) $report['cash_in'], 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Cash out</dt><dd>{{ number_format((float) $report['cash_out'], 2) }}</dd></div>
                    <div class="d-flex justify-content-between fw-bold border-top pt-2 mt-2">
                        <dt>Expected cash</dt>
                        <dd>{{ number_format((float) $report['expected_cash'], 2) }}</dd>
                    </div>
                    @if ($report['closing_counted'] !== null)
                        <div class="d-flex justify-content-between"><dt>Counted</dt><dd>{{ number_format((float) $report['closing_counted'], 2) }}</dd></div>
                    @endif
                    @if ($report['variance'] !== null)
                        <div class="d-flex justify-content-between"><dt>Variance</dt><dd>{{ number_format((float) $report['variance'], 2) }}</dd></div>
                    @endif
                    <div class="d-flex justify-content-between"><dt>Non-cash sales</dt><dd>{{ number_format((float) $report['non_cash_sales'], 2) }}</dd></div>
                    <div class="d-flex justify-content-between"><dt>Receipt total</dt><dd>{{ number_format((float) $report['receipt_total'], 2) }}</dd></div>
                </dl>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
