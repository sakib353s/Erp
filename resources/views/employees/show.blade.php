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
                    <dd>{{ $employee->designation ?: '—' }}</dd>
                    <dt>Department</dt>
                    <dd>{{ $employee->department ?: '—' }}</dd>
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
