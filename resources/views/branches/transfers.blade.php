@php
    /* §12-08 — what is moving around the company, branch to branch. */
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $pending = $transfers->where('status', \App\Domain\Inventory\StockTransfer::STATUS_PENDING);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Branches · Transfer"
    title="Stock moving between branches"
    subtitle="Every transfer whose two ends belong to different branches. A move between two warehouses of the same branch is stockroom work and is not here — it never crossed a boundary. Each row belongs to the branch it left from: open that branch's desk to raise the next one."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('branches.compare') }}">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Comparison
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('inventory.transfers.index') }}">
            <i class="bi bi-box-seam" aria-hidden="true"></i> Stock desk
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Cross-branch transfers" :value="$transfers->count()" icon="bi-arrow-left-right"
              hint="All time, newest first" />
    <x-ui.kpi label="Waiting for approval" :value="$pending->count()" icon="bi-hourglass-split"
              :hint="$pending->isNotEmpty()
                  ? 'Nothing has left the warehouse on these'
                  : 'Nothing is held'" />
    <x-ui.kpi label="Value in flight" :value="$money($pending->sum('total_value'))" icon="bi-cash-stack"
              hint="The held transfers, at the cost they were raised with" />
    <x-ui.kpi label="Approval threshold" :value="$money($threshold)" icon="bi-sliders"
              hint="Above this a transfer waits for a second person" />
</div>

@if ($transfers->isEmpty())
    <x-ui.empty
        icon="bi-arrow-left-right"
        title="Nothing has crossed a branch boundary yet"
        text="Open a branch and use its transfer desk to send stock to another branch." />
@else
    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Transfer</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="text-end">Lines</th>
                        <th scope="col" class="text-end">Value</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Branch</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfers as $transfer)
                        <tr>
                            <td><code>{{ $transfer->transfer_no }}</code></td>
                            <td>
                                {{ $transfer->fromWarehouse?->name ?? '—' }}
                                <div class="text-body-secondary small">{{ $transfer->fromWarehouse?->branch?->name ?? '—' }}</div>
                            </td>
                            <td>
                                {{ $transfer->toWarehouse?->name ?? '—' }}
                                <div class="text-body-secondary small">{{ $transfer->toWarehouse?->branch?->name ?? '—' }}</div>
                            </td>
                            <td>{{ $transfer->transfer_date?->format('d M Y') }}</td>
                            <td class="text-end">{{ $transfer->lines->count() }}</td>
                            <td class="text-end">{{ $money($transfer->total_value) }}</td>
                            <td><x-ui.status :value="$transfer->status" /></td>
                            <td class="text-end">
                                @if ($transfer->fromWarehouse?->branch)
                                    <a class="btn btn-sm btn-light" href="{{ route('branches.transfer', $transfer->fromWarehouse->branch) }}">
                                        Desk
                                    </a>
                                @else
                                    <span class="text-body-secondary">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<x-ui.related-pages />
