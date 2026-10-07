@extends('layouts.app')

@section('page_title', 'Sales Targets')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Targets</h1>
            <p class="erp-page-sub">Daily, monthly, and yearly targets per sales person — period-scoped from DB dates.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.index') }}">Sales persons</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.achievement') }}">Achievement</a>
        </div>
    </div>

    @if ($perm('sales.team.targets'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Set target</h2>
            <form method="POST" action="{{ route('sales.team.targets.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="employee_id">Employee</label>
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
                    <label class="form-label" for="period_type">Period</label>
                    <select class="form-select" id="period_type" name="period_type" required>
                        @foreach (['daily', 'monthly', 'yearly'] as $p)
                            <option value="{{ $p }}" @selected(old('period_type', 'monthly') === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="target_amount">Target amount</label>
                    <input class="form-control" id="target_amount" name="target_amount" type="number" step="0.01" min="0" value="{{ old('target_amount') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="at">As of date</label>
                    <input class="form-control" id="at" name="at" type="date" value="{{ old('at', now()->toDateString()) }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Save</button>
                </div>
                @if ($errors->has('target'))
                    <div class="col-12 text-danger small">{{ $errors->first('target') }}</div>
                @endif
            </form>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="period_type">Period type</label>
                <select class="form-select" id="period_type" name="period_type">
                    <option value="">All</option>
                    @foreach (['daily', 'monthly', 'yearly'] as $p)
                        <option value="{{ $p }}" @selected($periodType === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="employee_id">Employee</label>
                <select class="form-select" id="employee_id" name="employee_id">
                    <option value="">All</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected($employeeFilter == $employee->id)>
                            {{ $employee->full_name }}
                        </option>
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
                        <th>Employee</th>
                        <th>Period</th>
                        <th>Start</th>
                        <th>End</th>
                        <th class="text-end">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($targets as $target)
                        <tr>
                            <td>{{ $target->employee?->full_name ?? '—' }}</td>
                            <td>{{ $target->period_type }}</td>
                            <td>{{ optional($target->period_start)->toDateString() }}</td>
                            <td>{{ optional($target->period_end)->toDateString() }}</td>
                            <td class="text-end">{{ number_format((float) $target->target_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No targets set.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $targets->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
