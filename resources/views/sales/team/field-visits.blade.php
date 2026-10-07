@extends('layouts.app')

@section('page_title', 'Field Visits')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Field Visit Log &amp; GPS</h1>
            <p class="erp-page-sub">Visits per rep — GPS points stored only with approved consent.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.beat-plans.index') }}">Beat plans</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.field-sales') }}">Field sales</a>
        </div>
    </div>

    @if ($perm('sales.team.field_tracking'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Plan visit</h2>
            <form method="POST" action="{{ route('sales.team.field-visits.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="employee_id">Sales person</label>
                    <select class="form-select" id="employee_id" name="employee_id" required>
                        <option value="">Select…</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                {{ $employee->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="visit_date">Visit date</label>
                    <input class="form-control" id="visit_date" name="visit_date" type="date" value="{{ old('visit_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="purpose">Purpose</label>
                    <input class="form-control" id="purpose" name="purpose" type="text" maxlength="64" value="{{ old('purpose') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="location_note">Location note</label>
                    <input class="form-control" id="location_note" name="location_note" type="text" maxlength="255" value="{{ old('location_note') }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="gps_consent" name="gps_consent" value="1" @checked(old('gps_consent'))>
                        <label class="form-check-label" for="gps_consent">GPS consent</label>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Plan</button>
                </div>
                @if ($errors->has('visit'))
                    <div class="col-12 text-danger small">{{ $errors->first('visit') }}</div>
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
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (\App\Domain\Sales\FieldVisit::STATUSES as $s)
                        <option value="{{ $s }}" @selected($statusFilter === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Sales person</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Purpose</th>
                        <th>GPS</th>
                        <th>Consent</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($visits as $visit)
                        <tr>
                            <td>{{ optional($visit->visit_date)->toDateString() }}</td>
                            <td>{{ $visit->employee?->full_name ?? '—' }}</td>
                            <td>{{ $visit->customer?->name ?? '—' }}</td>
                            <td>{{ $visit->status }}</td>
                            <td>{{ $visit->purpose }}</td>
                            <td>
                                @if ($visit->gps_consent && $visit->latitude !== null)
                                    {{ $visit->latitude }}, {{ $visit->longitude }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $visit->gps_consent ? 'Yes' : 'No' }}</td>
                            <td class="text-end">
                                @if ($perm('sales.team.field_tracking') && in_array($visit->status, ['planned', 'in_progress'], true))
                                    @if ($visit->status === 'planned')
                                        <form method="POST" action="{{ route('sales.team.field-visits.transition', $visit) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="action" value="start">
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Start</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('sales.team.field-visits.transition', $visit) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="action" value="complete">
                                        <button class="btn btn-sm btn-primary" type="submit">Complete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No field visits.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $visits->links() }}</div>
    </div>

    <div class="erp-card">
        <h2 class="erp-h3 mb-2">GPS track replay (consented only)</h2>
        <p class="small text-muted">method: gps_points joined to field_visits where gps_consent = true · sample {{ $gpsPoints->count() }}</p>
        @if ($gpsPoints->isEmpty())
            <p class="text-muted mb-0">No consented GPS points.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Captured</th>
                            <th>Visit</th>
                            <th>Employee</th>
                            <th class="text-end">Lat</th>
                            <th class="text-end">Lng</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($gpsPoints as $point)
                            <tr>
                                <td>{{ $point->captured_at?->toDateTimeString() }}</td>
                                <td>{{ $point->field_visit_id }}</td>
                                <td>{{ $point->employee?->full_name ?? '—' }}</td>
                                <td class="text-end">{{ $point->latitude }}</td>
                                <td class="text-end">{{ $point->longitude }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
