@extends('layouts.app')

@section('page_title', 'Departments')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Departments"
        subtitle="The shape of the organisation. Departments carry headcount and can sit inside a parent department, so reports roll up instead of guessing."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.designations') }}">
                <i class="bi bi-person-vcard" aria-hidden="true"></i> Designations
            </a>
            @if ($perm('employees.view'))
                <a class="btn btn-outline-secondary" href="{{ route('employees.index') }}">
                    <i class="bi bi-people" aria-hidden="true"></i> Employees
                </a>
            @endif
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
                <h2 class="erp-card-title">Add a department</h2>
            </div>
            <form method="POST" action="{{ route('hr.departments.store') }}" class="px-3 pb-3">
                @csrf
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control" type="text" id="code" name="code" maxlength="32"
                               value="{{ old('code') }}" placeholder="e.g. SALES" required>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" type="text" id="name" name="name" maxlength="128"
                               value="{{ old('name') }}" placeholder="e.g. Sales &amp; marketing" required>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="parent_id">Parent department</label>
                        <select class="form-select" id="parent_id" name="parent_id">
                            <option value="">— none (top level) —</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected(old('parent_id') == $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="cost_center">Cost centre</label>
                        <input class="form-control" type="text" id="cost_center" name="cost_center" maxlength="64"
                               value="{{ old('cost_center') }}" placeholder="Optional accounting link">
                    </div>
                </div>
                <div class="erp-help mb-2">
                    A cost centre is free text on purpose — it names the accounting dimension this department reports under.
                    Nothing is posted anywhere until a payroll run exists.
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create department</button>
            </form>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Departments <span class="erp-chip erp-chip-outline">{{ $departments->count() }}</span></h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Code</th>
                            <th>Parent</th>
                            <th>Cost centre</th>
                            <th class="erp-th-num">Headcount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($departments as $department)
                            <tr>
                                <td data-label="Department" class="erp-cell-strong">{{ $department->name }}</td>
                                <td data-label="Code" class="erp-td-muted">{{ $department->code }}</td>
                                <td data-label="Parent" class="erp-td-muted">{{ $department->parent?->name ?? '—' }}</td>
                                <td data-label="Cost centre" class="erp-td-muted">{{ $department->cost_center ?? '—' }}</td>
                                <td data-label="Headcount" class="erp-td-num">
                                    @if ($perm('employees.view'))
                                        <a href="{{ route('employees.index', ['department' => $department->id]) }}">{{ $department->employees_count }}</a>
                                    @else
                                        {{ $department->employees_count }}
                                    @endif
                                </td>
                                <td data-label="Status"><x-ui.status :value="$department->is_active ? 'active' : 'inactive'" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty icon="bi-diagram-3" title="No departments yet"
                                                text="Create one on the left, then assign employees to it from the employee form." />
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
