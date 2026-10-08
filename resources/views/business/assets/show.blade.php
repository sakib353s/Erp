@php
    /* §12-14 — one asset: what it is, what it is worth, where it has been, and
       everything that has ever happened to it. */
    $config = $registry->config($asset->category);
    $isVehicle = $asset->isVehicle();
    $bookValue = $asset->bookValue();
    $next = $asset->nextDepreciationOn();
    $due = $next !== null && $next <= now()->toDateString();
    $monthly = $asset->monthlyDepreciation();

    $state = match (true) {
        $asset->isDisposed() => [
            'tone' => 'erp-note-info',
            'icon' => 'bi-archive',
            'text' => 'Written off on '.$asset->disposed_on?->format('d M Y').' — '.$asset->disposal_reason.'. It stays on the register and on the disposal register; the working lists let it go.',
        ],
        ! $asset->isCapitalised() && (float) $asset->acquisition_cost > 0 => [
            'tone' => 'erp-note-warn',
            'icon' => 'bi-exclamation-triangle',
            'text' => 'Its cost is not in the books yet, so nothing can be depreciated against it. Capitalise it once the purchase is posted and the depreciation desk will start charging it month by month.',
        ],
        ! $asset->isDepreciable() && (float) $asset->acquisition_cost > 0 => [
            'tone' => 'erp-note-info',
            'icon' => 'bi-info-circle',
            'text' => 'This asset is not being depreciated. That is a decision, not a gap — but it should be a decision somebody made, so the policy is written down here and in the history.',
        ],
        $due => [
            'tone' => 'erp-note-warn',
            'icon' => 'bi-calendar-check',
            'text' => 'A monthly charge of ৳'.number_format(min($monthly, $asset->remainingDepreciable()), 2).' fell due on '.date('d M Y', strtotime($next)).'. The depreciation desk posts it, with a journal entry behind it.',
        ],
        default => [
            'tone' => 'erp-note-ok',
            'icon' => 'bi-check2-circle',
            'text' => $next !== null
                ? 'Depreciating ৳'.number_format($monthly, 2).' a month (৳'.number_format($asset->remainingDepreciable(), 2).' left to write off). The next charge falls due on '.date('d M Y', strtotime($next)).'.'
                : 'Fully written down — the cost has been charged to expense in full.',
        ],
    };
@endphp

<x-ui.page-header
    eyebrow="Business Management · {{ $config['plural'] }}"
    :title="$asset->name"
    :subtitle="$asset->code.($asset->registration_no ? ' · '.$asset->registration_no : '').' · '.$asset->categoryLabel()"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ $isVehicle ? route('assets.vehicles') : route('assets.index') }}">
            <i class="bi {{ $config['icon'] }}" aria-hidden="true"></i> All {{ strtolower($config['plural']) }}
        </a>
        @if ($isVehicle)
            <a class="btn btn-outline-secondary" href="{{ route('assets.trips', ['asset_id' => $asset->id]) }}">
                <i class="bi bi-signpost-split" aria-hidden="true"></i> Trips
            </a>
        @endif
        @if ($asset->isDepreciable() && ! $asset->isDisposed())
            <a class="btn btn-outline-secondary" href="{{ route('assets.depreciation') }}">
                <i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Depreciation desk
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-note {{ $state['tone'] }} mb-3">
    <i class="bi {{ $state['icon'] }}" aria-hidden="true"></i>
    <div>
        <div class="mb-1">
            <x-ui.status :value="$asset->status" :label="$asset->statusLabel()" />
            @if ($asset->condition)
                <x-ui.status :value="$asset->condition" :label="$asset->conditionLabel()" />
            @endif
        </div>
        <div>{{ $state['text'] }}</div>
    </div>
</div>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Cost" :value="$asset->acquisition_cost !== null ? '৳'.number_format((float) $asset->acquisition_cost, 2) : 'Not recorded'"
              icon="bi-cash-stack" :hint="$asset->acquired_on ? 'Acquired '.$asset->acquired_on->format('d M Y') : 'No purchase date on file'" />
    <x-ui.kpi label="Accumulated depreciation" :value="'৳'.number_format((float) $asset->accumulated_depreciation, 2)"
              icon="bi-graph-down-arrow"
              :hint="$asset->last_depreciated_on ? 'Charged up to '.$asset->last_depreciated_on->format('M Y') : 'Nothing charged yet'" />
    <x-ui.kpi label="Book value" :value="$bookValue !== null ? '৳'.number_format($bookValue, 2) : '—'"
              icon="bi-wallet2" hint="Cost less what the ledger has already charged" />
    <x-ui.kpi label="Monthly charge" :value="$monthly > 0 ? '৳'.number_format($monthly, 2) : 'None'"
              icon="bi-calendar3"
              :hint="$asset->isDepreciable() ? 'Over '.$asset->useful_life_months.' month(s), straight line' : $asset->methodLabel()" />
    <x-ui.kpi label="Next charge due" :value="$next !== null ? date('d M Y', strtotime($next)) : '—'"
              icon="bi-calendar-check"
              :hint="$asset->isDisposed() ? 'Written off' : ($next === null ? 'Nothing left to charge' : 'Posted by the depreciation desk')" />
    @if ($isVehicle)
        <x-ui.kpi label="Odometer" :value="$asset->odometer_reading !== null ? number_format($asset->odometer_reading).' km' : '—'"
                  icon="bi-speedometer2"
                  :hint="$tripTotals['trips'].' trip(s) logged'".
                         ($tripTotals['distance'] > 0 && ($tripTotals['fuel'] + $tripTotals['other']) > 0
                            ? ' · ৳'.number_format(round(($tripTotals['fuel'] + $tripTotals['other']) / $tripTotals['distance'], 2), 2).' per km'
                            : '')" />
    @endif
</div>

<div class="erp-split">
    <div class="erp-split-main">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What the register holds</h2>
                    <p class="erp-card-sub">Where it is, who answers for it, and what it cost.</p>
                </div>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $asset->code }}</span>
                </div>
            </header>
            <div class="px-3 pb-3">
                <dl class="erp-dl erp-dl-tight">
                    <dt>Kind</dt>
                    <dd>{{ $asset->categoryLabel() }}</dd>

                    <dt>Where it is</dt>
                    <dd>{{ $asset->location ?: 'Location not recorded' }}</dd>

                    <dt>Branch</dt>
                    <dd>{{ $asset->branch?->name ?? 'Company-wide' }}</dd>

                    <dt>Custodian</dt>
                    <dd>{{ $asset->custodian?->name ?? 'Nobody named' }}</dd>

                    <dt>Acquired on</dt>
                    <dd>{{ $asset->acquired_on?->format('d M Y') ?? '—' }}</dd>

                    <dt>Acquisition cost</dt>
                    <dd>{{ $asset->acquisition_cost !== null ? '৳'.number_format((float) $asset->acquisition_cost, 2) : 'Not recorded' }}</dd>

                    <dt>Bought from</dt>
                    <dd>{{ $asset->supplier_name ?: '—' }}</dd>

                    <dt>Invoice reference</dt>
                    <dd>{{ $asset->invoice_ref ?: '—' }}</dd>

                    <dt>Warranty</dt>
                    <dd>
                        @if ($asset->warranty_expires_on)
                            {{ $asset->warranty_expires_on->format('d M Y') }}
                            <span class="erp-chip {{ $asset->isWarrantyLive() ? 'erp-chip-ok' : 'erp-chip-outline' }} ms-1">
                                {{ $asset->isWarrantyLive() ? 'In warranty' : 'Warranty ended' }}
                            </span>
                        @else
                            —
                        @endif
                    </dd>

                    <dt>Ledger account</dt>
                    <dd>{{ $asset->gl_account_code ?: '—' }}</dd>

                    @if ($isVehicle)
                        <dt>Registration</dt>
                        <dd>{{ $asset->registration_no ?: '—' }}</dd>

                        <dt>Engine number</dt>
                        <dd>{{ $asset->engine_no ?: '—' }}</dd>

                        <dt>Chassis number</dt>
                        <dd>{{ $asset->chassis_no ?: '—' }}</dd>

                        <dt>Driver</dt>
                        <dd>{{ $asset->driver?->name ?? ($asset->driver_name ?: 'Nobody assigned') }}</dd>

                        <dt>Odometer</dt>
                        <dd>{{ $asset->odometer_reading !== null ? number_format($asset->odometer_reading).' km' : '—' }}</dd>
                    @endif

                    @if ($asset->description)
                        <dt>Description</dt>
                        <dd>{{ $asset->description }}</dd>
                    @endif

                    @if ($asset->notes)
                        <dt>Notes</dt>
                        <dd>{{ $asset->notes }}</dd>
                    @endif
                </dl>
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">How it wears out</h2>
                    <p class="erp-card-sub">The schedule is a projection; the posted months are not. Rows marked <strong>posted</strong> stand behind a real journal entry — debit {{ \App\Domain\Business\AssetRegistry::DEPRECIATION_EXPENSE_CODE }}, credit {{ \App\Domain\Business\AssetRegistry::ACCUMULATED_DEPRECIATION_CODE }} — and changing the life tomorrow changes the future rows only.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                <dl class="erp-dl erp-dl-tight">
                    <dt>Method</dt>
                    <dd>{{ $asset->methodLabel() }}</dd>

                    <dt>Useful life</dt>
                    <dd>{{ $asset->useful_life_months !== null ? $asset->useful_life_months.' month(s)' : '—' }}</dd>

                    <dt>Salvage value</dt>
                    <dd>৳{{ number_format((float) $asset->salvage_value, 2) }}</dd>

                    <dt>Charge per month</dt>
                    <dd>{{ $monthly > 0 ? '৳'.number_format($monthly, 2) : '—' }}</dd>

                    <dt>Left to write off</dt>
                    <dd>৳{{ number_format($asset->remainingDepreciable(), 2) }}</dd>

                    <dt>Cost in the books</dt>
                    <dd>
                        @if ($asset->isCapitalised())
                            Capitalised {{ $asset->capitalised_at->format('d M Y') }}
                        @else
                            <span class="erp-chip erp-chip-warn">Not capitalised</span>
                        @endif
                    </dd>

                    <dt>Charged from</dt>
                    <dd>{{ $asset->depreciation_starts_on?->format('d M Y') ?? '—' }}</dd>

                    <dt>Last charge posted</dt>
                    <dd>{{ $asset->last_depreciated_on?->format('d M Y') ?? 'Nothing posted yet' }}</dd>

                    <dt>Next charge due</dt>
                    <dd>{{ $next !== null ? date('d M Y', strtotime($next)) : '—' }}</dd>
                </dl>
            </div>

            @if ($schedule !== [])
                <x-ui.table-shell title="The schedule" :count="count($schedule).' month(s)'">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Charged on</th>
                            <th class="text-end">Charge</th>
                            <th class="text-end">Left after</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($schedule, 0, 24) as $row)
                            <tr class="{{ $row['posted'] ? '' : 'erp-row-muted' }}">
                                <td data-label="Month" class="erp-cell-strong">{{ $row['period'] }}</td>
                                <td data-label="Charged on" class="erp-td-muted">{{ date('d M Y', strtotime($row['on'])) }}</td>
                                <td data-label="Charge" class="erp-td-num text-end">৳{{ number_format($row['amount'], 2) }}</td>
                                <td data-label="Left after" class="erp-td-num text-end">৳{{ number_format($row['remaining_after'], 2) }}</td>
                                <td data-label="State">
                                    <span class="erp-chip {{ $row['posted'] ? 'erp-chip-ok' : 'erp-chip-outline' }}">
                                        {{ $row['posted'] ? 'Posted' : 'Planned' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if (count($schedule) > 24)
                        <x-slot:footer>
                            Showing the first 24 of {{ count($schedule) }} months. The register keeps the whole schedule; the posted ones are evidenced by journal entries, the rest are what will happen if nothing changes.
                        </x-slot:footer>
                    @endif
                </x-ui.table-shell>
            @endif
        </section>

        @if ($isVehicle)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Where it has been</h2>
                        <p class="erp-card-sub">
                            {{ $tripTotals['trips'] }} trip(s) logged ·
                            {{ number_format($tripTotals['distance'], 0) }} km ·
                            ৳{{ number_format($tripTotals['fuel'], 2) }} fuel ·
                            ৳{{ number_format($tripTotals['other'], 2) }} other
                        </p>
                    </div>
                    <div class="erp-card-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.trips', ['asset_id' => $asset->id]) }}">Full trip log</a>
                    </div>
                </header>
                <div class="px-3 pb-3">
                    @forelse ($trips as $trip)
                        <div class="erp-list-row">
                            <div class="erp-list-row-main">
                                <span class="erp-cell-strong">{{ $trip->routeLabel() }}</span>
                                <div class="erp-td-muted">
                                    {{ $trip->purpose ?: 'No purpose recorded' }} · {{ $trip->driverLabel() }}
                                </div>
                            </div>
                            <div class="text-end text-nowrap">
                                <div>{{ $trip->trip_date->format('d M Y') }}</div>
                                <div class="erp-td-muted">
                                    {{ $trip->distance_km !== null ? number_format((float) $trip->distance_km, 2).' km' : 'no distance' }}
                                    · ৳{{ number_format($trip->totalCost(), 2) }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="erp-td-muted mb-0">No trips logged yet. Every run out and back is a row here: where it went, how far, what the fuel cost — and the distance is derived from the odometer rather than typed, so it holds up when somebody asks.</p>
                    @endforelse
                </div>
            </section>
        @endif

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The papers hanging on it</h2>
                    <p class="erp-card-sub">Certificates, insurance and contracts are records on the company registers — they keep their own dates, the renewals lens and the compliance calendar. Linking one here is what lets the vehicle page show the next fitness date.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($papers as $paper)
                    <div class="erp-list-row">
                        <div class="erp-list-row-main">
                            <a class="erp-cell-strong" href="{{ route('records.show', $paper) }}">{{ $paper->title }}</a>
                            <div class="erp-td-muted">
                                {{ $paper->kindLabel() }}
                                @if ($paper->reference_no)
                                    · {{ $paper->reference_no }}
                                @endif
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-end">
                            <div>
                                @if ($paper->trackedOn())
                                    <div>{{ $paper->trackedOn()->format('d M Y') }}</div>
                                    <div class="erp-td-muted">{{ $paper->stateLabel() }}</div>
                                @else
                                    <span class="erp-td-muted">No date</span>
                                @endif
                            </div>
                            @if ($canManage)
                                <form method="POST" action="{{ route('assets.records.unlink', [$asset, $paper]) }}"
                                      data-confirm="Unlink “{{ $paper->title }}” from {{ $asset->code }}? It stays on the certificate register.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit">Unlink</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">
                        No papers linked yet.
                        @if ($isVehicle)
                            Fitness, insurance and the tax token are certificates with dates — record them on the certificate register and link them here, and they will appear on this page and on the compliance calendar at the same time.
                        @endif
                    </p>
                @endforelse
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What has happened to it</h2>
                    <p class="erp-card-sub">Registered, moved, handed over, capitalised, depreciated, taken out, written off — in the register's own words.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($events as $event)
                    <div class="erp-list-row erp-list-row-top">
                        <div class="erp-list-row-main">
                            <span class="erp-chip {{ $event->action === 'disposed' ? 'erp-chip-outline' : 'erp-chip-soft' }}">
                                {{ $event->actionLabel() }}
                            </span>
                            <div>{{ $event->note }}</div>
                        </div>
                        <div class="erp-td-muted text-nowrap">
                            {{ $event->happened_on->format('d M Y') }}
                            @if ($event->actor)
                                <div>{{ $event->actor->name }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">Nothing yet — it was just registered.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="erp-split-side">
        @if ($canManage && ! $asset->isCapitalised() && ! $asset->isDisposed())
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Capitalise it</h2>
                        <p class="erp-card-sub">Confirms its cost is in the books. Until then no depreciation can be charged — a monthly expense against a cost nobody recorded is an invented loss.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.capitalise', $asset) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="capitalised_on">Capitalised on</label>
                        <input class="form-control @error('capitalised_on') is-invalid @enderror" type="date"
                               name="capitalised_on" id="capitalised_on" value="{{ $asset->acquired_on?->toDateString() ?? now()->toDateString() }}">
                        @error('capitalised_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <p class="erp-help">Depreciation starts from the month of this date unless the policy says otherwise.</p>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-journal-check" aria-hidden="true"></i> Capitalise
                    </button>
                </form>
            </section>
        @endif

        @if ($canManage && ! $asset->isDisposed())
            <section class="erp-card {{ $asset->isCapitalised() ? '' : 'mt-3' }}">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">How it depreciates</h2>
                        <p class="erp-card-sub">Straight line spreads cost less salvage evenly across the life. Nothing is posted from this form — the depreciation desk posts, one month at a time.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.depreciation.set', $asset) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="method">Method</label>
                        <select class="form-select" name="method" id="method">
                            @foreach (\App\Domain\Business\AssetRegistry::METHODS as $key => $label)
                                <option value="{{ $key }}" @selected($asset->depreciation_method === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('method')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="useful_life_months">Useful life (months)</label>
                        <input class="form-control" type="number" min="1" max="600" name="useful_life_months" id="useful_life_months"
                               value="{{ $asset->useful_life_months ?? $config['life'] }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="salvage_value">Salvage value (৳)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="salvage_value" id="salvage_value"
                               value="{{ (float) $asset->salvage_value }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="depreciation_starts_on">Charge from</label>
                        <input class="form-control" type="date" name="depreciation_starts_on" id="depreciation_starts_on"
                               value="{{ $asset->depreciation_starts_on?->toDateString() ?? $asset->capitalised_at?->startOfMonth()->toDateString() }}">
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">Save the policy</button>
                </form>
            </section>
        @endif

        @if ($canManage && ! $asset->isDisposed())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Move it, or hand it over</h2>
                        <p class="erp-card-sub">A correction. Every change is written to the asset's own history — “who had it when it stopped working?” is a question somebody asks the day after.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.update', $asset) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" for="edit_name">Name</label>
                        <input class="form-control" type="text" name="name" id="edit_name" value="{{ $asset->name }}" maxlength="191" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_location">Location</label>
                        <input class="form-control" type="text" name="location" id="edit_location" value="{{ $asset->location }}" maxlength="160">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_custodian_id">Custodian</label>
                        <select class="form-select" name="custodian_id" id="edit_custodian_id">
                            <option value="">Nobody named</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected((int) $asset->custodian_id === (int) $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_status">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            @foreach (\App\Domain\Business\AssetRegistry::STATUSES as $key => $label)
                                @continue($key === \App\Domain\Business\BusinessAsset::STATUS_DISPOSED)
                                <option value="{{ $key }}" @selected($asset->status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="erp-help">Writing it off is its own action, with a date and a reason — it is below.</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_condition">Condition</label>
                        <select class="form-select" name="condition" id="edit_condition">
                            <option value="">Not assessed</option>
                            @foreach (\App\Domain\Business\AssetRegistry::CONDITIONS as $key => $label)
                                <option value="{{ $key }}" @selected($asset->condition === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_acquisition_cost">Acquisition cost (৳)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="acquisition_cost" id="edit_acquisition_cost"
                               value="{{ (float) $asset->acquisition_cost }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_warranty_expires_on">Warranty runs to</label>
                        <input class="form-control" type="date" name="warranty_expires_on" id="edit_warranty_expires_on"
                               value="{{ $asset->warranty_expires_on?->toDateString() }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_notes">Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="2" maxlength="2000">{{ $asset->notes }}</textarea>
                    </div>
                    @if ($isVehicle)
                        <div class="mb-3">
                            <label class="form-label" for="edit_registration_no">Registration number</label>
                            <input class="form-control" type="text" name="registration_no" id="edit_registration_no"
                                   value="{{ $asset->registration_no }}" maxlength="40" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit_driver_id">Driver</label>
                            <select class="form-select" name="driver_id" id="edit_driver_id">
                                <option value="">Nobody assigned</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}" @selected((int) $asset->driver_id === (int) $person->id)>{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit_odometer_reading">Odometer (km)</label>
                            <input class="form-control" type="number" min="0" name="odometer_reading" id="edit_odometer_reading"
                                   value="{{ $asset->odometer_reading }}">
                        </div>
                    @endif
                    <button class="btn btn-outline-secondary" type="submit">Save changes</button>
                </form>
            </section>
        @endif

        @if ($canManage && $isVehicle && ! $asset->isDisposed())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Log a trip</h2>
                        <p class="erp-card-sub">Both odometer readings give a real distance; neither gives none rather than a guess. The reading on the asset moves forward with the trips.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.trips.log') }}">
                    @csrf
                    <input type="hidden" name="business_asset_id" value="{{ $asset->id }}">
                    <div class="mb-3">
                        <label class="form-label" for="trip_date">Date</label>
                        <input class="form-control @error('trip_date') is-invalid @enderror" type="date" name="trip_date"
                               id="trip_date" value="{{ now()->toDateString() }}" required>
                        @error('trip_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="from_location">From</label>
                            <input class="form-control" type="text" name="from_location" id="from_location" maxlength="160" placeholder="Head office">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="to_location">To</label>
                            <input class="form-control" type="text" name="to_location" id="to_location" maxlength="160" placeholder="Uttara depot">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="purpose">Purpose</label>
                        <input class="form-control" type="text" name="purpose" id="purpose" maxlength="255" placeholder="Deliver order 4412">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="odometer_start">Odometer out</label>
                            <input class="form-control" type="number" min="0" name="odometer_start" id="odometer_start"
                                   value="{{ $asset->odometer_reading }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="odometer_end">Odometer in</label>
                            <input class="form-control @error('odometer_end') is-invalid @enderror" type="number" min="0"
                                   name="odometer_end" id="odometer_end">
                            @error('odometer_end')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="fuel_litres">Fuel (litres)</label>
                            <input class="form-control" type="number" step="0.001" min="0" name="fuel_litres" id="fuel_litres">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="fuel_cost">Fuel cost (৳)</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="fuel_cost" id="fuel_cost">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="other_cost">Other cost (৳)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="other_cost" id="other_cost">
                        <p class="erp-help">Tolls, parking, a helper. Costs are recorded here and posted on the expense desk.</p>
                    </div>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-signpost-split" aria-hidden="true"></i> Log the trip
                    </button>
                </form>
            </section>
        @endif

        @if ($canManage && $linkable->isNotEmpty())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Link a paper</h2>
                        <p class="erp-card-sub">Certificates, insurance and contracts already on the registers, that belong to this asset.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.records.link', $asset) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="record_id">Paper</label>
                        <select class="form-select @error('record_id') is-invalid @enderror" name="record_id" id="record_id" required>
                            <option value="">Pick one</option>
                            @foreach ($linkable as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->title }} ({{ $candidate->kindLabel() }})</option>
                            @endforeach
                        </select>
                        @error('record_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">Link it</button>
                </form>
            </section>
        @endif

        @if ($canManage && ! $asset->isDisposed())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Write it off</h2>
                        <p class="erp-card-sub">Sold, scrapped, given away, stolen or lost. The row stays on the register and moves to the disposal register with its reason and its book value.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('assets.dispose', $asset) }}"
                      data-confirm="Write off {{ $asset->code }}? It leaves the working lists — the register keeps it, with the date, the reason and its book value at that moment.">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="disposed_on">Disposed on</label>
                        <input class="form-control @error('disposed_on') is-invalid @enderror" type="date" name="disposed_on"
                               id="disposed_on" value="{{ now()->toDateString() }}">
                        @error('disposed_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="disposal_proceeds">Proceeds (৳)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="disposal_proceeds" id="disposal_proceeds">
                        <p class="erp-help">What came back, if anything. Book value now is ৳{{ $bookValue !== null ? number_format($bookValue, 2) : '—' }} — the difference is for the books to account for, not for this form to hide.</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="disposal_reason">Why <span aria-hidden="true">*</span></label>
                        <input class="form-control @error('disposal_reason') is-invalid @enderror" type="text" name="disposal_reason"
                               id="disposal_reason" maxlength="255" required placeholder="Sold to a trader, chassis beyond repair, stolen (GD 4412)">
                        @error('disposal_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-outline-danger" type="submit">
                        <i class="bi bi-archive" aria-hidden="true"></i> Write it off
                    </button>
                </form>
            </section>
        @endif
    </div>
</div>

<x-ui.related-pages />
