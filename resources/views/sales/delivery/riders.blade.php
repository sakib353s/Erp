@extends('layouts.app')

@section('page_title', 'Own Delivery Riders')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Own Delivery Riders</h1>
            <p class="erp-page-sub">Roster of your own riders — employee-backed, with vehicle, availability and GPS sharing consent.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Add rider</h2>
        @if ($employees->isEmpty())
            <p class="text-muted mb-0">Every active employee is already on the rider roster.</p>
        @else
            <form method="POST" action="{{ route('sales.delivery.riders.store') }}" class="row g-2">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="employee_id">Employee</label>
                    <select class="form-select" id="employee_id" name="employee_id" required>
                        <option value="">Select employee…</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>
                                {{ $employee->full_name }} ({{ $employee->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('employee_id')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="vehicle_type">Vehicle type</label>
                    <input class="form-control" id="vehicle_type" name="vehicle_type" value="{{ old('vehicle_type') }}" maxlength="32" placeholder="Motorbike / Cycle / Van">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="vehicle_plate">Plate</label>
                    <input class="form-control" id="vehicle_plate" name="vehicle_plate" value="{{ old('vehicle_plate') }}" maxlength="32">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="add_consent" name="gps_consent" value="1" @checked(old('gps_consent'))>
                        <label class="form-check-label" for="add_consent">GPS sharing</label>
                    </div>
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-primary" type="submit">Add</button>
                </div>
            </form>
        @endif
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Rider</th>
                        <th>Vehicle</th>
                        <th>Linked user</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Active</th>
                        <th class="text-center">GPS sharing</th>
                        <th class="text-center">Pending assignments</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($profiles as $profile)
                        <tr>
                            <td><code>{{ $profile->employee?->code }}</code></td>
                            <td class="fw-semibold">{{ $profile->employee?->full_name ?? 'Employee #'.$profile->employee_id }}</td>
                            <td class="small">
                                {{ $profile->vehicle_type ?: '—' }}
                                @if ($profile->vehicle_plate)
                                    <code>{{ $profile->vehicle_plate }}</code>
                                @endif
                            </td>
                            <td class="small">
                                @if ($profile->employee?->user_id)
                                    <span class="erp-status erp-status-active">linked</span>
                                @else
                                    <span class="text-muted">no login</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="erp-status {{ $profile->is_available ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $profile->is_available ? 'available' : 'busy' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="erp-status {{ $profile->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $profile->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="erp-status {{ $profile->gps_consent ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $profile->gps_consent ? 'sharing on' : 'sharing off' }}
                                </span>
                            </td>
                            <td class="text-center">{{ (int) ($pendingCounts[$profile->employee_id] ?? 0) }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('sales.delivery.riders.update', $profile) }}" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="vehicle_type" value="{{ $profile->vehicle_type }}">
                                    <input type="hidden" name="vehicle_plate" value="{{ $profile->vehicle_plate }}">
                                    <input type="hidden" name="is_available" value="{{ $profile->is_available ? 1 : 0 }}">
                                    <input type="hidden" name="is_active" value="{{ $profile->is_active ? 1 : 0 }}">
                                    <input type="hidden" name="gps_consent" value="{{ $profile->gps_consent ? 0 : 1 }}">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">
                                        {{ $profile->gps_consent ? 'Stop GPS sharing' : 'Approve GPS sharing' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('sales.delivery.riders.update', $profile) }}" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="vehicle_type" value="{{ $profile->vehicle_type }}">
                                    <input type="hidden" name="vehicle_plate" value="{{ $profile->vehicle_plate }}">
                                    <input type="hidden" name="is_available" value="{{ $profile->is_available ? 0 : 1 }}">
                                    <input type="hidden" name="is_active" value="{{ $profile->is_active ? 1 : 0 }}">
                                    <input type="hidden" name="gps_consent" value="{{ $profile->gps_consent ? 1 : 0 }}">
                                    <button class="btn btn-sm btn-outline-primary" type="submit">
                                        {{ $profile->is_available ? 'Mark busy' : 'Mark available' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-muted">No riders on the roster yet — add an employee above.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
