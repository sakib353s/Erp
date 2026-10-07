@extends('layouts.app')

@section('page_title', 'Opening Trial Balance')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Opening Trial Balance</h1>
            <p class="erp-page-sub">
                From OPENING-type journals ·
                @if ($report['is_balanced'])
                    <span class="text-success fw-semibold">D = C ✓</span>
                @else
                    <span class="text-danger fw-semibold">OUT OF BALANCE</span>
                @endif
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('accounting.reports.trial-balance') }}">Full trial balance</a>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Account</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td><code>{{ $row['code'] }}</code></td>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-end">{{ number_format((float) $row['debit'], 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row['credit'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-4 text-body-secondary">No opening balance journals yet.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-end">Total</th>
                        <th class="text-end">{{ number_format((float) $report['total_debit'], 2) }}</th>
                        <th class="text-end">{{ number_format((float) $report['total_credit'], 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
