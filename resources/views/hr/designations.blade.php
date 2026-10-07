@extends('layouts.app')

@section('page_title', 'Designations')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Designations"
        subtitle="Job titles, optionally bound to a department, with an optional grade band. Payroll reads salary from its own tables — a designation never carries money."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.departments') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> Departments
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('code')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Add a designation</h2>
            </div>
            <form method="POST" action="{{ route('hr.designations.store') }}" class="px-3 pb-3">
                @csrf
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control" type="text" id="code" name="code" maxlength="32"
                               value="{{ old('code') }}" placeholder="e.g. SR-SALES" required>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" type="text" id="name" name="name" maxlength="128"
                               value="{{ old('name') }}" placeholder="e.g. Senior sales executive" required>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="department_id">Department</label>
                        <select class="form-select" id="department_id" name="department_id">
                            <option value="">— any department —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="grade">Grade</label>
                        <input class="form-control" type="text" id="grade" name="grade" maxlength="32"
                               value="{{ old('grade') }}" placeholder="e.g. M2">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create designation</button>
            </form>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Designations <span class="erp-chip erp-chip-outline">{{ $designations->count() }}</span></h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Designation</th>
                            <th>Code</th>
                            <th>Department</th>
                            <th>Grade</th>
                            <th class="erp-th-num">Holders</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($designations as $designation)
                            <tr>
                                <td data-label="Designation" class="erp-cell-strong">{{ $designation->name }}</td>
                                <td data-label="Code" class="erp-td-muted">{{ $designation->code }}</td>
                                <td data-label="Department" class="erp-td-muted">{{ $designation->department?->name ?? 'Any' }}</td>
                                <td data-label="Grade" class="erp-td-muted">{{ $designation->grade ?? '—' }}</td>
                                <td data-label="Holders" class="erp-td-num">{{ $designation->employees_count }}</td>
                                <td data-label="Status"><x-ui.status :value="$designation->is_active ? 'active' : 'inactive'" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty icon="bi-person-vcard" title="No designations yet"
                                                text="Create one on the left to stop typing job titles as free text on the employee form." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
