@extends('layouts.app')

@section('page_title', 'Sales Commissions')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Commissions</h1>
            <p class="erp-page-sub">Earned from real attributed invoice revenue — calculate then pay (WF approval when configured).</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.commission-rules.index') }}">Commission rules</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.index') }}">Sales persons</a>
        </div>
    </div>

    @if ($perm('sales.team.commissions'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Calculate commission</h2>
            <form method="POST" action="{{ route('sales.team.commissions.calculate') }}" class="row g-2">
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
                    <label class="form-label" for="period_type">Period</label>
                    <select class="form-select" id="period_type" name="period_type" required>
                        @foreach (['daily', 'monthly', 'yearly'] as $p)
                            <option value="{{ $p }}" @selected(old('period_type', 'monthly') === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="at">As of date</label>
                    <input class="form-control" id="at" name="at" type="date" value="{{ old('at', now()->toDateString()) }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Calculate</button>
                </div>
                @if ($errors->has('commission'))
                    <div class="col-12 text-danger small">{{ $errors->first('commission') }}</div>
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
                        <option value="{{ $employee->id }}" @selected($employeeFilter == $employee->id)>
                            {{ $employee->full_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (['pending', 'accrued', 'pending_approval', 'paid', 'void'] as $s)
                        <option value="{{ $s }}" @selected($statusFilter === $s)>{{ $s }}</option>
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
                        <th class="text-end">Base</th>
                        <th class="text-end">Rate %</th>
                        <th class="text-end">Commission</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($calculations as $calc)
                        <tr>
                            <td>{{ $calc->employee?->full_name ?? '—' }}</td>
                            <td>{{ $calc->period_type }}</td>
                            <td>{{ optional($calc->period_start)->toDateString() }}</td>
                            <td>{{ optional($calc->period_end)->toDateString() }}</td>
                            <td class="text-end">{{ number_format((float) $calc->base_amount, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $calc->rate, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $calc->commission_amount, 2) }}</td>
                            <td>{{ $calc->status }}</td>
                            <td class="text-end">
                                @if ($perm('sales.team.commission_pay') && in_array($calc->status, ['accrued', 'pending_approval'], true))
                                    <form method="POST" action="{{ route('sales.team.commissions.pay', $calc) }}" class="d-inline">
                                        @csrf
                                        <input name="method" type="hidden" value="cash">
                                        <input name="idempotency_key" type="hidden" value="pay-{{ $calc->id }}-{{ \Illuminate\Support\Str::random(8) }}">
                                        <button class="btn btn-sm btn-primary" type="submit">Pay</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No commission calculations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $calculations->links() }}</div>
    </div>
@endsection
