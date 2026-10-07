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
                            <label class="form-label" for="designation">Designation</label>
                            <input class="form-control @error('designation') is-invalid @enderror" id="designation"
                                   name="designation" value="{{ old('designation', $employee->designation) }}">
                            @error('designation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="department">Department</label>
                            <input class="form-control @error('department') is-invalid @enderror" id="department"
                                   name="department" value="{{ old('department', $employee->department) }}">
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
            </div>

            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Status</h2></header>
                    <div class="mb-3">
                        <label class="form-label" for="employment_status">Employment status</label>
                        <select class="form-select" id="employment_status" name="employment_status">
                            @foreach (['active', 'probation', 'inactive', 'exited'] as $s)
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
