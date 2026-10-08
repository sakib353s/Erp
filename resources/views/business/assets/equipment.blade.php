@php
    /* §12-14 — equipment: the machines, the computers and the fit-out. */
    $kindOptions = array_intersect_key(
        $registry->options(),
        array_flip(\App\Domain\Business\AssetRegistry::EQUIPMENT_CATEGORIES),
    );
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets · Equipment"
    title="Machinery, computers and the fit-out"
    subtitle="The things the work is actually done with. Short lives and quick obsolescence on one side, a warehouse fit-out on the other — and both are the same shape of fact: something the company owns, somewhere it lives, somebody answerable for it, and a cost that turns into expense month by month."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('assets.create', ['category' => 'equipment']) }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Register equipment
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('assets.depreciation') }}">
            <i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Depreciation desk
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('assets.index') }}">
            <i class="bi bi-hdd-stack" aria-hidden="true"></i> Whole register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="On the shelf" :value="$summary['live']" icon="bi-gear"
              hint="Equipment not written off" />
    <x-ui.kpi label="At cost" :value="'৳'.number_format($summary['cost'], 2)" icon="bi-cash-stack"
              hint="What this shelf was bought for" />
    <x-ui.kpi label="Depreciated" :value="'৳'.number_format($summary['accumulated'], 2)" icon="bi-graph-down-arrow"
              hint="Charged to expense by real journal entries" />
    <x-ui.kpi label="Book value" :value="'৳'.number_format($summary['book_value'], 2)" icon="bi-wallet2"
              hint="What the balance sheet carries for it" />
    <x-ui.kpi label="Under repair" :value="$summary['under_repair']" icon="bi-tools"
              :hint="$summary['under_repair'] > 0 ? 'Broken kit is still on the books' : 'Nothing is off for repair'" />
    <x-ui.kpi label="Warranty ending" :value="$summary['warranty_ending']" icon="bi-shield-check"
              :hint="$summary['warranty_ending'] > 0 ? 'Inside the next 30 days — claim while you can' : 'Nothing falls out of warranty this month'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('assets.equipment') }}">
    <div class="erp-filter">
        <label class="form-label" for="category">Kind</label>
        <select class="form-select" name="category" id="category" data-erp-autosubmit>
            <option value="">All equipment</option>
            @foreach ($kindOptions as $key => $label)
                <option value="{{ $key }}" @selected(($filters['category'] ?? null) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">Live only</option>
            @foreach ([\App\Domain\Business\BusinessAsset::STATUS_IN_USE, \App\Domain\Business\BusinessAsset::STATUS_STORED, \App\Domain\Business\BusinessAsset::STATUS_REPAIR] as $key)
                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ \App\Domain\Business\AssetRegistry::STATUSES[$key] }}</option>
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
               placeholder="Name, code, serial or location">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('assets.equipment') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="Equipment" :count="$equipment->total().' item(s)'">
    <thead>
        <tr>
            <th>Item</th>
            <th>Kind</th>
            <th>Where it is</th>
            <th>Custodian</th>
            <th>Condition</th>
            <th>Warranty</th>
            <th class="text-end">Book value</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($equipment as $item)
            <tr>
                <td data-label="Item">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $item) }}">{{ $item->name }}</a>
                    <div class="erp-td-muted">{{ $item->code }}{{ $item->description ? ' · '.$item->description : '' }}</div>
                </td>
                <td data-label="Kind" class="erp-td-muted">{{ $item->categoryLabel() }}</td>
                <td data-label="Where it is" class="erp-td-muted">
                    {{ $item->location ?: '—' }}
                    <div class="erp-td-muted">{{ $item->branch?->name ?? 'Company-wide' }}</div>
                </td>
                <td data-label="Custodian" class="erp-td-muted">{{ $item->custodian?->name ?? 'Nobody named' }}</td>
                <td data-label="Condition">
                    @if ($item->condition)
                        <x-ui.status :value="$item->condition" :label="$item->conditionLabel()" />
                    @else
                        <span class="erp-td-muted">—</span>
                    @endif
                </td>
                <td data-label="Warranty" class="erp-td-muted">
                    @if ($item->warranty_expires_on)
                        {{ $item->warranty_expires_on->format('d M Y') }}
                        <div class="erp-td-muted">{{ $item->isWarrantyLive() ? 'In warranty' : 'Ended' }}</div>
                    @else
                        —
                    @endif
                </td>
                <td data-label="Book value" class="erp-td-num text-end">
                    {{ $item->bookValue() !== null ? '৳'.number_format($item->bookValue(), 2) : '—' }}
                    @if ($item->isDepreciable())
                        <div class="erp-td-muted">৳{{ number_format($item->monthlyDepreciation(), 2) }}/month</div>
                    @endif
                </td>
                <td data-label="Status">
                    <x-ui.status :value="$item->status" :label="$item->statusLabel()" />
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $item) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9">
                    <x-ui.empty
                        title="Nothing on this shelf yet"
                        text="Generators, freezers, packing machines, POS terminals, the racking in the warehouse. Register one with where it lives and who answers for it, and the depreciation desk takes the cost off the books a month at a time."
                        icon="bi-gear"
                        :action="$canManage ? 'Register equipment' : null"
                        :href="$canManage ? route('assets.create', ['category' => 'equipment']) : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($equipment->hasPages())
        <x-slot:footer>{{ $equipment->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<div class="erp-note erp-note-info">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        A machine that stops working does not stop being owned. Marking it <strong>under repair</strong> keeps it on the register and on the balance sheet — writing it off is a different action, with a date, a reason and whatever came back,
        <a href="{{ route('assets.disposal') }}">on the disposal register</a>.
    </div>
</div>

<x-ui.related-pages />
