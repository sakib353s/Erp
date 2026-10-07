@php($showDays = $showDays ?? false)

<form class="erp-filterbar" method="GET" action="{{ $action }}" role="search">
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <div class="erp-input-group">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                   placeholder="SKU, code or product name…" autocomplete="off">
        </div>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="warehouse">Warehouse</label>
        <select class="form-select" id="warehouse" name="warehouse">
            <option value="">All warehouses</option>
            @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}" @selected(($filters['warehouse'] ?? null) === $warehouse->id)>{{ $warehouse->name }}</option>
            @endforeach
        </select>
    </div>
    @if ($showDays)
        <div class="erp-filter">
            <label class="form-label" for="days">Idle for at least (days)</label>
            <input class="form-control erp-num" type="number" min="1" max="3650" id="days" name="days"
                   value="{{ request('days', $threshold) }}">
        </div>
    @endif
    <div class="erp-filterbar-actions">
        @if (($filters['q'] ?? null) !== null || ($filters['warehouse'] ?? null) !== null || ($showDays && request('days') !== null))
            <a class="btn btn-link" href="{{ $action }}">Reset</a>
        @endif
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="{{ $action.(str_contains($action, '?') ? '&' : '?').http_build_query(request()->except('format') + ['format' => 'csv']) }}">
            <i class="bi bi-download" aria-hidden="true"></i> CSV
        </a>
    </div>
</form>
