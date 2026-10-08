@php
    /* §12-14 — the disposal register: what was written off, why, and what came back. */
    $bookValueWrittenOff = round($summary['cost'] - $summary['accumulated'], 2);
    $gainOrLoss = round($summary['proceeds'] - $bookValueWrittenOff, 2);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Assets · Disposals"
    title="What was sold, scrapped or written off"
    subtitle="A disposal is a decision with a date, a reason and — usually — something coming back. The register keeps all three, so “where did the 2019 Hiace go?” has an answer years later, and the difference between what it was carried at and what it fetched is visible to the people who have to account for it."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('assets.index') }}">
            <i class="bi bi-hdd-stack" aria-hidden="true"></i> Asset register
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('assets.depreciation') }}">
            <i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Depreciation desk
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Written off" :value="$summary['count']" icon="bi-archive"
              hint="Every asset taken off the working register, ever" />
    <x-ui.kpi label="This year" :value="$summary['this_year']" icon="bi-calendar3"
              :hint="'Disposals dated in '.now()->year" />
    <x-ui.kpi label="Original cost" :value="'৳'.number_format($summary['cost'], 2)" icon="bi-cash-stack"
              hint="What these assets cost when they were bought" />
    <x-ui.kpi label="Depreciated before disposal" :value="'৳'.number_format($summary['accumulated'], 2)"
              icon="bi-graph-down-arrow"
              hint="The part of that cost already charged to expense by real journal entries" />
    <x-ui.kpi label="Carried value at disposal" :value="'৳'.number_format($bookValueWrittenOff, 2)" icon="bi-wallet2"
              hint="Cost less accumulated — what the balance sheet still carried" />
    <x-ui.kpi label="Proceeds" :value="'৳'.number_format($summary['proceeds'], 2)" icon="bi-cash-coin"
              :hint="$summary['proceeds'] > 0
                        ? ($gainOrLoss >= 0 ? '৳'.number_format(abs($gainOrLoss), 2).' above the carried value' : '৳'.number_format(abs($gainOrLoss), 2).' below the carried value')
                        : 'Nothing came back — scrapped, lost or given away'" />
</div>

<x-ui.table-shell title="The disposal register" :count="$disposed->total().' asset(s)'">
    <thead>
        <tr>
            <th>Asset</th>
            <th>Kind</th>
            <th>Disposed on</th>
            <th>Why</th>
            <th class="text-end">Cost</th>
            <th class="text-end">Carried value</th>
            <th class="text-end">Proceeds</th>
            <th>Written off by</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($disposed as $asset)
            <tr>
                <td data-label="Asset">
                    <a class="erp-cell-strong" href="{{ route('assets.show', $asset) }}">{{ $asset->name }}</a>
                    <div class="erp-td-muted">
                        {{ $asset->code }}
                        @if ($asset->registration_no)
                            · {{ $asset->registration_no }}
                        @endif
                    </div>
                </td>
                <td data-label="Kind" class="erp-td-muted">{{ $asset->categoryLabel() }}</td>
                <td data-label="Disposed on" class="erp-td-muted">{{ $asset->disposed_on?->format('d M Y') ?? '—' }}</td>
                <td data-label="Why">{{ $asset->disposal_reason ?: '—' }}</td>
                <td data-label="Cost" class="erp-td-num text-end">
                    {{ $asset->acquisition_cost !== null ? '৳'.number_format((float) $asset->acquisition_cost, 2) : '—' }}
                </td>
                <td data-label="Carried value" class="erp-td-num text-end">
                    {{ $asset->bookValue() !== null ? '৳'.number_format($asset->bookValue(), 2) : '—' }}
                </td>
                <td data-label="Proceeds" class="erp-td-num text-end">
                    {{ $asset->disposal_proceeds !== null ? '৳'.number_format((float) $asset->disposal_proceeds, 2) : 'Nothing' }}
                </td>
                <td data-label="Written off by" class="erp-td-muted">
                    {{ $asset->disposedBy?->name ?? 'Not recorded' }}
                    <div class="erp-td-muted">{{ $asset->branch?->name ?? 'Company-wide' }}</div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nothing has been written off"
                        text="When something is sold, scrapped or lost, it is written off from its own page — with the date, the reason and whatever came back. The register keeps it; the working lists let it go."
                        icon="bi-archive" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($disposed->hasPages())
        <x-slot:footer>{{ $disposed->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<div class="erp-note erp-note-info">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>What this page does not do:</strong> post the disposal. Writing off the gain or loss, clearing the accumulated depreciation of an asset that has left, and booking the proceeds are accounting entries on the journals — this register records the physical fact and the money attached to it, and leaves the ledger entries to the people who keep the ledger. The carried value above is exactly the figure those entries start from.
    </div>
</div>

<x-ui.related-pages />
