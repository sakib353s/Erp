@php
    /* §12-14 — vehicle management: the fleet, its papers and what it costs to run.

       The papers column is the useful one. Fitness, insurance and the tax token
       are certificates on the company registers, linked to the vehicle — so the
       next date that runs out appears here, and on the same renewals lens as the
       company's trade licence. */
    $today = now()->startOfDay();
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets · Vehicles"
    title="The fleet, its papers, and what each kilometre costs"
    subtitle="A vehicle is an asset with plates: the same register entry as a generator, plus a driver and dates that run out. Nothing here is a second list — it is the register read the way somebody who has to get a van to Uttara needs it."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('assets.create', ['category' => 'vehicle']) }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Register a vehicle
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('assets.trips') }}">
            <i class="bi bi-signpost-split" aria-hidden="true"></i> Trip log
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('assets.index') }}">
            <i class="bi bi-hdd-stack" aria-hidden="true"></i> Whole register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Vehicles" :value="$summary['live']" icon="bi-truck"
              hint="On the register and not written off" />
    <x-ui.kpi label="Under repair" :value="$summary['under_repair']" icon="bi-tools"
              :hint="$summary['under_repair'] > 0 ? 'Off the road, still on the books' : 'Nothing is in the workshop'" />
    <x-ui.kpi label="At cost" :value="'৳'.number_format($summary['cost'], 2)" icon="bi-cash-stack"
              hint="What the fleet was bought for" />
    <x-ui.kpi label="Book value" :value="'৳'.number_format($summary['book_value'], 2)" icon="bi-wallet2"
              hint="Cost less the depreciation already charged" />
    <x-ui.kpi label="Papers linked" :value="$summary['papers']" icon="bi-paperclip"
              hint="Certificates, insurance and contracts hanging on these vehicles" />
    <x-ui.kpi label="Warranty ending" :value="$summary['warranty_ending']" icon="bi-shield-check"
              :hint="$summary['warranty_ending'] > 0 ? 'Inside the next 30 days' : 'Nothing falls out of warranty this month'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('assets.vehicles') }}">
    <div class="erp-filter">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">On the road and off it</option>
            @foreach ([\App\Domain\Business\BusinessAsset::STATUS_IN_USE, \App\Domain\Business\BusinessAsset::STATUS_STORED, \App\Domain\Business\BusinessAsset::STATUS_REPAIR] as $key)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>
                    {{ \App\Domain\Business\AssetRegistry::STATUSES[$key] }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="branch_id">Branch</label>
        <select class="form-select" name="branch_id" id="branch_id" data-erp-autosubmit>
            <option value="">Every branch</option>
            <option value="company" @selected(($filters['branch_id'] ?? '') === 'company')>Company-wide only</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Plates, name, driver or location">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('assets.vehicles') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="The vehicles" :count="$vehicles->total().' vehicle(s)'">
    <thead>
        <tr>
            <th>Vehicle</th>
            <th>Driver</th>
            <th class="text-end">Odometer</th>
            <th>Next paper to run out</th>
            <th class="text-end">Book value</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($vehicles as $vehicle)
            @php($vehiclePapers = $papers->get($vehicle->id, collect())->sortBy(fn ($paper) => $paper->trackedOn()?->timestamp ?? PHP_INT_MAX))
            <tr>
                <td data-label="Vehicle">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $vehicle) }}">{{ $vehicle->name }}</a>
                    <div class="erp-td-muted">
                        {{ $vehicle->registration_no ?: $vehicle->code }} · {{ $vehicle->location ?: 'Location not recorded' }}
                    </div>
                </td>
                <td data-label="Driver" class="erp-td-muted">
                    {{ $vehicle->driver?->name ?? ($vehicle->driver_name ?: 'Nobody assigned') }}
                </td>
                <td data-label="Odometer" class="erp-td-num text-end">
                    {{ $vehicle->odometer_reading !== null ? number_format($vehicle->odometer_reading).' km' : '—' }}
                </td>
                <td data-label="Next paper">
                    @php($nextPaper = $vehiclePapers->first())
                    @if ($nextPaper !== null && $nextPaper->trackedOn() !== null)
                        <a class="erp-cell-strong" href="{{ route('records.show', $nextPaper) }}">{{ $nextPaper->title }}</a>
                        <div class="erp-td-muted">
                            {{ $nextPaper->trackedOn()->format('d M Y') }} ·
                            <x-ui.status :value="$nextPaper->state()" :label="$nextPaper->stateLabel()" />
                        </div>
                    @elseif ($nextPaper !== null)
                        <a class="erp-cell-strong" href="{{ route('records.show', $nextPaper) }}">{{ $nextPaper->title }}</a>
                        <div class="erp-td-muted">No date on the paper</div>
                    @else
                        <span class="erp-td-muted">No papers linked — record the fitness and insurance on the certificate register and link them to this vehicle</span>
                    @endif
                </td>
                <td data-label="Book value" class="erp-td-num text-end">
                    {{ $vehicle->bookValue() !== null ? '৳'.number_format($vehicle->bookValue(), 2) : '—' }}
                </td>
                <td data-label="Status">
                    <x-ui.status :value="$vehicle->status" :label="$vehicle->statusLabel()" />
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $vehicle) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="No vehicles on the register yet"
                        text="Register a vehicle with its plates, its driver and its cost. Then log its trips — the distance comes from the odometer, and the running cost per kilometre falls out of the numbers rather than out of somebody's memory."
                        icon="bi-truck"
                        :action="$canManage ? 'Register a vehicle' : null"
                        :href="$canManage ? route('assets.create', ['category' => 'vehicle']) : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($vehicles->hasPages())
        <x-slot:footer>{{ $vehicles->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.table-shell title="What they cost to run" :count="$periodLabel">
    <x-slot:tools>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.trips') }}">Log a trip</a>
    </x-slot:tools>
    <thead>
        <tr>
            <th>Vehicle</th>
            <th class="text-end">Trips</th>
            <th class="text-end">Distance</th>
            <th class="text-end">Fuel</th>
            <th class="text-end">Other</th>
            <th class="text-end">Total</th>
            <th class="text-end">Per km</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($costs as $row)
            <tr>
                <td data-label="Vehicle">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $row['asset']) }}">
                        {{ $row['asset']->registration_no ?: $row['asset']->name }}
                    </a>
                    <div class="erp-td-muted">{{ $row['asset']->name }}</div>
                </td>
                <td data-label="Trips" class="erp-td-num text-end">{{ $row['trips'] }}</td>
                <td data-label="Distance" class="erp-td-num text-end">{{ number_format($row['distance'], 2) }} km</td>
                <td data-label="Fuel" class="erp-td-num text-end">৳{{ number_format($row['fuel_cost'], 2) }}</td>
                <td data-label="Other" class="erp-td-num text-end">৳{{ number_format($row['other_cost'], 2) }}</td>
                <td data-label="Total" class="erp-td-num text-end erp-cell-strong">৳{{ number_format($row['total_cost'], 2) }}</td>
                <td data-label="Per km" class="erp-td-num text-end">
                    {{ $row['cost_per_km'] !== null ? '৳'.number_format($row['cost_per_km'], 2) : '—' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="Nothing to total yet"
                        text="No trips were logged for these vehicles this month. A trip with both odometer readings gives a distance that holds up; without them the run is recorded but the kilometres stay blank."
                        icon="bi-signpost-split" />
                </td>
            </tr>
        @endforelse
    </tbody>
    <x-slot:footer>
        Cost per kilometre is only shown when the distance is known — a total divided by a guessed distance is a number nobody should act on. Fuel and other costs are what the driver recorded on each trip; posting them to the books happens on the expense desk, where the money actually moves.
    </x-slot:footer>
</x-ui.table-shell>

<x-ui.related-pages />
