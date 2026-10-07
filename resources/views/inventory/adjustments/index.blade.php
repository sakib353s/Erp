@extends('layouts.app')

@section('page_title', 'Stock Adjustments')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Stock"
        title="Stock Adjustments"
        subtitle="An adjustment is the one document that changes stock with no counterparty behind it, so it says why, and — above the threshold — waits for a second person."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.adjustments.history') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> History
            </a>
            @if ($perm('inventory.adjustments.create'))
                <a class="btn btn-primary" href="{{ route('inventory.adjustments.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New adjustment
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Ledger, right now" :value="number_format($counts['posted'])" icon="bi-check2-circle"
                  hint="Posted — every one of these has movements behind it" />
        <x-ui.kpi label="Waiting for approval" :value="number_format($counts['pending'])" icon="bi-hourglass-split"
                  hint="{{ $counts['pending'] > 0 ? number_format($pendingValue, 2).' of stock change held back' : 'Nothing is waiting' }}" />
        <x-ui.kpi label="Rejected" :value="number_format($counts['rejected'])" icon="bi-x-octagon"
                  hint="Refused, and the stock was left exactly as it was" />
        <x-ui.kpi label="Approval threshold" :value="$threshold > 0 ? number_format($threshold, 2) : 'not set'"
                  icon="bi-sliders" hint="Settings › Inventory — 0 posts every adjustment immediately" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.adjustments.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $q }}" placeholder="Number or reason">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                @foreach (\App\Domain\Inventory\StockAdjustment::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }} ({{ $counts[$key] ?? 0 }})</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($q || $status)
                <a class="btn btn-link" href="{{ route('inventory.adjustments.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @error('adjustment') <div class="alert alert-danger">{{ $message }}</div> @enderror

    @if ($perm('inventory.adjustments.approve') && $counts['pending'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong>{{ $counts['pending'] }} adjustment(s) are waiting for you.</strong>
                Nothing here has touched stock yet. Approving writes the movements; rejecting leaves the shelf as it is.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="$adjustments->total().' document'.($adjustments->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>Adjustment</th>
                <th>Date</th>
                <th>Warehouse</th>
                <th>Reason</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($adjustments as $adjustment)
                <tr>
                    <td data-label="Adjustment">
                        <span class="erp-cell-strong"><code>{{ $adjustment->adjustment_no }}</code></span>
                        <span class="d-block erp-td-muted small">
                            {{ $adjustment->movementLabel() }}
                            @if ($adjustment->creator) · raised by {{ $adjustment->creator->name }} @endif
                        </span>
                    </td>
                    <td data-label="Date" class="erp-td-muted">{{ $adjustment->adjustment_date?->format('d M Y') }}</td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $adjustment->warehouse?->name }}</td>
                    <td data-label="Reason">{{ \Illuminate\Support\Str::limit($adjustment->reason, 70) }}</td>
                    <td data-label="Lines" class="erp-td-num">{{ number_format($adjustment->lines->count()) }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $adjustment->total_value, 2) }}</td>
                    <td data-label="Status">
                        <x-ui.status :value="$adjustment->status" :label="$adjustment->statusLabel()" />
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if ($adjustment->isPending() && $perm('inventory.adjustments.approve'))
                            <form class="d-inline" method="POST" action="{{ route('inventory.adjustments.approve', $adjustment) }}"
                                  data-confirm="Approve {{ $adjustment->adjustment_no }}? The stock will move as its lines say.">
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit">Approve</button>
                            </form>
                            <form class="d-inline" method="POST" action="{{ route('inventory.adjustments.reject', $adjustment) }}">
                                @csrf
                                <input type="hidden" name="note" value="Rejected from the adjustment register.">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                            </form>
                        @elseif ($adjustment->isPending())
                            <span class="erp-td-muted small">Waiting on someone who may approve</span>
                        @elseif ($adjustment->approver)
                            <span class="erp-td-muted small">Decided by {{ $adjustment->approver->name }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-sliders" title="No adjustment matches this filter"
                            text="An adjustment is how the ledger is told a shelf disagrees with it. Each one carries a reason, and above the threshold a second person's decision." />
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
