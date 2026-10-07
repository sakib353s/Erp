@extends('layouts.app')

@section('page_title', 'Employees')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Employees</h1>
            <p class="erp-page-sub">Employee type is never a hardcoded role — roles live on the linked user account.</p>
        </div>
        @if ($perm('employees.create'))
            <a class="btn btn-primary" href="{{ route('employees.create') }}">
                <i class="bi bi-person-plus" aria-hidden="true"></i> New employee
            </a>
        @endif
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('employees.index') }}">
        <div class="col-sm-5">
            <input class="form-control" type="search" name="q" value="{{ $q }}" placeholder="Search name, code, e-mail…" aria-label="Search employees">
        </div>
        <div class="col-sm-3">
            <select class="form-select" name="status" aria-label="Filter by status">
                <option value="">Any status</option>
                @foreach (['active', 'inactive'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-3">
            <select class="form-select" name="employment_status" aria-label="Filter by employment status">
                <option value="">Any employment</option>
                @foreach (['active', 'probation', 'inactive', 'exited'] as $s)
                    <option value="{{ $s }}" @selected($employmentStatus === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filter</button>
        </div>
        @if($q || $status || $employmentStatus)
            <div class="col-auto"><a class="btn btn-link" href="{{ route('employees.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Branch</th>
                        <th>Designation</th>
                        <th>Employment</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $employee)
                        <tr>
                            <td><code>{{ $employee->code }}</code></td>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('employees.show', $employee) }}">
                                    {{ $employee->full_name }}
                                </a>
                                @if($employee->is_technician)<span class="erp-chip erp-chip-soft">technician</span>@endif
                            </td>
                            <td class="text-body-secondary">{{ $employee->branch?->name }}</td>
                            <td class="text-body-secondary">{{ $employee->designation ?: '—' }}</td>
                            <td class="text-body-secondary">{{ $employee->employment_status }}</td>
                            <td>
                                <span class="erp-status {{ $employee->status === 'active' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $employee->status }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('employees.show', $employee) }}">View</a>
                                @if ($perm('employees.edit'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('employees.edit', $employee) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4 text-body-secondary">No employees match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $employees->links() }}</div>
@endsection
