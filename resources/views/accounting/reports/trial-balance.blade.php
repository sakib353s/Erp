@extends('layouts.app')

@section('page_title', 'Trial Balance')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Trial Balance</h1>
            <p class="erp-page-sub">
                As at {{ $asAt ?: 'all posted history' }} ·
                @if ($report['is_balanced'])
                    <span class="text-success fw-semibold">Σ Debits = Σ Credits ✓</span>
                @else
                    <span class="text-danger fw-semibold">OUT OF BALANCE</span>
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="date" class="form-control" name="as_at" value="{{ $asAt }}">
                <button class="btn btn-outline-secondary" type="submit">Apply</button>
            </form>
            @if ($perm('accounting.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('accounting.reports.reconcile') }}">Reconcile</a>
            @endif
            @if ($perm('accounting.reports.view'))
                <form method="POST" action="{{ route('accounting.reports.rebuild') }}">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">Rebuild balances</button>
                </form>
            @endif
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td><code>{{ $row['code'] }}</code></td>
                            <td>{{ $row['name'] }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $row['type'] }}</span></td>
                            <td class="text-end">
                                @if ((float) $row['debit'] > 0){{ number_format((float) $row['debit'], 2) }}@endif
                            </td>
                            <td class="text-end">
                                @if ((float) $row['credit'] > 0){{ number_format((float) $row['credit'], 2) }}@endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-body-secondary">No posted activity yet.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-end">Total</th>
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
