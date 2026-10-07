@extends('layouts.app')

@section('page_title', 'Import History')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Products · Import"
        title="Import history"
        subtitle="Every catalogue file this company has read, previews included, with what each one actually did. Three days later, this is the answer to “where did this product come from?”."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('inventory.products.import') }}">
                <i class="bi bi-upload" aria-hidden="true"></i> Import a file
            </a>
            @if ($perm('inventory.products.export'))
                <a class="btn btn-outline-secondary" href="{{ route('inventory.products.export') }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export catalogue
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Runs recorded" :value="number_format($totals['runs'])" icon="bi-clock-history"
                  hint="Previews are kept too — they are statements about a file" />
        <x-ui.kpi label="Products created" :value="number_format($totals['created'])" icon="bi-plus-circle"
                  hint="Across every import this company has run" />
        <x-ui.kpi label="Products updated" :value="number_format($totals['updated'])" icon="bi-pencil-square"
                  hint="Only cells the files actually filled" />
        <x-ui.kpi label="Rows refused" :value="number_format($totals['failed'])" icon="bi-x-octagon"
                  hint="Each one is listed on its run's page, with the reason" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('inventory.products.import.history') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">File name</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="products-october.csv…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Outcome</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                <option value="preview" @selected($filters['status'] === 'preview')>Preview (wrote nothing)</option>
                <option value="imported" @selected($filters['status'] === 'imported')>Imported</option>
                <option value="imported_with_errors" @selected($filters['status'] === 'imported_with_errors')>Imported with errors</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if ($filters['q'] !== '' || $filters['status'])
                <a class="btn btn-link" href="{{ route('inventory.products.import.history') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$runs->total().' run'.($runs->total() === 1 ? '' : 's')">
        <thead>
            <tr>
                <th>When</th>
                <th>File</th>
                <th>By</th>
                <th class="erp-th-num">Rows</th>
                <th class="erp-th-num">Created</th>
                <th class="erp-th-num">Updated</th>
                <th class="erp-th-num">Failed</th>
                <th>Outcome</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($runs as $run)
                <tr>
                    <td data-label="When" class="text-nowrap">
                        {{ $run->created_at->format('d M Y') }}
                        <span class="d-block erp-td-muted small">{{ $run->created_at->format('H:i') }}</span>
                    </td>
                    <td data-label="File">
                        <span class="erp-cell-strong">{{ $run->original_name }}</span>
                        @if ($run->headers)
                            <span class="d-block erp-td-muted small">{{ count($run->headers) }} column(s)</span>
                        @endif
                    </td>
                    <td data-label="By" class="erp-td-muted">{{ $run->actor?->name ?? 'System' }}</td>
                    <td data-label="Rows" class="erp-td-num">{{ number_format($run->rows_total) }}</td>
                    <td data-label="Created" class="erp-td-num">
                        {{ $run->rows_created > 0 ? number_format($run->rows_created) : '—' }}
                    </td>
                    <td data-label="Updated" class="erp-td-num">
                        {{ $run->rows_updated > 0 ? number_format($run->rows_updated) : '—' }}
                    </td>
                    <td data-label="Failed" class="erp-td-num">
                        @if ($run->rows_failed > 0)
                            <span class="erp-cell-strong">{{ number_format($run->rows_failed) }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Outcome">
                        <x-ui.status :value="$run->stateTone()" :label="$run->stateLabel()" />
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="{{ route('inventory.products.import.show', $run) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty icon="bi-clock-history" title="No import has been run yet"
                            text="Import a CSV of products and this page becomes the receipt: how many rows landed, how many were refused and why, and who sent the file."
                            action="Import a file" :href="route('inventory.products.import')" />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($runs->isNotEmpty())
            <x-slot:footer>
                <span class="erp-td-muted">Page totals:
                    {{ number_format($runs->getCollection()->sum('rows_created')) }} created ·
                    {{ number_format($runs->getCollection()->sum('rows_updated')) }} updated ·
                    {{ number_format($runs->getCollection()->sum('rows_failed')) }} refused</span>
                <span class="erp-td-muted">A run is never edited — a re-import is a new one.</span>
            </x-slot:footer>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">{{ $runs->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
