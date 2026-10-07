@extends('layouts.app')

@section('page_title', 'Territories')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Territory Management</h1>
            <p class="erp-page-sub">Sales territories with optional delivery-zone link and rep assignment.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.team.field-sales') }}">Field sales</a>
        </div>
    </div>

    @if ($perm('sales.team.territories'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Add territory</h2>
            <form method="POST" action="{{ route('sales.team.territories.store') }}" class="row g-2">
                @csrf
                <div class="col-md-2">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" type="text" maxlength="40" value="{{ old('code') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" type="text" maxlength="160" value="{{ old('name') }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="delivery_zone_id">Delivery zone</label>
                    <select class="form-select" id="delivery_zone_id" name="delivery_zone_id">
                        <option value="">None</option>
                        @foreach ($deliveryZones as $zone)
                            <option value="{{ $zone->id }}" @selected(old('delivery_zone_id') == $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="employee_ids">Reps</label>
                    <select class="form-select" id="employee_ids" name="employee_ids[]" multiple size="3">
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(in_array($employee->id, (array) old('employee_ids', [])))>{{ $employee->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-primary w-100" type="submit">Save</button>
                </div>
                @if ($errors->has('territory'))
                    <div class="col-12 text-danger small">{{ $errors->first('territory') }}</div>
                @endif
            </form>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" type="search" value="{{ $q }}">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Delivery zone</th>
                        <th>Reps</th>
                        <th>Active</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($territories as $territory)
                        <tr>
                            <td>{{ $territory->code }}</td>
                            <td>{{ $territory->name }}</td>
                            <td>{{ $territory->deliveryZone?->name ?? '—' }}</td>
                            <td>
                                @forelse ($territory->employees as $rep)
                                    <span class="badge text-bg-light">{{ $rep->full_name }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td>{{ $territory->is_active ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No territories.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $territories->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
