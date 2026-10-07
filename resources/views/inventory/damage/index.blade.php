@extends('layouts.app')

@php
    $isLoss = $kind === \App\Domain\Inventory\StockDamageEntry::KIND_LOSS;
    $title = $isLoss ? 'Loss records' : 'Damage records';
    $subtitle = $isLoss
        ? 'Stock that is gone: the ledger was consumed when the entry was recorded, so this value has left the books.'
        : 'Stock we still hold but cannot sell. Damage changes where goods sit, never what they are worth — until a write-off decides they are gone.';
    $registerRoute = $isLoss ? 'inventory.loss.index' : 'inventory.damage.index';
    $createRoute = $isLoss ? 'inventory.loss.create' : 'inventory.damage.create';
@endphp

@section('page_title', $title)

@section('content')
    <x-ui.page-header eyebrow="Inventory · Damage & loss" :title="$title" :subtitle="$subtitle" :pin="true">
        <x-slot:actions>
            @if ($perm($isLoss ? 'inventory.loss.create' : 'inventory.damage.create'))
                <a class="btn btn-primary" href="{{ route($createRoute) }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $isLoss ? 'Record a loss' : 'Record damage' }}
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ $isLoss ? route('inventory.damage.index') : route('inventory.loss.index') }}">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i> {{ $isLoss ? 'Damage records' : 'Loss records' }}
            </a>
            @if ($perm('inventory.writeoffs.create'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.writeoffs.create') }}">
                    <i class="bi bi-file-earmark-x" aria-hidden="true"></i> Write-off
                </a>
            @endif
            @if ($perm('inventory.reports.view'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.reports.damage') }}">
                    <i class="bi bi-graph-down" aria-hidden="true"></i> Damage analytics
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route($registerRoute) }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="warehouse">Warehouse</label>
            <select class="form-select" id="warehouse" name="warehouse">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse'] === $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="reason">Cause</label>
            <select class="form-select" id="reason" name="reason">
                <option value="">Any cause</option>
                @foreach ($reasons as $key => $label)
                    <option value="{{ $key }}" @selected($filters['reason_code'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['from'] || $filters['to'] || $filters['warehouse'] || $filters['reason_code'])
                <a class="btn btn-link" href="{{ route($registerRoute) }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$entries->total().' entr'.($entries->total() === 1 ? 'y' : 'ies')">
        <thead>
            <tr>
                <th>Entry</th>
                <th>Date</th>
                <th>Warehouse</th>
                <th>Cause</th>
                <th>What happened</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td data-label="Entry"><code>{{ $entry->code }}</code></td>
                    <td data-label="Date">{{ $entry->entry_date?->format('d M Y') }}</td>
                    <td data-label="Warehouse" class="erp-td-muted">{{ $entry->warehouse?->name }}</td>
                    <td data-label="Cause">{{ $entry->reasonLabel() }}</td>
                    <td data-label="What happened" class="erp-td-muted">
                        <span title="{{ $entry->reason }}">{{ \Illuminate\Support\Str::limit($entry->reason, 70) }}</span>
                        <span class="d-block small">
                            @foreach ($entry->lines as $line)
                                {{ $line->product?->sku }} × {{ number_format((float) $line->qty, 4) }}@if (! $loop->last) · @endif
                            @endforeach
                        </span>
                    </td>
                    <td data-label="Lines" class="erp-td-num">{{ $entry->lines->count() }}</td>
                    <td data-label="Value" class="erp-td-num">{{ number_format((float) $entry->total_value, 2) }}</td>
                    <td data-label="Status">
                        <x-ui.status :value="$entry->status" />
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if (! $isLoss && $entry->isOpen() && $perm('inventory.damage.create'))
                            <form method="POST" action="{{ route('inventory.damage.release', $entry) }}" class="text-end">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                        title="The goods turned out fine — put them back into sellable stock">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Release
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty
                            icon="{{ $isLoss ? 'bi-question-circle' : 'bi-shield-check' }}"
                            :title="$isLoss ? 'No loss has been recorded' : 'No damage has been recorded'"
                            text="{{ $isLoss
                                ? 'A loss entry is how stock that went missing leaves the ledger with its cost accounted for.'
                                : 'Recording damage moves goods out of sellable stock and keeps them valued, so nothing is written off by accident.' }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($entries->getCollection()->isNotEmpty())
            <tfoot>
                <tr>
                    <th colspan="6">Page total of {{ number_format($entries->total()) }}</th>
                    <th class="erp-th-num">{{ number_format($entries->getCollection()->sum(fn ($e) => (float) $e->total_value), 2) }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">{{ $entries->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
