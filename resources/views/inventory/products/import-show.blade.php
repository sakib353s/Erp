@extends('layouts.app')

@section('page_title', 'Import run #'.$run->id)

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Products · Import"
        title="{{ $run->dry_run ? 'Preview' : 'Import' }}: {{ $run->original_name }}"
        subtitle="What this file did, row by row. A preview writes nothing; an import wrote everything below, and a re-import is a new run rather than an edit of this one."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.import') }}">
                <i class="bi bi-upload" aria-hidden="true"></i> Import another file
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.import.history') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Import history
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-meta-row mb-3">
        <x-ui.status :value="$run->stateTone()" :label="$run->stateLabel()" />
        <span class="erp-chip erp-chip-soft">
            <i class="bi bi-person" aria-hidden="true"></i> {{ $run->actor?->name ?? 'System' }}
        </span>
        <span class="erp-chip erp-chip-soft">
            <i class="bi bi-calendar-event" aria-hidden="true"></i> {{ $run->created_at->format('d M Y H:i') }}
        </span>
        @if ($run->headers)
            <span class="erp-chip">{{ count($run->headers) }} column(s) read</span>
        @endif
    </div>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Rows in the file" :value="number_format($run->rows_total)" icon="bi-list-ol"
                  hint="Blank lines are not counted" />
        <x-ui.kpi label="Created" :value="number_format($run->rows_created)" icon="bi-plus-circle"
                  hint="{{ $run->dry_run ? 'Would be created — nothing was written' : 'New products in the catalogue' }}" />
        <x-ui.kpi label="Updated" :value="number_format($run->rows_updated)" icon="bi-pencil-square"
                  hint="Only the cells the file actually filled" />
        <x-ui.kpi label="Failed" :value="number_format($run->rows_failed)" icon="bi-x-octagon"
                  hint="{{ $run->rows_failed > 0 ? 'Listed below, with the reason' : 'Every row that carried data landed' }}" />
    </div>

    @if ($run->rows_failed > 0 && ! $run->dry_run)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>This was a partial import.</strong>
                {{ number_format($run->rows_created + $run->rows_updated) }} row(s) landed and
                {{ number_format($run->rows_failed) }} did not. Fix the rows below in the file and import it again —
                the rows that already landed will simply come back unchanged.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="count($problems).' row(s) needing a look'">
        <thead>
            <tr>
                <th>Line</th>
                <th>Row</th>
                <th>What happened</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($problems as $problem)
                <tr>
                    <td data-label="Line" class="erp-td-num">
                        {{ $problem['line'] > 0 ? number_format($problem['line']) : '—' }}
                    </td>
                    <td data-label="Row">
                        @if (! empty($problem['code']) || ! empty($problem['name']))
                            <span class="erp-cell-strong">{{ $problem['name'] ?: '—' }}</span>
                            <span class="d-block erp-td-muted small"><code>{{ $problem['code'] ?: '—' }}</code></span>
                        @else
                            <span class="erp-td-muted">File</span>
                        @endif
                    </td>
                    <td data-label="What happened">{{ $problem['message'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">
                        <x-ui.empty icon="bi-check2-circle" title="Nothing was refused"
                            text="Every row that carried data was read and applied: new products were created, changed cells were updated, and rows identical to what is already in the catalogue were left alone." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if (count($problems) > 0)
            <x-slot:footer>
                <span class="erp-td-muted">Line numbers are the file's own — line 1 is the heading row.</span>
                <span class="erp-td-muted">{{ number_format($run->rows_skipped) }} row(s) carried no change.</span>
            </x-slot:footer>
        @endif
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
