@extends('layouts.app')

@section('page_title', 'Commission Rules')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Commission Rules</h1>
            <p class="erp-page-sub">Percent-of-revenue or fixed-period rules — config only, no GL until calculate/pay.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.commissions.index') }}">Commissions</a>
        </div>
    </div>

    @if ($perm('sales.team.commissions'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Add rule</h2>
            <form method="POST" action="{{ route('sales.team.commission-rules.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="employee_id">Sales person</label>
                    <select class="form-select" id="employee_id" name="employee_id">
                        <option value="">All sales persons</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>
                                {{ $employee->full_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="rule_type">Rule type</label>
                    <select class="form-select" id="rule_type" name="rule_type" required>
                        <option value="percent_of_revenue" @selected(old('rule_type', 'percent_of_revenue') === 'percent_of_revenue')>percent_of_revenue</option>
                        <option value="fixed_per_period" @selected(old('rule_type') === 'fixed_per_period')>fixed_per_period</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="rate">Rate %</label>
                    <input class="form-control" id="rate" name="rate" type="number" step="0.01" min="0" max="100" value="{{ old('rate', 0) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fixed_amount">Fixed amount</label>
                    <input class="form-control" id="fixed_amount" name="fixed_amount" type="number" step="0.01" min="0" value="{{ old('fixed_amount', 0) }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="period_type">Period</label>
                    <select class="form-select" id="period_type" name="period_type" required>
                        @foreach (['daily', 'monthly', 'yearly'] as $p)
                            <option value="{{ $p }}" @selected(old('period_type', 'monthly') === $p)>{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex align-items-center gap-3">
                    <button class="btn btn-primary" type="submit">Save rule</button>
                    @if ($errors->has('rule'))
                        <span class="text-danger small">{{ $errors->first('rule') }}</span>
                    @endif
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Sales person</th>
                        <th>Type</th>
                        <th class="text-end">Rate %</th>
                        <th class="text-end">Fixed</th>
                        <th>Period</th>
                        <th>Active</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td>{{ $rule->name }}</td>
                            <td>{{ $rule->employee?->full_name ?? 'All sales persons' }}</td>
                            <td>{{ $rule->rule_type }}</td>
                            <td class="text-end">{{ number_format((float) $rule->rate, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $rule->fixed_amount, 2) }}</td>
                            <td>{{ $rule->period_type }}</td>
                            <td>{{ $rule->is_active ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No commission rules.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rules->links() }}</div>
    </div>
@endsection
