@extends('layouts.app')

@section('page_title', 'Leave types')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Leave types"
        subtitle="What people can ask for, and whether it is paid. The default days here is the entitlement every employee starts the year with unless their own contract says otherwise."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.leave') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Leave
            </a>
            @if ($perm('masters.manage'))
                <a class="btn btn-outline-secondary" href="{{ route('masters.index') }}">
                    <i class="bi bi-list-check" aria-hidden="true"></i> Master data
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Add a leave type</h2>
            </div>
            <form method="POST" action="{{ route('hr.leave-types.store') }}" class="px-3 pb-3">
                @csrf
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control @error('code') is-invalid @enderror" type="text" id="code" name="code"
                               maxlength="32" value="{{ old('code') }}" placeholder="e.g. casual" required>
                        @error('code')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control @error('name') is-invalid @enderror" type="text" id="name" name="name"
                               maxlength="64" value="{{ old('name') }}" placeholder="e.g. Casual leave" required>
                        @error('name')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="default_days">Default days per year</label>
                        <input class="form-control @error('default_days') is-invalid @enderror" type="number" id="default_days"
                               name="default_days" min="0" max="365" step="0.5" value="{{ old('default_days', 10) }}" required>
                        @error('default_days')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="is_paid">Paid?</label>
                        <select class="form-select" id="is_paid" name="is_paid">
                            <option value="1" @selected(old('is_paid', '1') === '1')>Paid</option>
                            <option value="0" @selected(old('is_paid') === '0')>Unpaid (no salary)</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create leave type</button>
            </form>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Leave types <span class="erp-chip erp-chip-outline">{{ $types->count() }}</span></h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Code</th>
                            <th class="erp-th-num">Default days</th>
                            <th>Paid</th>
                            <th class="erp-th-num">Requests</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($types as $type)
                            <tr>
                                <td data-label="Type" class="erp-cell-strong">{{ $type->name }}</td>
                                <td data-label="Code" class="erp-td-muted">{{ $type->code }}</td>
                                <td data-label="Default days" class="erp-td-num">{{ $type->default_days }}</td>
                                <td data-label="Paid">
                                    <x-ui.status :value="$type->is_paid ? 'paid' : 'unpaid'" :label="$type->is_paid ? 'Paid' : 'Unpaid'" />
                                </td>
                                <td data-label="Requests" class="erp-td-num">{{ $type->leave_requests_count }}</td>
                                <td data-label="Status"><x-ui.status :value="$type->is_active ? 'active' : 'inactive'" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty icon="bi-tags" title="No leave types yet"
                                                text="Create one on the left — leave cannot be requested until at least one exists." />
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
