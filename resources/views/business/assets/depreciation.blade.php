@php
    /* §12-14 — the depreciation desk.

       The accounting, said once and plainly: wearing out is an expense with no
       invoice. Every month, each capitalised asset gives up a slice of its cost —
       debited to {{ \App\Domain\Business\AssetRegistry::DEPRECIATION_EXPENSE_CODE }}
       (depreciation expense) and credited to
       {{ \App\Domain\Business\AssetRegistry::ACCUMULATED_DEPRECIATION_CODE }}
       (accumulated depreciation). Nothing on this page moves a figure without a
       journal entry to point at. */
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets · Depreciation"
    title="What wearing out costs this month"
    subtitle="Wearing out is an expense that arrives without an invoice — once a month, for every capitalised asset, for years. This is the desk that charges it: one journal entry per asset per month, debit {{ \App\Domain\Business\AssetRegistry::DEPRECIATION_EXPENSE_CODE }} and credit {{ \App\Domain\Business\AssetRegistry::ACCUMULATED_DEPRECIATION_CODE }}, with the register's own figure following the ledger rather than the other way round."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('assets.index') }}">
            <i class="bi bi-hdd-stack" aria-hidden="true"></i> Asset register
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('assets.disposal') }}">
            <i class="bi bi-archive" aria-hidden="true"></i> Disposal register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Due now" :value="$due->count()" icon="bi-calendar-check"
              :hint="$due->count() > 0 ? 'A month has fallen due on these assets' : 'Everything is charged up to this month'" />
    <x-ui.kpi label="Charge if posted" :value="'৳'.number_format($charge, 2)" icon="bi-calculator"
              hint="One month for each asset that is due" />
    <x-ui.kpi label="Depreciating" :value="$depreciating->count()" icon="bi-graph-down-arrow"
              hint="Capitalised, with a life and a monthly charge" />
    <x-ui.kpi label="Posted to date" :value="'৳'.number_format($postedTotal, 2)" icon="bi-journal-check"
              :hint="$postedEntries.' journal entr'.($postedEntries === 1 ? 'y' : 'ies').' across the whole register'" />
    <x-ui.kpi label="Waiting to be capitalised" :value="$waiting->count()" icon="bi-hourglass-split"
              :hint="$waiting->count() > 0 ? 'Cost recorded, not yet in the books — nothing can be charged against them' : 'Every costed asset is in the books'" />
    <x-ui.kpi label="No policy yet" :value="$unplanned->count()" icon="bi-question-circle"
              :hint="$unplanned->count() > 0 ? 'Capitalised but with no life set: a decision nobody has taken' : 'Every capitalised asset has a policy'" />
</div>

@if ($due->isNotEmpty())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>{{ $due->count() }} asset(s) have a month due.</strong>
            Posting it writes ৳{{ number_format($charge, 2) }} of expense into the ledger and moves the same amount into accumulated depreciation — the register's book values drop by exactly what the books charged, because they are the same number. Each asset gets its own entry, so a later question about one van can be answered from the ledger.
        </div>
    </div>
@endif

<x-ui.table-shell title="What is due" :count="$due->count().' asset(s)'">
    <x-slot:tools>
        @if ($canManage)
            @if ($due->isNotEmpty())
                <form method="POST" action="{{ route('assets.depreciation.run') }}"
                      data-confirm="Post one month of depreciation for {{ $due->count() }} asset(s), ৳{{ number_format($charge, 2) }} in total? One journal entry per asset, debit {{ \App\Domain\Business\AssetRegistry::DEPRECIATION_EXPENSE_CODE }} and credit {{ \App\Domain\Business\AssetRegistry::ACCUMULATED_DEPRECIATION_CODE }}.">
                    @csrf
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-journal-arrow-down" aria-hidden="true"></i> Post this month
                    </button>
                </form>
            @else
                <span class="erp-chip erp-chip-ok">Nothing due</span>
            @endif
        @endif
    </x-slot:tools>
    <thead>
        <tr>
            <th>Asset</th>
            <th>Charged from</th>
            <th>Last posted</th>
            <th>Falls due</th>
            <th class="text-end">This month</th>
            <th class="text-end">Left to write off</th>
            <th class="text-end">Book value now</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($due as $asset)
            <tr>
                <td data-label="Asset">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                    <div class="erp-td-muted">{{ $asset->code }} · {{ $asset->categoryLabel() }}</div>
                </td>
                <td data-label="Charged from" class="erp-td-muted">{{ $asset->depreciation_starts_on?->format('d M Y') ?? '—' }}</td>
                <td data-label="Last posted" class="erp-td-muted">{{ $asset->last_depreciated_on?->format('d M Y') ?? 'Never' }}</td>
                <td data-label="Falls due" class="erp-td-muted">
                    {{ $asset->nextDepreciationOn() !== null ? date('d M Y', strtotime($asset->nextDepreciationOn())) : '—' }}
                </td>
                <td data-label="This month" class="erp-td-num text-end">
                    ৳{{ number_format(min($asset->monthlyDepreciation(), $asset->remainingDepreciable()), 2) }}
                </td>
                <td data-label="Left to write off" class="erp-td-num text-end">৳{{ number_format($asset->remainingDepreciable(), 2) }}</td>
                <td data-label="Book value now" class="erp-td-num text-end">
                    {{ $asset->bookValue() !== null ? '৳'.number_format($asset->bookValue(), 2) : '—' }}
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $asset) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nothing is due"
                        text="Every depreciating asset has been charged up to this month. A charge falls due once a month for each of them, for as long as there is cost left to write off."
                        icon="bi-calendar-check" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($due->isNotEmpty())
        <x-slot:footer>
            A charge is never dated in the future and never before the month it covers, so an asset registered mid-month is charged from the month it starts. The month is derived from the start date and the months already posted — which is why running the post twice in one day cannot charge the same month twice, and why a run that stops halfway can simply be run again.
        </x-slot:footer>
    @endif
</x-ui.table-shell>

@if ($waiting->isNotEmpty())
    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Cost recorded, not in the books yet</h2>
                <p class="erp-card-sub">Nothing can be depreciated against a cost that was never recorded. Capitalise these when the purchase is posted and they join the schedule — the refusal is deliberate: charging a monthly expense against an unrecorded cost invents a loss.</p>
            </div>
        </header>
        <div class="px-3 pb-3">
            @foreach ($waiting as $asset)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                        <div class="erp-td-muted">
                            {{ $asset->code }} · {{ $asset->categoryLabel() }} · bought for ৳{{ number_format((float) $asset->acquisition_cost, 2) }}
                            @if ($asset->acquired_on)
                                on {{ $asset->acquired_on->format('d M Y') }}
                            @endif
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip erp-chip-warn">Not capitalised</span>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $asset) }}">Capitalise it</a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if ($unplanned->isNotEmpty())
    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Capitalised, with no depreciation policy</h2>
                <p class="erp-card-sub">These assets are in the books but nobody has said how the cost turns into expense. Leaving them undepreciated is allowed — what is not allowed is leaving it unsaid, because an asset nobody decided about is still on the balance sheet at full value ten years later.</p>
            </div>
        </header>
        <div class="px-3 pb-3">
            @foreach ($unplanned as $asset)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                        <div class="erp-td-muted">
                            {{ $asset->code }} · {{ $asset->categoryLabel() }} · ৳{{ number_format((float) $asset->acquisition_cost, 2) }}
                            · capitalised {{ $asset->capitalised_at?->format('d M Y') }}
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip erp-chip-outline">{{ $asset->methodLabel() }}</span>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('assets.show', $asset) }}">Set the policy</a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

@if ($depreciating->isNotEmpty())
    <x-ui.table-shell title="Being depreciated" :count="$depreciating->count().' asset(s)'">
        <thead>
            <tr>
                <th>Asset</th>
                <th>Method</th>
                <th class="text-end">Life</th>
                <th class="text-end">Salvage</th>
                <th class="text-end">Per month</th>
                <th>Last posted</th>
                <th>Next due</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($depreciating as $asset)
                <tr>
                    <td data-label="Asset">
                        <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                        <div class="erp-td-muted">{{ $asset->code }} · {{ $asset->branch?->name ?? 'Company-wide' }}</div>
                    </td>
                    <td data-label="Method" class="erp-td-muted">{{ $asset->methodLabel() }}</td>
                    <td data-label="Life" class="erp-td-num text-end">{{ $asset->useful_life_months }} m</td>
                    <td data-label="Salvage" class="erp-td-num text-end">৳{{ number_format((float) $asset->salvage_value, 2) }}</td>
                    <td data-label="Per month" class="erp-td-num text-end">৳{{ number_format($asset->monthlyDepreciation(), 2) }}</td>
                    <td data-label="Last posted" class="erp-td-muted">{{ $asset->last_depreciated_on?->format('d M Y') ?? 'Never' }}</td>
                    <td data-label="Next due" class="erp-td-muted">
                        @php($next = $asset->nextDepreciationOn())
                        @if ($next === null)
                            <span class="erp-chip erp-chip-ok">Fully written down</span>
                        @else
                            {{ date('d M Y', strtotime($next)) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <x-slot:footer>
            The schedule is a projection. Changing a life or a salvage value changes the future rows and leaves the posted months alone — the entries that exist, exist. The register carries the accumulated figure the ledger gave it, and no page in this module can move it by hand.
        </x-slot:footer>
    </x-ui.table-shell>
@endif

<x-ui.related-pages />
