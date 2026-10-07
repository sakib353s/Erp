@extends('layouts.app')

@section('page_title', 'Delivery Zones')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Delivery Zones</h1>
            <p class="erp-page-sub">Zone-wise charges — base + per-kg + weight-slab rows feed shipping on quotations and orders.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Add delivery zone</h2>
        <form method="POST" action="{{ route('sales.delivery.zones.store') }}" class="row g-2">
            @csrf
            <div class="col-md-2">
                <label class="form-label" for="zone_code">Code</label>
                <input class="form-control" id="zone_code" name="code" value="{{ old('code') }}" maxlength="32" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="zone_name">Name</label>
                <input class="form-control" id="zone_name" name="name" value="{{ old('name') }}" maxlength="128" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="zone_description">Description</label>
                <input class="form-control" id="zone_description" name="description" value="{{ old('description') }}" maxlength="500">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="base_charge">Base charge</label>
                <input class="form-control" id="base_charge" name="base_charge" type="number" step="0.01" min="0" value="{{ old('base_charge', '0') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="per_kg_charge">Per kg charge</label>
                <input class="form-control" id="per_kg_charge" name="per_kg_charge" type="number" step="0.01" min="0" value="{{ old('per_kg_charge', '0') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="district_ids">Districts</label>
                <select class="form-select" id="district_ids" name="district_ids[]" multiple size="5">
                    @foreach ($districts as $district)
                        <option value="{{ $district->id }}" @selected(in_array($district->id, old('district_ids', []), true))>
                            {{ $district->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="zone_active" name="is_active" value="1" @checked(old('is_active', true))>
                    <label class="form-check-label" for="zone_active">Active</label>
                </div>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Add zone</button>
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
                        <th>Districts</th>
                        <th class="text-end">Base charge</th>
                        <th class="text-end">Per kg</th>
                        <th>Status</th>
                        <th>Charges</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($zones as $zone)
                        <tr>
                            <td><code>{{ $zone->code }}</code></td>
                            <td class="fw-semibold">{{ $zone->name }}</td>
                            <td class="small">
                                @forelse ($zone->districts as $district)
                                    <span class="badge text-bg-light border">{{ $district->name }}</span>
                                @empty
                                    <span class="text-muted">No district coverage</span>
                                @endforelse
                            </td>
                            <td class="text-end">{{ number_format((float) $zone->base_charge, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $zone->per_kg_charge, 2) }}</td>
                            <td>
                                <span class="erp-status {{ $zone->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $zone->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="small">
                                @forelse ($zone->charges as $charge)
                                    <div class="d-flex justify-content-between gap-2 border-bottom py-1">
                                        <span>
                                            <code>{{ $charge->code }}</code>
                                            {{ $charge->weight_from }}kg
                                            @if ($charge->weight_to !== null)
                                                → {{ $charge->weight_to }}kg
                                            @else
                                                +
                                            @endif
                                            = {{ number_format((float) $charge->amount, 2) }}
                                            @unless ($charge->is_active)
                                                <span class="text-muted">(off)</span>
                                            @endunless
                                        </span>
                                        <form method="POST"
                                              action="{{ route('sales.delivery.zones.charges.destroy', $charge) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-link btn-sm p-0 text-danger" type="submit"
                                                    aria-label="Remove charge {{ $charge->code }}">Remove</button>
                                        </form>
                                    </div>
                                @empty
                                    <span class="text-muted">No slab charges</span>
                                @endforelse

                                <form method="POST" action="{{ route('sales.delivery.zones.charges.store', $zone) }}"
                                      class="row g-1 mt-1">
                                    @csrf
                                    <div class="col-4">
                                        <input class="form-control form-control-sm" name="code" placeholder="Code"
                                               maxlength="32" required aria-label="Charge code for {{ $zone->code }}">
                                    </div>
                                    <div class="col-3">
                                        <input class="form-control form-control-sm" name="weight_from" type="number"
                                               step="0.001" min="0" placeholder="Min kg" required
                                               aria-label="Minimum weight for {{ $zone->code }} charge">
                                    </div>
                                    <div class="col-3">
                                        <input class="form-control form-control-sm" name="weight_to" type="number"
                                               step="0.001" min="0" placeholder="Max kg (blank = open)"
                                               aria-label="Maximum weight for {{ $zone->code }} charge">
                                    </div>
                                    <div class="col-2">
                                        <div class="input-group input-group-sm">
                                            <input class="form-control" name="amount" type="number" step="0.01"
                                                   min="0" placeholder="Amount" required
                                                   aria-label="Amount for {{ $zone->code }} charge">
                                            <button class="btn btn-outline-secondary" type="submit">Add</button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No delivery zones yet — add one above.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
