@php
    /* §12-14 — the register: everything the company owns, on one page. */
    $shelfLinks = [
        'register' => ['label' => 'Asset register', 'icon' => 'bi-hdd-stack', 'route' => 'assets.index', 'hint' => 'Every asset, every kind'],
        'vehicles' => ['label' => 'Vehicle management', 'icon' => 'bi-truck', 'route' => 'assets.vehicles', 'hint' => 'Plates, drivers, papers and running cost'],
        'equipment' => ['label' => 'Equipment', 'icon' => 'bi-gear', 'route' => 'assets.equipment', 'hint' => 'Machinery, computers and the fit-out'],
    ];
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets"
    title="What the company owns, where it is, and what it is worth"
    subtitle="A register that answers three questions about every thing the company owns: where is it, what did it cost, and who answers for it. Book value is not an opinion — it is cost less the depreciation that has actually been posted to the ledger, which is why nothing on these pages moves it except a real journal entry."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('assets.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Register an asset
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('assets.trips') }}">
            <i class="bi bi-signpost-split" aria-hidden="true"></i> Trip log
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('assets.depreciation') }}">
            <i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Depreciation
            @if ($dueCount > 0)
                <span class="erp-chip erp-chip-warn ms-1">{{ $dueCount }} due</span>
            @endif
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Live assets" :value="$summary['live']" icon="bi-hdd-stack"
              hint="On the working register — everything not written off" />
    <x-ui.kpi label="At cost" :value="'৳'.number_format($summary['cost'], 2)" icon="bi-cash-stack"
              hint="What these assets were bought for" />
    <x-ui.kpi label="Accumulated depreciation" :value="'৳'.number_format($summary['accumulated'], 2)"
              icon="bi-graph-down-arrow"
              hint="The part of that cost already charged to expense" />
    <x-ui.kpi label="Book value" :value="'৳'.number_format($summary['book_value'], 2)" icon="bi-wallet2"
              hint="Cost less accumulated depreciation — the figure the balance sheet carries" />
    <x-ui.kpi label="Vehicles" :value="$summary['vehicles']" icon="bi-truck"
              :href="route('assets.vehicles')"
              hint="Each one with plates, a driver and papers that run out" />
    <x-ui.kpi label="Due for depreciation" :value="$dueCount" icon="bi-calendar-check"
              :href="route('assets.depreciation')"
              :hint="$dueCount > 0 ? 'A month has fallen due and not been posted' : 'Every depreciating asset is charged up to this month'" />
</div>

<section class="erp-card mb-3">
    <header class="erp-card-head">
        <div>
            <h2 class="erp-card-title">The shelves</h2>
            <p class="erp-card-sub">One register, seen from four angles. A vehicle is an asset — which is why the trucks are not missing from the register and the register is not missing the trucks.</p>
        </div>
    </header>
    <div class="px-3 pb-2">
        @foreach ($shelfLinks as $key => $shelf)
            <div class="erp-list-row">
                <div class="erp-list-row-main">
                    <a class="erp-cell-strong" href="{{ route($shelf['route']) }}">
                        <i class="bi {{ $shelf['icon'] }} me-1" aria-hidden="true"></i>{{ $shelf['label'] }}
                    </a>
                    <div class="erp-td-muted">{{ $shelf['hint'] }}</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="erp-chip {{ ($shelves[$key] ?? 0) > 0 ? 'erp-chip-soft' : 'erp-chip-outline' }}">
                        {{ $shelves[$key] ?? 0 }} live
                    </span>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route($shelf['route']) }}">Open</a>
                </div>
            </div>
        @endforeach
        <div class="erp-list-row">
            <div class="erp-list-row-main">
                <a class="erp-cell-strong" href="{{ route('assets.trips') }}">
                    <i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Vehicle trip log
                </a>
                <div class="erp-td-muted">Where each vehicle went, how far, and what the run cost — distance derived from the odometer, never typed</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.trips') }}">Open</a>
            </div>
        </div>
        <div class="erp-list-row">
            <div class="erp-list-row-main">
                <a class="erp-cell-strong" href="{{ route('assets.disposal') }}">
                    <i class="bi bi-archive me-1" aria-hidden="true"></i>Disposal register
                </a>
                <div class="erp-td-muted">What was sold, scrapped or written off — with the date, the reason and what came back</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="erp-chip erp-chip-outline">{{ $summary['disposed'] }} written off</span>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.disposal') }}">Open</a>
            </div>
        </div>
    </div>
</section>

@if ($summary['uncapitalised'] > 0)
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>{{ $summary['uncapitalised'] }} asset(s) carry a cost that is not in the books yet.</strong>
            Until they are capitalised they cannot be depreciated — charging a monthly expense against a cost that was never recorded is inventing a loss, so the depreciation run skips them and says so.
            <a href="{{ route('assets.depreciation') }}">Take them through the depreciation desk</a> when the purchase is posted.
        </div>
    </div>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('assets.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="category">Kind</label>
        <select class="form-select" name="category" id="category" data-erp-autosubmit>
            <option value="">Every kind</option>
            @foreach ($registry->options() as $key => $label)
                <option value="{{ $key }}" @selected(($filters['category'] ?? null) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">Live only</option>
            @foreach (\App\Domain\Business\AssetRegistry::STATUSES as $key => $label)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="custodian_id">Custodian</label>
        <select class="form-select" name="custodian_id" id="custodian_id" data-erp-autosubmit>
            <option value="">Anybody</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}" @selected((string) ($filters['custodian_id'] ?? '') === (string) $person->id)>{{ $person->name }}</option>
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
               placeholder="Name, code, plates or location">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('assets.index') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="The register" :count="$assets->total().' asset(s)'">
    <thead>
        <tr>
            <th>Asset</th>
            <th>Where it is</th>
            <th>Custodian</th>
            <th>Condition</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Book value</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($assets as $asset)
            <tr>
                <td data-label="Asset">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                    <div class="erp-td-muted">
                        {{ $asset->code }} · {{ $asset->categoryLabel() }}
                        @if ($asset->registration_no)
                            · {{ $asset->registration_no }}
                        @endif
                    </div>
                </td>
                <td data-label="Where it is" class="erp-td-muted">
                    {{ $asset->location ?: '—' }}
                    <div class="erp-td-muted">{{ $asset->branch?->name ?? 'Company-wide' }}</div>
                </td>
                <td data-label="Custodian" class="erp-td-muted">{{ $asset->custodian?->name ?? 'Nobody named' }}</td>
                <td data-label="Condition">
                    @if ($asset->condition)
                        <x-ui.status :value="$asset->condition" :label="$asset->conditionLabel()" />
                    @else
                        <span class="erp-td-muted">—</span>
                    @endif
                </td>
                <td data-label="Cost" class="erp-td-num text-end">
                    {{ $asset->acquisition_cost !== null ? '৳'.number_format((float) $asset->acquisition_cost, 2) : '—' }}
                </td>
                <td data-label="Book value" class="erp-td-num text-end">
                    {{ $asset->bookValue() !== null ? '৳'.number_format($asset->bookValue(), 2) : '—' }}
                    @if ((float) $asset->accumulated_depreciation > 0)
                        <div class="erp-td-muted">৳{{ number_format((float) $asset->accumulated_depreciation, 2) }} charged</div>
                    @endif
                </td>
                <td data-label="Status">
                    <x-ui.status :value="$asset->status" :label="$asset->statusLabel()" />
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $asset) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nothing on this shelf yet"
                        text="Register the vans, the machines, the laptops and the fit-out — each one with where it lives and who answers for it. Once a cost is capitalised the register can charge its depreciation month by month, with a journal entry behind every charge."
                        icon="bi-hdd-stack"
                        :action="$canManage ? 'Register an asset' : null"
                        :href="$canManage ? route('assets.create') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($assets->hasPages())
        <x-slot:footer>{{ $assets->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
