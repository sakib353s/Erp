@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New employee' : 'Edit employee')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New employee' : 'Edit: '.$employee->full_name }}</h1>
            <p class="erp-page-sub">Branch must sit inside your own access. Role assignment happens on the linked user.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('employees.index') }}">Back to employees</a>
    </div>

    <form method="POST" action="{{ $mode === 'create' ? route('employees.store') : route('employees.update', $employee) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Employee details</h2></header>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
                            <input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code"
                                   name="code" value="{{ old('code', $employee->code) }}" required maxlength="32">
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">First name <span class="text-danger">*</span></label>
                            <input class="form-control @error('first_name') is-invalid @enderror" id="first_name"
                                   name="first_name" value="{{ old('first_name', $employee->first_name) }}" required>
                            @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="last_name">Last name</label>
                            <input class="form-control @error('last_name') is-invalid @enderror" id="last_name"
                                   name="last_name" value="{{ old('last_name', $employee->last_name) }}">
                            @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
                            <select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required>
                                <option value="">— select branch —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected((int) old('branch_id', $employee->branch_id) === $b->id)>
                                        {{ $b->name }} ({{ $b->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="department_id">Department</label>
                            <select class="form-select @error('department_id') is-invalid @enderror" id="department_id" name="department_id">
                                <option value="">— not assigned —</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected((int) old('department_id', $employee->department_id) === $department->id)>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('department_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if ($departments->isEmpty())
                                <div class="erp-help">
                                    No departments exist yet —
                                    @if ($perm('hr.structure.manage'))
                                        <a href="{{ route('hr.departments') }}">create the first one</a>.
                                    @else
                                        ask an administrator to create one.
                                    @endif
                                    Until then you can type a free-text designation below.
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="designation_id">Designation</label>
                            <select class="form-select @error('designation_id') is-invalid @enderror" id="designation_id" name="designation_id">
                                <option value="">— not assigned —</option>
                                @foreach ($designations as $designation)
                                    <option value="{{ $designation->id }}" @selected((int) old('designation_id', $employee->designation_id) === $designation->id)>
                                        {{ $designation->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('designation_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="designation">Job title as printed</label>
                            <input class="form-control @error('designation') is-invalid @enderror" id="designation"
                                   name="designation" value="{{ old('designation', $employee->designation) }}"
                                   placeholder="Free text fallback for ID cards">
                            @error('designation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="department">Department as printed</label>
                            <input class="form-control @error('department') is-invalid @enderror" id="department"
                                   name="department" value="{{ old('department', $employee->department) }}"
                                   placeholder="Free text fallback">
                            @error('department')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">E-mail</label>
                            <input class="form-control @error('email') is-invalid @enderror" type="email" id="email"
                                   name="email" value="{{ old('email', $employee->email) }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone"
                                   name="phone" value="{{ old('phone', $employee->phone) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="joining_date">Joining date</label>
                            <input class="form-control @error('joining_date') is-invalid @enderror" type="date" id="joining_date"
                                   name="joining_date" value="{{ old('joining_date', optional($employee->joining_date)->format('Y-m-d')) }}">
                            @error('joining_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="manager_id">Manager</label>
                            <select class="form-select @error('manager_id') is-invalid @enderror" id="manager_id" name="manager_id">
                                <option value="">— none —</option>
                                @foreach($managers as $m)
                                    <option value="{{ $m->id }}" @selected((int) old('manager_id', $employee->manager_id) === $m->id)>
                                        {{ $m->full_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('manager_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="user_id">Linked user (optional)</label>
                            <select class="form-select @error('user_id') is-invalid @enderror" id="user_id" name="user_id">
                                <option value="">— no login —</option>
                                @foreach($users as $u)
                                    <option value="{{ $u->id }}" @selected((int) old('user_id', $employee->user_id) === $u->id)>
                                        {{ $u->name }} ({{ $u->email }})
                                    </option>
                                @endforeach
                            </select>
                            @error('user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>

                <section class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">HR profile</h2></header>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="employment_type">Employment type</label>
                            <select class="form-select" id="employment_type" name="employment_type">
                                @foreach (\App\Domain\People\Employee::EMPLOYMENT_TYPES as $type)
                                    <option value="{{ $type }}" @selected(old('employment_type', $employee->employment_type) === $type)>
                                        {{ ucfirst(str_replace('_', ' ', $type)) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="confirmation_date">Confirmation date</label>
                            <input class="form-control" type="date" id="confirmation_date" name="confirmation_date"
                                   value="{{ old('confirmation_date', $employee->confirmation_date?->toDateString()) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="national_id">National ID</label>
                            <input class="form-control" id="national_id" name="national_id" maxlength="32"
                                   value="{{ old('national_id', $employee->national_id) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="date_of_birth">Date of birth</label>
                            <input class="form-control" type="date" id="date_of_birth" name="date_of_birth"
                                   value="{{ old('date_of_birth', $employee->date_of_birth?->toDateString()) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="gender">Gender</label>
                            <input class="form-control" id="gender" name="gender" maxlength="16"
                                   value="{{ old('gender', $employee->gender) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="blood_group">Blood group</label>
                            <input class="form-control" id="blood_group" name="blood_group" maxlength="8"
                                   value="{{ old('blood_group', $employee->blood_group) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="present_address">Present address</label>
                            <textarea class="form-control" id="present_address" name="present_address" rows="2" maxlength="500">{{ old('present_address', $employee->present_address) }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="permanent_address">Permanent address</label>
                            <textarea class="form-control" id="permanent_address" name="permanent_address" rows="2" maxlength="500">{{ old('permanent_address', $employee->permanent_address) }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="emergency_contact_name">Emergency contact</label>
                            <input class="form-control" id="emergency_contact_name" name="emergency_contact_name" maxlength="128"
                                   placeholder="Name" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="emergency_contact_phone">Emergency phone</label>
                            <input class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" maxlength="32"
                                   value="{{ old('emergency_contact_phone', $employee->emergency_contact_phone) }}">
                        </div>
                    </div>

                    <p class="erp-field-label mt-4">Shift &amp; leave</p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="shift_start">Shift start</label>
                            <input class="form-control" type="time" id="shift_start" name="shift_start"
                                   value="{{ old('shift_start', $employee->shift_start ?? '09:00') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="shift_end">Shift end</label>
                            <input class="form-control" type="time" id="shift_end" name="shift_end"
                                   value="{{ old('shift_end', $employee->shift_end ?? '18:00') }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="late_grace_minutes">Grace (minutes)</label>
                            <input class="form-control" type="number" min="0" max="120" id="late_grace_minutes" name="late_grace_minutes"
                                   value="{{ old('late_grace_minutes', $employee->late_grace_minutes ?? 10) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="weekly_off">Weekly off</label>
                            <select class="form-select" id="weekly_off" name="weekly_off">
                                @foreach (['friday', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday'] as $day)
                                    <option value="{{ $day }}" @selected(old('weekly_off', $employee->weekly_off ?? 'friday') === $day)>
                                        {{ ucfirst($day) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="annual_leave_days">Annual leave days</label>
                            <input class="form-control" type="number" min="0" max="365" id="annual_leave_days" name="annual_leave_days"
                                   value="{{ old('annual_leave_days', $employee->annual_leave_days ?? 0) }}">
                        </div>
                    </div>
                    <div class="erp-help mt-2">
                        These three settings drive attendance: lateness is measured against shift start plus grace, leave day
                        counting skips the weekly off, and the annual allowance seeds the paid-leave balance.
                    </div>

                    <p class="erp-field-label mt-4">Payroll destination</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="bank_name">Bank</label>
                            <input class="form-control" id="bank_name" name="bank_name" maxlength="96"
                                   value="{{ old('bank_name', $employee->bank_name) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="bank_account_no">Account number</label>
                            <input class="form-control" id="bank_account_no" name="bank_account_no" maxlength="48"
                                   value="{{ old('bank_account_no', $employee->bank_account_no) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="mobile_wallet">Mobile wallet</label>
                            <input class="form-control" id="mobile_wallet" name="mobile_wallet" maxlength="32"
                                   value="{{ old('mobile_wallet', $employee->mobile_wallet) }}">
                        </div>
                    </div>
                    <div class="erp-help mt-2">
                        Salary itself is not captured here — payroll owns the money and reads these destination fields when it
                        runs. Nothing on this page posts to the ledger.
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Status</h2></header>
                    <div class="mb-3">
                        <label class="form-label" for="employment_status">Employment status</label>
                        <select class="form-select" id="employment_status" name="employment_status">
                            @foreach (\App\Domain\People\Employee::EMPLOYMENT_STATUSES as $s)
                                <option value="{{ $s }}" @selected(old('employment_status', $employee->employment_status) === $s)>
                                    {{ ucfirst($s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="status">Account status</label>
                        <select class="form-select" id="status" name="status">
                            @foreach (['active', 'inactive'] as $s)
                                <option value="{{ $s }}" @selected(old('status', $employee->status) === $s)>
                                    {{ ucfirst($s) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_technician"
                               name="is_technician" value="1" @checked(old('is_technician', $employee->is_technician))>
                        <label class="form-check-label" for="is_technician">Technician flag (service floor)</label>
                    </div>
                    @error('is_technician')<div class="text-danger small">{{ $message }}</div>@enderror
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create employee' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('employees.index') }}">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
