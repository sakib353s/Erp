@extends('layouts.app')

@section('page_title', 'Cash In / Cash Out')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Cash In / Cash Out</h1>
            <p class="erp-page-sub">Record a drawer movement against the open session. Cash In adds to the drawer, Cash Out removes from it — both feed the close math and post one balanced Cash in Hand ↔ Bank transfer.</p>
        </div>
        <div class="d-flex gap-2">
            @if ($perm('pos.cash_drawer'))
                <a class="btn btn-outline-secondary" href="{{ route('pos.drawer') }}">Cash Drawer</a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('pos.terminal') }}">Terminal</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @if ($session === null)
        <div class="erp-card">
            <p class="mb-0">No open cash drawer for this branch. Open a POS session before moving cash.</p>
            @if ($perm('pos.sessions.view'))
                <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ route('pos.sessions.index') }}">POS sessions</a>
            @endif
        </div>
    @else
        <div class="erp-card mb-3">
            <h2 class="erp-h2 mb-3">Record movement — session {{ $session->session_no }}</h2>
            <form method="POST" action="{{ route('pos.cash-io.store') }}">
                @csrf
                <input type="hidden" name="pos_session_id" value="{{ $session->id }}">

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="direction">Direction</label>
                        <select class="form-select" id="direction" name="direction" required>
                            <option value="in" @selected(old('direction') === 'in')>Cash In (received into the drawer)</option>
                            <option value="out" @selected(old('direction') === 'out')>Cash Out (paid out of the drawer)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="amount">Amount</label>
                        <input class="form-control" type="number" id="amount" name="amount"
                               min="0.01" max="10000000" step="0.01" required
                               value="{{ old('amount') }}" placeholder="0.00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reason">Reason</label>
                        <input class="form-control" type="text" id="reason" name="reason"
                               maxlength="140" required value="{{ old('reason') }}"
                               placeholder="e.g. Float from safe, safe drop">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100" type="submit">Record</button>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">A reason is required — every movement is audited and posted against this session.</p>
            </form>

            <div class="mt-4">
                <h3 class="erp-h3 mb-2">Drawer state</h3>
                <p class="mb-0 text-muted">
                    Opening float {{ number_format((float) $session->opening_float, 2) }} ·
                    Cash sales {{ number_format((float) $session->cash_sales, 2) }} ·
                    Cash in {{ number_format((float) $session->cash_in, 2) }} ·
                    Cash out {{ number_format((float) $session->cash_out, 2) }} ·
                    Expected cash <strong>{{ number_format((float) $expected, 2) }}</strong>
                </p>
            </div>
        </div>

        <div class="erp-card">
            <h2 class="erp-h2 mb-3">Cash events</h2>
            @if ($events->isEmpty())
                <p class="mb-0 text-muted">No cash movements in this session yet.</p>
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
            @endif
        </div>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
