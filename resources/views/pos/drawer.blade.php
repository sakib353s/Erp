@extends('layouts.app')

@section('page_title', 'Cash Drawer')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Cash Drawer</h1>
            <p class="erp-page-sub">Live drawer state for the open session — cash events and the audit trail below. Expected cash uses the close formula: opening float + cash sales + cash in − cash out.</p>
        </div>
        <div class="d-flex gap-2">
            @if ($perm('pos.cash_io'))
                <a class="btn btn-outline-secondary" href="{{ route('pos.cash-io.index') }}">Cash In / Cash Out</a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($session === null)
        <div class="erp-card">
            <p class="mb-0">No open cash drawer for this branch. Open a POS session to start drawing cash.</p>
            @if ($perm('pos.sessions.view'))
                <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ route('pos.sessions.index') }}">POS sessions</a>
            @endif
        </div>
    @else
        <div class="erp-card mb-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="erp-h2 mb-1">Session {{ $session->session_no }}</h2>
                    <p class="mb-0 text-muted">
                        Opened {{ $session->opened_at?->toDateTimeString() }}
                        @if ($session->opener)
                            · {{ $session->opener->name }}
                        @endif
                    </p>
                </div>
                <span class="erp-status erp-status-active">{{ $session->status }}</span>
            </div>

            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <tbody>
                        <tr>
                            <td>Opening float</td>
                            <td class="text-end">{{ number_format((float) $session->opening_float, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Cash sales</td>
                            <td class="text-end">{{ number_format((float) $session->cash_sales, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Non-cash sales</td>
                            <td class="text-end">{{ number_format((float) $session->non_cash_sales, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Cash in</td>
                            <td class="text-end">{{ number_format((float) $session->cash_in, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Cash out</td>
                            <td class="text-end">{{ number_format((float) $session->cash_out, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="fw-semibold">Expected cash</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $expected, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-2 mb-0">Counted cash is compared against Expected cash when the session closes (variance = counted − expected).</p>
        </div>

        <div class="erp-card mb-3">
            <h2 class="erp-h2 mb-3">Cash events</h2>
            @if ($events->isEmpty())
                <p class="mb-0 text-muted">No cash movements in this session yet. Record one from Cash In / Cash Out.</p>
            @else
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Entry</th>
                                <th>Direction</th>
                                <th class="text-end">Amount</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr>
                                    <td>{{ $event->created_at?->toDateTimeString() }}</td>
                                    <td>{{ $event->entry_no }}</td>
                                    <td>
                                        <span class="erp-status {{ $event->source_event === 'pos_cash_in' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                            {{ $event->source_event === 'pos_cash_in' ? 'Cash in' : 'Cash out' }}
                                        </span>
                                    </td>
                                    <td class="text-end">{{ number_format((float) $event->total_debit, 2) }}</td>
                                    <td>{{ $event->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small mt-2 mb-0">Each movement posts one balanced Cash in Hand ↔ Bank transfer — internal drawer moves never fabricate income or expense.</p>
            @endif
        </div>

        <div class="erp-card">
            <h2 class="erp-h2 mb-3">Audit trail</h2>
            @if ($trail->isEmpty())
                <p class="mb-0 text-muted">No audit events for this session yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Action</th>
                                <th>Actor</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trail as $event)
                                <tr>
                                    <td>{{ $event->created_at?->toDateTimeString() }}</td>
                                    <td>{{ $event->action }}</td>
                                    <td>{{ $event->actor_label ?? '—' }}</td>
                                    <td class="text-end">{{ $event->amount !== null ? number_format((float) $event->amount, 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
@endsection
