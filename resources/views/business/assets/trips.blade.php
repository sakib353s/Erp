@php
    /* §12-14 — the trip log: every run out and back, and what it cost.

       Two readings matter and both are arithmetic: where a vehicle was (which is
       what somebody asks the day something arrives damaged) and what it costs per
       kilometre (which is how a fleet is actually compared). */
    $hasVehicles = $vehicles->isNotEmpty();
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets · Trip log"
    title="Every run out and back"
    subtitle="A trip is a date, a route, a driver, two odometer readings and what the run cost. The distance is derived from the odometer rather than typed — a typed distance and a measured one disagree eventually, and nobody can say which is right."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('assets.vehicles') }}">
            <i class="bi bi-truck" aria-hidden="true"></i> The fleet
        </a>
        @if ($canManage && ! $hasVehicles)
            <a class="btn btn-primary" href="{{ route('assets.create', ['category' => 'vehicle']) }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Register a vehicle first
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Trips in range" :value="$trips->total()" icon="bi-signpost-split"
              :hint="($filters['from'] ?? 'the start of the month').' to '.($filters['to'] ?? 'today')" />
    <x-ui.kpi label="Distance on this page" :value="number_format($totals['distance'], 2).' km'" icon="bi-speedometer2"
              hint="Only the trips with both odometer readings contribute" />
    <x-ui.kpi label="Fuel" :value="'৳'.number_format($totals['fuel'], 2)" icon="bi-fuel-pump"
              hint="What the runs on this page burned" />
    <x-ui.kpi label="Other costs" :value="'৳'.number_format($totals['other'], 2)" icon="bi-receipt"
              hint="Tolls, parking, helpers — whatever the driver recorded" />
    <x-ui.kpi label="Total on this page" :value="'৳'.number_format($totals['fuel'] + $totals['other'], 2)"
              icon="bi-cash-stack"
              :hint="$totals['distance'] > 0 && ($totals['fuel'] + $totals['other']) > 0
                        ? '৳'.number_format(round(($totals['fuel'] + $totals['other']) / $totals['distance'], 2), 2).' per km'
                        : 'No distance recorded, so no cost per km'" />
    <x-ui.kpi label="Not on the expense desk" :value="$totals['unexpensed']" icon="bi-exclamation-circle"
              :hint="$totals['unexpensed'] > 0 ? 'Costs recorded here with nothing in the books behind them yet' : 'Every cost on this page has been expensed'" />
</div>

@if ($canManage && $hasVehicles)
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Log a run</h2>
                <p class="erp-card-sub">Fill in what you know. Both odometer readings give a real distance; with neither, the trip is still recorded and the kilometres stay blank rather than invented.</p>
            </div>
        </header>
        <form class="p-3 pt-0" method="POST" action="{{ route('assets.trips.log') }}">
            @csrf
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="erp-field-label" for="business_asset_id">Vehicle <span aria-hidden="true">*</span></label>
                    <select class="form-select @error('business_asset_id') is-invalid @enderror" name="business_asset_id" id="business_asset_id" required>
                        <option value="">Pick a vehicle</option>
                        @foreach ($vehicles as $vehicle)
                            <option value="{{ $vehicle->id }}" @selected((string) old('business_asset_id', $filters['asset_id']) === (string) $vehicle->id)>
                                {{ $vehicle->registration_no ?: $vehicle->code }} — {{ $vehicle->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('business_asset_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="trip_date">Date <span aria-hidden="true">*</span></label>
                    <input class="form-control @error('trip_date') is-invalid @enderror" type="date" name="trip_date"
                           id="trip_date" value="{{ old('trip_date', now()->toDateString()) }}" required>
                    @error('trip_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="from_location">From</label>
                    <input class="form-control" type="text" name="from_location" id="from_location" value="{{ old('from_location') }}" maxlength="160">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="to_location">To</label>
                    <input class="form-control" type="text" name="to_location" id="to_location" value="{{ old('to_location') }}" maxlength="160">
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="erp-field-label" for="purpose">Purpose</label>
                    <input class="form-control" type="text" name="purpose" id="purpose" value="{{ old('purpose') }}" maxlength="255"
                           placeholder="Deliver order 4412, collect stock from the port, staff pickup">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="driver_id">Driver</label>
                    <select class="form-select" name="driver_id" id="driver_id">
                        <option value="">The vehicle's usual driver</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((string) old('driver_id') === (string) $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="driver_name">Or a name</label>
                    <input class="form-control" type="text" name="driver_name" id="driver_name" value="{{ old('driver_name') }}" maxlength="120"
                           placeholder="A contract driver without an account">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="odometer_start">Odometer out</label>
                    <input class="form-control" type="number" min="0" name="odometer_start" id="odometer_start" value="{{ old('odometer_start') }}">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="odometer_end">Odometer in</label>
                    <input class="form-control @error('odometer_end') is-invalid @enderror" type="number" min="0"
                           name="odometer_end" id="odometer_end" value="{{ old('odometer_end') }}">
                    @error('odometer_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="fuel_litres">Fuel (litres)</label>
                    <input class="form-control" type="number" step="0.001" min="0" name="fuel_litres" id="fuel_litres" value="{{ old('fuel_litres') }}">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="fuel_cost">Fuel cost (৳)</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="fuel_cost" id="fuel_cost" value="{{ old('fuel_cost') }}">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="other_cost">Other cost (৳)</label>
                    <input class="form-control" type="number" step="0.01" min="0" name="other_cost" id="other_cost" value="{{ old('other_cost') }}">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="cost_note">What the other cost was</label>
                    <input class="form-control" type="text" name="cost_note" id="cost_note" value="{{ old('cost_note') }}" maxlength="255"
                           placeholder="Toll, parking, a helper's wage">
                </div>
            </div>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Log the trip
            </button>
        </form>
    </section>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('assets.trips') }}">
    <div class="erp-filter">
        <label class="form-label" for="asset_id">Vehicle</label>
        <select class="form-select" name="asset_id" id="asset_id" data-erp-autosubmit>
            <option value="">Every vehicle</option>
            @foreach ($vehicles as $vehicle)
                <option value="{{ $vehicle->id }}" @selected((string) ($filters['asset_id'] ?? '') === (string) $vehicle->id)>
                    {{ $vehicle->registration_no ?: $vehicle->code }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="from">From</label>
        <input class="form-control" type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="to">To</label>
        <input class="form-control" type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}">
    </div>
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Route, purpose or driver">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('assets.trips') }}">This month</a>
    </div>
</form>

<x-ui.table-shell title="The log" :count="$trips->total().' trip(s)'">
    <thead>
        <tr>
            <th>Date</th>
            <th>Vehicle</th>
            <th>Route</th>
            <th>Driver</th>
            <th class="text-end">Odometer</th>
            <th class="text-end">Distance</th>
            <th class="text-end">Cost</th>
            <th>In the books</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($trips as $trip)
            <tr>
                <td data-label="Date" class="erp-td-muted">{{ $trip->trip_date->format('d M Y') }}</td>
                <td data-label="Vehicle">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $trip->asset) }}">
                        {{ $trip->asset?->registration_no ?: $trip->asset?->code }}
                    </a>
                    <div class="erp-td-muted">{{ $trip->asset?->name }}</div>
                </td>
                <td data-label="Route">
                    {{ $trip->routeLabel() }}
                    @if ($trip->purpose)
                        <div class="erp-td-muted">{{ $trip->purpose }}</div>
                    @endif
                </td>
                <td data-label="Driver" class="erp-td-muted">{{ $trip->driverLabel() }}</td>
                <td data-label="Odometer" class="erp-td-num text-end">
                    @if ($trip->odometer_start !== null || $trip->odometer_end !== null)
                        {{ $trip->odometer_start !== null ? number_format($trip->odometer_start) : '—' }}
                        →
                        {{ $trip->odometer_end !== null ? number_format($trip->odometer_end) : '—' }}
                    @else
                        <span class="erp-td-muted">not taken</span>
                    @endif
                </td>
                <td data-label="Distance" class="erp-td-num text-end">
                    {{ $trip->distance_km !== null ? number_format((float) $trip->distance_km, 2).' km' : '—' }}
                    @if ($trip->fuelEfficiency() !== null)
                        <div class="erp-td-muted">{{ $trip->fuelEfficiency() }} km/l</div>
                    @endif
                </td>
                <td data-label="Cost" class="erp-td-num text-end">
                    ৳{{ number_format($trip->totalCost(), 2) }}
                    <div class="erp-td-muted">
                        {{ $trip->fuel_cost !== null ? '৳'.number_format((float) $trip->fuel_cost, 2).' fuel' : 'no fuel cost' }}
                        @if ((float) $trip->other_cost > 0)
                            · ৳{{ number_format((float) $trip->other_cost, 2) }} other
                        @endif
                    </div>
                </td>
                <td data-label="In the books">
                    @if ($trip->isExpensed())
                        <span class="erp-chip erp-chip-ok">Expensed</span>
                    @elseif ($trip->totalCost() > 0)
                        <span class="erp-chip erp-chip-warn">On the expense desk yet to be recorded</span>
                    @else
                        <span class="erp-chip erp-chip-outline">No cost recorded</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="No trips in this range"
                        text="Widen the dates, or log a run. The trip log is what turns “the van is expensive” into a number: fuel and other costs divided by measured kilometres, per vehicle, per month."
                        icon="bi-signpost-split" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($trips->hasPages())
        <x-slot:footer>{{ $trips->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<div class="erp-note erp-note-info">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        Costs are recorded here and posted on the <strong>expense desk</strong> — that is where money actually leaves the company, with an expense category, an approver and a journal entry. A trip whose cost is recorded but never expensed is a real cost the books have not seen yet, which is why the column above says so out loud rather than quietly showing a total.
    </div>
</div>

<x-ui.related-pages />
