@extends('layouts.app')

@section('page_title', 'Adjustment History')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Adjustment History"
        subtitle="Every adjustment this company has raised, with who raised it, who decided it and what the ledger was asked to do — the register behind the register."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.adjustments.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Adjustments
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $ledgerRows = $adjustments->getCollection();
        $posted = $ledgerRows->where('status', \App\Domain\Inventory\StockAdjustment::STATUS_POSTED);
        $pending = $ledgerRows->where('status', \App\Domain\Inventory\StockAdjustment::STATUS_PENDING);
    @endphp

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="On this page" :value="number_format($adjustments->total())" icon="bi-journal-text"
                  hint="The whole register, newest first" />
        <x-ui.kpi label="Posted here" :value="number_format($posted->count())" icon="bi-check2-circle"
                  hint="{{ number_format((float) $posted->sum('total_value'), 2) }} of counted value" />
        <x-ui.kpi label="Still waiting" :value="number_format($pending->count())" icon="bi-hourglass-split"
                  hint="Held back — no movements behind them" />
        <x-ui.kpi label="Threshold in force" :value="$threshold > 0 ? number_format($threshold, 2) : 'not set'"
                  icon="bi-sliders" hint="A setting, so it can change; the value each document was judged at does not" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.adjustments.history') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                @foreach (\App\Domain\Inventory\StockAdjustment::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($status)
                <a class="btn btn-link" href="{{ route('inventory.adjustments.history') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="The register" :count="$adjustments->total().' document'.($adjustments->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Adjustment</th>
                <th>Warehouse</th>
                <th class="erp-th-num">Value judged</th>
                <th>Raised</th>
                <th>Decided</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td data-label="Adjustment">
                        <span class="erp-cell-strong"><code>{{ $adjustment->adjustment_no }}</code></span>
                        <span class="d-block erp-td-muted small">{{ \Illuminate\Support\Str::limit($adjustment->reason, 80) }}</span>
                    </td>
                    <td data-label="Warehouse" class="erp-td-muted">
                        {{ $adjustment->warehouse?->name }}
                        <span class="d-block small">{{ $adjustment->adjustment_date?->format('d M Y') }}</span>
                    </td>
                    <td data-label="Value judged" class="erp-td-num">{{ number_format((float) $adjustment->total_value, 2) }}</td>
                    <td data-label="Raised" class="erp-td-muted">
                        {{ $adjustment->creator?->name ?? '—' }}
                        <span class="d-block small">{{ $adjustment->created_at?->format('d M Y H:i') }}</span>
                    </td>
                    <td data-label="Decided" class="erp-td-muted">
                        @if ($adjustment->approver)
                            {{ $adjustment->approver->name }}
                            <span class="d-block small">{{ $adjustment->approved_at?->format('d M Y H:i') }}</span>
                            @if ($adjustment->approval_note)
                                <span class="d-block small">"{{ \Illuminate\Support\Str::limit($adjustment->approval_note, 80) }}"</span>
                            @endif
                        @elseif ($adjustment->isPending())
                            <span class="erp-td-muted">Waiting — maker cannot decide their own document</span>
                        @else
                            Posted without approval
                        @endif
                    </td>
                    <td data-label="Status">
                        <x-ui.status :value="$adjustment->status" :label="$adjustment->statusLabel()" />
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty icon="bi-clock-history" title="Nothing in the register yet"
                            text="Once an adjustment is raised it is listed here for good — posted, waiting or refused — with the person on each side of the decision." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $adjustments->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
