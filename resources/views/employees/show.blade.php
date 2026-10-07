@extends('layouts.app')

@section('page_title', 'Employee profile')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $employee->full_name }}</h1>
            <p class="erp-page-sub"><code>{{ $employee->code }}</code> · {{ $employee->branch?->name }}</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('employees.index') }}">Back</a>
            @if ($perm('employees.edit'))
                <a class="btn btn-primary" href="{{ route('employees.edit', $employee) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            @if ($perm('employees.delete'))
                <form method="POST" action="{{ route('employees.destroy', $employee) }}"
                      data-confirm="Delete {{ $employee->full_name }}? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger" type="submit">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Profile</h2></header>
                <dl class="erp-dl">
                    <dt>Branch</dt>
                    <dd>{{ $employee->branch?->name ?: '—' }}</dd>
                    <dt>Designation</dt>
                    <dd>{{ $employee->displayDesignation() ?: '—' }}</dd>
                    <dt>Department</dt>
                    <dd>{{ $employee->displayDepartment() ?: '—' }}</dd>
                    <dt>Joining date</dt>
                    <dd>{{ optional($employee->joining_date)->format('d M Y') ?: '—' }}</dd>
                    <dt>E-mail</dt>
                    <dd>{{ $employee->email ?: '—' }}</dd>
                    <dt>Phone</dt>
                    <dd>{{ $employee->phone ?: '—' }}</dd>
                    <dt>Manager</dt>
                    <dd>{{ $employee->manager?->full_name ?: '—' }}</dd>
                    <dt>Linked user</dt>
                    <dd>
                        @if($employee->user)
                            <a href="{{ route('users.show', $employee->user) }}">{{ $employee->user->name }}</a>
                        @else
                            <span class="text-body-secondary">No login account</span>
                        @endif
                    </dd>
                    <dt>Type</dt>
                    <dd>{{ ucfirst(str_replace('_', ' ', (string) $employee->employment_type)) }}</dd>
                    <dt>Shift</dt>
                    <dd>{{ $employee->shift_start }} – {{ $employee->shift_end }} · {{ $employee->late_grace_minutes }} min grace · {{ ucfirst((string) $employee->weekly_off) }} off</dd>
                    <dt>Employment</dt>
                    <dd>{{ $employee->employment_status }}
                        @if($employee->is_technician)<span class="erp-chip erp-chip-soft ms-1">technician</span>@endif
                    </dd>
                    <dt>Status</dt>
                    <dd>
                        <span class="erp-status {{ $employee->status === 'active' ? 'erp-status-active' : 'erp-status-disabled' }}">
                            {{ $employee->status }}
                        </span>
                    </dd>
                </dl>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">HR actions</h2></header>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @if ($perm('attendance.report'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hr.attendance.report', $employee) }}">
                            <i class="bi bi-calendar3" aria-hidden="true"></i> Attendance report
                        </a>
                    @endif
                    @if ($perm('hr.structure.manage'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hr.service-book', $employee) }}">
                            <i class="bi bi-journal-text" aria-hidden="true"></i> Service book
                        </a>
                    @endif
                    @if ($perm('leave.request'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('hr.leave', ['status' => 'pending']) }}">
                            <i class="bi bi-airplane" aria-hidden="true"></i> Leave
                        </a>
                    @endif
                </div>

                <header class="erp-card-head"><h2 class="erp-card-title">Notes</h2></header>
                <p class="text-body-secondary mb-0">
                    Role assignment is intentionally separate from the employee record — open the linked user
                    account to grant or revoke DB-driven roles (traceability 10-02).
                </p>
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
