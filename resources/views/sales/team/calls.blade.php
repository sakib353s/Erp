@extends('layouts.app')

@section('page_title', 'Sales Call Log')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Call Log</h1>
            <p class="erp-page-sub">Outbound and inbound calls per salesperson — CRM activity only, no GL.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.field-sales') }}">Field sales</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.index') }}">Sales persons</a>
        </div>
    </div>

    @if ($perm('sales.team.calls'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Log call</h2>
            <form method="POST" action="{{ route('sales.team.calls.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="employee_id">Sales person</label>
                    <select class="form-select" id="employee_id" name="employee_id" required>
                        <option value="">Select…</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                {{ $employee->code }} — {{ $employee->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="direction">Direction</label>
                    <select class="form-select" id="direction" name="direction" required>
                        <option value="outbound" @selected(old('direction', 'outbound') === 'outbound')>outbound</option>
                        <option value="inbound" @selected(old('direction') === 'inbound')>inbound</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="outcome">Outcome</label>
                    <select class="form-select" id="outcome" name="outcome" required>
                        @foreach (\App\Domain\Sales\SalesCallLog::OUTCOMES as $o)
                            <option value="{{ $o }}" @selected(old('outcome', 'connected') === $o)>{{ $o }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="call_date">Call date</label>
                    <input class="form-control" id="call_date" name="call_date" type="date" value="{{ old('call_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="duration_minutes">Min</label>
                    <input class="form-control" id="duration_minutes" name="duration_minutes" type="number" min="0" max="1440" value="{{ old('duration_minutes', 0) }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Log call</button>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="subject">Subject</label>
                    <input class="form-control" id="subject" name="subject" type="text" maxlength="200" value="{{ old('subject') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" type="text" maxlength="2000" value="{{ old('notes') }}">
                </div>
                @if ($errors->has('call'))
                    <div class="col-12 text-danger small">{{ $errors->first('call') }}</div>
                @endif
            </form>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="employee_id">Sales person</label>
                <select class="form-select" id="employee_id" name="employee_id">
                    <option value="">All</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected($employeeFilter == $employee->id)>{{ $employee->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="direction">Direction</label>
                <select class="form-select" id="direction" name="direction">
                    <option value="">All</option>
                    <option value="outbound" @selected($directionFilter === 'outbound')>outbound</option>
                    <option value="inbound" @selected($directionFilter === 'inbound')>inbound</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Sales person</th>
                        <th>Customer</th>
                        <th>Dir</th>
                        <th>Outcome</th>
                        <th>Subject</th>
                        <th class="text-end">Min</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($calls as $call)
                        <tr>
                            <td>{{ optional($call->call_date)->toDateString() }}</td>
                            <td>{{ $call->employee?->full_name ?? '—' }}</td>
                            <td>{{ $call->customer?->name ?? '—' }}</td>
                            <td>{{ $call->direction }}</td>
                            <td>{{ $call->outcome }}</td>
                            <td>{{ $call->subject }}</td>
                            <td class="text-end">{{ (int) $call->duration_minutes }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No calls logged.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $calls->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
