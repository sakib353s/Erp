@extends('layouts.app')

@section('page_title', 'Beat Plans')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Beat Plans</h1>
            <p class="erp-page-sub">Planned customer routes per salesperson — ordered stops, DOC only.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.field-visits.index') }}">Field visits</a>
        </div>
    </div>

    @if ($perm('sales.team.field_tracking'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">New beat plan</h2>
            <form method="POST" action="{{ route('sales.team.beat-plans.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="employee_id">Sales person</label>
                    <select class="form-select" id="employee_id" name="employee_id" required>
                        <option value="">Select…</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="160" value="{{ old('name') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="plan_date">Plan date</label>
                    <input class="form-control" id="plan_date" name="plan_date" type="date" value="{{ old('plan_date', now()->toDateString()) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="stop_label">First stop label</label>
                    <input class="form-control" id="stop_label" name="stops[0][label]" type="text" maxlength="200" value="{{ old('stops.0.label') }}" required>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Create</button>
                </div>
                @if ($errors->has('beat'))
                    <div class="col-12 text-danger small">{{ $errors->first('beat') }}</div>
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
                        <th>Name</th>
                        <th>Sales person</th>
                        <th>Status</th>
                        <th class="text-end">Stops</th>
                        <th>Route</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($plans as $plan)
                        <tr>
                            <td>{{ optional($plan->plan_date)->toDateString() }}</td>
                            <td>{{ $plan->name }}</td>
                            <td>{{ $plan->employee?->full_name ?? '—' }}</td>
                            <td>{{ $plan->status }}</td>
                            <td class="text-end">{{ $plan->stops->count() }}</td>
                            <td>
                                @foreach ($plan->stops as $stop)
                                    <span class="badge text-bg-light">{{ $stop->sequence_no }}. {{ $stop->label ?? ($stop->customer?->name ?? 'stop') }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No beat plans.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $plans->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
