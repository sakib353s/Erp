@extends('layouts.app')

@section('page_title', 'Sales Team')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Team</h1>
            <p class="erp-page-sub">Sales persons flagged on the employee roster · targets and achievement tracked separately.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.targets.index') }}">Targets</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.achievement') }}">Achievement</a>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Name, code, email">
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="salespersons" name="salespersons" value="1" @checked($salespersonsOnly)>
                    <label class="form-check-label" for="salespersons">Sales persons only</label>
                </div>
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
                        <th>Code</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Status</th>
                        <th>Sales person</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr>
                            <td><code>{{ $employee->code }}</code></td>
                            <td>{{ $employee->full_name }}</td>
                            <td>{{ $employee->designation ?? '—' }}</td>
                            <td><span class="erp-status erp-status-active">{{ $employee->employment_status }}</span></td>
                            <td>
                                <span class="erp-status {{ $employee->is_salesperson ? 'erp-status-active' : 'erp-status-inactive' }}">
                                    {{ $employee->is_salesperson ? 'yes' : 'no' }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if ($perm('sales.team.create'))
                                    <form method="POST" action="{{ route('sales.team.store') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                        <input type="hidden" name="is_salesperson" value="{{ $employee->is_salesperson ? 0 : 1 }}">
                                        <button class="btn btn-sm {{ $employee->is_salesperson ? 'btn-outline-secondary' : 'btn-primary' }}" type="submit">
                                            {{ $employee->is_salesperson ? 'Remove' : 'Add as sales person' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No employees yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $employees->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
