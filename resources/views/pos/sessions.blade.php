@extends('layouts.app')

@section('page_title', 'POS Sessions')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Sessions</h1>
            <p class="erp-page-sub">Open/close with counted cash and variance.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Session #</th>
                        <th>Opened</th>
                        <th>Closed</th>
                        <th>Status</th>
                        <th class="text-end">Float</th>
                        <th class="text-end">Cash sales</th>
                        <th class="text-end">Expected</th>
                        <th class="text-end">Counted</th>
                        <th class="text-end">Variance</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sessions as $session)
                        <tr>
                            <td><code>{{ $session->session_no }}</code></td>
                            <td>{{ optional($session->opened_at)->format('Y-m-d H:i') }}</td>
                            <td>{{ optional($session->closed_at)->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>
                                <span class="erp-status {{ $session->status === 'open' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $session->status }}
                                </span>
                            </td>
                            <td class="text-end">{{ $session->opening_float !== null ? number_format((float) $session->opening_float, 2) : '—' }}</td>
                            <td class="text-end">{{ number_format((float) $session->cash_sales, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $session->expected_cash, 2) }}</td>
                            <td class="text-end">{{ $session->closing_counted !== null ? number_format((float) $session->closing_counted, 2) : '—' }}</td>
                            <td class="text-end">{{ $session->status === 'closed' ? number_format((float) $session->variance, 2) : '—' }}</td>
                            <td class="text-end text-nowrap">
                                @if ($perm('pos.reports.x'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('pos.sessions.x-report', $session) }}">X</a>
                                @endif
                                @if ($perm('pos.reports.z'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('pos.sessions.z-report', $session) }}">Z</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">No POS sessions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $sessions->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
