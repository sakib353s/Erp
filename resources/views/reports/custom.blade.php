@extends('layouts.app')

@section('page_title', 'Custom reports')

@php
    // The builder itself is a sales-reports screen (§02-120) and is behind that
    // key; this register is only honest if it says which half the reader can use.
    $canBuild = auth()->user()?->can('sales.reports.view') ?? false;
@endphp

@section('content')
    <x-ui.page-header
        eyebrow="Reports · Custom Reports"
        title="Reports this company wrote for itself"
        subtitle="A saved report is a source, a set of columns and a set of filters — stored as those keys, never as SQL, and re-validated against the builder's whitelist on every single run, so a definition saved last year cannot reach a column that has since been closed or a branch the reader cannot see. Executions are kept with their row count and their snapshot, which is why a schedule that failed can say so rather than looking like it produced nothing."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('reports.index') }}">
                <i class="bi bi-grid" aria-hidden="true"></i> All families
            </a>
            @if ($canBuild)
                <a class="btn btn-primary" href="{{ route('sales.reports.custom') }}">
                    <i class="bi bi-sliders" aria-hidden="true"></i> Open the builder
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Saved reports"
            value="{{ $definitions->count() }}"
            icon="bi-file-earmark-ruled"
            hint="Definitions this company owns — shared, not per person" />
        <x-ui.kpi
            label="Saved filters"
            value="{{ $definitions->sum('saved_filters_count') }}"
            icon="bi-funnel"
            hint="Named filter sets hanging off those definitions" />
        <x-ui.kpi
            label="Runs recorded"
            value="{{ $definitions->sum('runs_count') }}"
            icon="bi-play-circle"
            hint="Every execution, with its row count and a snapshot of what it produced" />
        <x-ui.kpi
            label="Scheduled"
            value="{{ $schedules->count() }}"
            icon="bi-clock-history"
            hint="Definitions that run themselves — see Scheduled reports" />
    </div>

    @if ($sources !== [])
        <div class="erp-note mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">What a custom report can be built on</strong>
                The builder whitelists {{ count($sources) }} source(s): {{ implode(', ', $sources) }}. Columns are re-checked against that list on every run,
                so a definition cannot outlive the column it names — and branch scope is applied at run time from your own access, never from what
                whoever wrote the definition could see.
            </div>
        </div>
    @endif

    <div class="erp-table-shell mb-3" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">
                The definitions
                <span class="erp-chip erp-chip-outline">{{ $definitions->count() }} saved</span>
            </h2>
        </div>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Code</th>
                        <th>Source</th>
                        <th class="erp-th-num">Columns</th>
                        <th class="erp-th-num">Filters</th>
                        <th class="erp-th-num">Runs</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($definitions as $definition)
                        <tr>
                            <td>
                                <span class="erp-cell-strong">{{ $definition->name }}</span>
                                @if ($definition->creator)
                                    <div class="erp-td-muted">written by {{ $definition->creator->name }}</div>
                                @endif
                            </td>
                            <td class="erp-td-muted font-monospace">{{ $definition->code }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $definition->source }}</span></td>
                            <td class="erp-td-num">{{ count($definition->columns ?? []) }}</td>
                            <td class="erp-td-num">{{ count($definition->filters ?? []) }}</td>
                            <td class="erp-td-num">{{ $definition->runs_count }}</td>
                            <td class="text-end">
                                @if ($canBuild)
                                    <form method="post" action="{{ route('sales.reports.custom.runs') }}" data-confirm="Run “{{ $definition->name }}” now? It reads the live registers under your own scopes.">
                                        @csrf
                                        <input type="hidden" name="definition_id" value="{{ $definition->id }}">
                                        <button class="btn btn-outline-secondary btn-sm" type="submit">
                                            <i class="bi bi-play" aria-hidden="true"></i> Run
                                        </button>
                                    </form>
                                @else
                                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> needs sales.reports.view</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty
                                    title="No custom report has been saved yet"
                                    text="A custom report starts in the builder: pick a source, tick the columns you are allowed to read, save it with a name — then it is here, ready to run on a schedule."
                                    icon="bi-file-earmark-ruled" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <section class="erp-card">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Recent runs</h2>
                <p class="erp-card-sub">What actually came out, including the failures — a run that failed is recorded as failed, with the reason, rather than as an empty report.</p>
            </div>
            <div class="erp-card-actions">
                <a class="erp-chip erp-chip-outline" href="{{ route('reports.scheduled') }}">Scheduled reports</a>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="erp-table erp-table-compact">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Report</th>
                        <th>By</th>
                        <th>State</th>
                        <th class="erp-th-num">Rows</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr>
                            <td>{{ $run->started_at?->format('Y-m-d H:i') ?? $run->created_at?->format('Y-m-d H:i') }}</td>
                            <td><span class="erp-cell-strong">{{ $run->reportDefinition?->name ?? '—' }}</span></td>
                            <td class="erp-td-muted">{{ $run->triggeredBy?->name ?? 'the scheduler' }}</td>
                            <td>
                                <span class="erp-status erp-status-{{ $run->status === 'completed' ? 'posted' : 'failed' }}">{{ $run->status }}</span>
                            </td>
                            <td class="erp-td-num">{{ $run->row_count }}</td>
                            <td class="erp-td-muted">{{ $run->error ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="erp-td-muted">Nothing has been run yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-ui.related-pages />
@endsection
