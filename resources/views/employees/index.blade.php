@extends('layouts.app')

@section('page_title', 'Employees')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Employees"
        subtitle="Employee type is never a hardcoded role — roles live on the linked user account. Attendance and leave hang off each employee record."
        :pin="true">
        <x-slot:actions>
            @if ($perm('attendance.view'))
                <a class="btn btn-outline-secondary" href="{{ route('hr.attendance') }}">
                    <i class="bi bi-calendar-check" aria-hidden="true"></i> Attendance
                </a>
            @endif
            @if ($perm('hr.structure.manage'))
                <a class="btn btn-outline-secondary" href="{{ route('hr.departments') }}">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i> Departments
                </a>
            @endif
            @if ($perm('employees.create'))
                <a class="btn btn-primary" href="{{ route('employees.create') }}">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> New employee
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('employees.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $q }}" placeholder="Name, code, e-mail or phone…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach (['active', 'inactive'] as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="employment_status">Employment</label>
            <select class="form-select" id="employment_status" name="employment_status">
                <option value="">Any employment</option>
                @foreach (\App\Domain\People\Employee::EMPLOYMENT_STATUSES as $s)
                    <option value="{{ $s }}" @selected($employmentStatus === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="department">Department</label>
            <select class="form-select" id="department" name="department">
                <option value="">All departments</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if($q || $status || $employmentStatus || $departmentId)
                <a class="btn btn-link" href="{{ route('employees.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
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
                            <td class="text-body-secondary">
                                {{ $employee->displayDesignation() ?: '—' }}
                                @if ($employee->displayDepartment())
                                    <span class="d-block small">{{ $employee->displayDepartment() }}</span>
                                @endif
                            </td>
                            <td class="text-body-secondary">{{ $employee->employment_status }}</td>
                            <td>
                                <span class="erp-status {{ $employee->status === 'active' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $employee->status }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light" href="{{ route('employees.show', $employee) }}">View</a>
                                @if ($perm('attendance.report'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('hr.attendance.report', $employee) }}">Attendance</a>
                                @endif
                                @if ($perm('employees.edit'))
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('employees.edit', $employee) }}">Edit</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty icon="bi-people" title="No employees match this filter"
                                            text="Clear a filter, or create the first employee record." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $employees->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
