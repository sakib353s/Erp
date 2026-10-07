@extends('layouts.app')

@section('page_title', 'Custom Sales Report')

@php
    $columnState = array_flip($currentColumns);
    $filterKeys = array_keys($schema['filters']);
@endphp

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Custom Sales Report</h1>
            <p class="erp-page-sub">
                Choose source, columns and filters · scope enforced server-side on every run
                · {{ $branchScope === null ? 'your scope: all branches' : 'your scope: '.count($branchScope).' assigned branch(es)' }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.summary') }}">Summary</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.trend') }}">Trend</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" action="{{ route('sales.reports.custom') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="source">Source</label>
                <select class="form-select" id="source" name="source">
                    @foreach ($sources as $key => $label)
                        <option value="{{ $key }}" @selected($key === $currentSource)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="definition">Open saved definition</label>
                <select class="form-select" id="definition" name="definition">
                    <option value="">— none —</option>
                    @foreach ($definitions as $definition)
                        <option value="{{ $definition->id }}"
                            @selected($selectedDefinition?->id === $definition->id)>
                            {{ $definition->name }} ({{ $definition->code }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Load</button>
            </div>
        </form>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3 mb-3">Build &amp; run — {{ $schema['label'] }}</h2>
        <form method="POST" action="{{ route('sales.reports.custom.run') }}">
            @csrf
            <input type="hidden" name="source" value="{{ $currentSource }}">

            <div class="row g-3 mb-3">
                <div class="col-12">
                    <label class="form-label d-block">Columns</label>
                    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4">
                        @foreach ($schema['columns'] as $key => $column)
                            <div class="col">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="columns[]"
                                           value="{{ $key }}" id="col-{{ $key }}"
                                           @checked(isset($columnState[$key]))>
                                    <label class="form-check-label" for="col-{{ $key }}">
                                        {{ $column['label'] }}
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @foreach ($filterKeys as $key)
                    @php $filter = $schema['filters'][$key]; @endphp
                    <div class="col-md-3">
                        <label class="form-label" for="filter-{{ $key }}">{{ $filter['label'] }}</label>
                        <input class="form-control" id="filter-{{ $key }}"
                               name="filters[{{ $key }}]"
                               type="{{ str_starts_with($filter['type'], 'date_') ? 'date' : 'text' }}"
                               value="{{ $currentFilters[$key] ?? '' }}">
                    </div>
                @endforeach

                <div class="col-md-2">
                    <label class="form-label" for="limit">Row limit</label>
                    <input class="form-control" id="limit" name="limit" type="number" min="1" max="500"
                           value="100">
                </div>
            </div>

            <button class="btn btn-primary" type="submit">Run report</button>
        </form>
    </div>

    @if ($result !== null)
        <div class="erp-card mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="erp-h3 mb-0">Results — {{ $sources[$result['source']] }}</h2>
                <span class="text-muted small">
                    {{ $result['row_count'] }} matching row(s) · showing {{ $result['returned'] }}
                    @if ($result['truncated'])
                        · <strong>truncated at {{ $result['limit'] }} — raise the limit to see more</strong>
                    @endif
                </span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            @foreach ($result['columns'] as $column)
                                <th>{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            <tr>
                                @foreach ($result['columns'] as $column)
                                    <td>{{ $row->{$column['key']} ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ max(1, count($result['columns'])) }}" class="text-center text-muted py-4">
                                    No rows match this selection.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($result['filters'] !== [])
                <p class="text-muted small mt-2 mb-0">
                    Applied filters:
                    @foreach ($result['filters'] as $key => $value)
                        <code>{{ $key }}={{ is_scalar($value) ? $value : json_encode($value) }}</code>@if(!$loop->last),@endif
                    @endforeach
                </p>
            @endif
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Save this selection as a definition</h2>
                <form method="POST" action="{{ route('sales.reports.custom.definitions') }}">
                    @csrf
                    <input type="hidden" name="source" value="{{ $currentSource }}">
                    @foreach ($currentColumns as $column)
                        <input type="hidden" name="columns[]" value="{{ $column }}">
                    @endforeach
                    @foreach ($currentFilters as $key => $value)
                        <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
                    @endforeach
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label" for="def-code">Code</label>
                            <input class="form-control" id="def-code" name="code" required
                                   pattern="[a-z0-9][a-z0-9_-]*" placeholder="open-ar">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="def-name">Name</label>
                            <input class="form-control" id="def-name" name="name" required
                                   placeholder="Open AR by customer">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-outline-primary w-100" type="submit"
                                @disabled(count($currentColumns) === 0)>Save definition</button>
                        </div>
                    </div>
                    @if (count($currentColumns) === 0)
                        <p class="text-muted small mt-2 mb-0">Tick at least one column, run, then save.</p>
                    @endif
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Save current filters</h2>
                <form method="POST" action="{{ route('sales.reports.custom.saved-filters') }}">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label" for="sf-definition">Definition</label>
                            <select class="form-select" id="sf-definition" name="definition_id" required>
                                <option value="">— choose —</option>
                                @foreach ($definitions as $definition)
                                    <option value="{{ $definition->id }}"
                                        @selected($selectedDefinition?->id === $definition->id)>
                                        {{ $definition->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sf-name">Filter name</label>
                            <input class="form-control" id="sf-name" name="name" required placeholder="This month">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-outline-primary w-100" type="submit">Save filter</button>
                        </div>
                    </div>
                    @foreach ($currentFilters as $key => $value)
                        <input type="hidden" name="payload[{{ $key }}]" value="{{ $value }}">
                    @endforeach
                </form>
            </div>
        </div>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Saved report definitions</h2>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Source</th>
                        <th class="text-end">Columns</th>
                        <th>Saved filters</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($definitions as $definition)
                        <tr>
                            <td class="fw-semibold">{{ $definition->code }}</td>
                            <td>{{ $definition->name }}</td>
                            <td>{{ $sources[$definition->source] ?? $definition->source }}</td>
                            <td class="text-end">{{ count($definition->columns) }}</td>
                            <td>
                                @forelse ($definition->savedFilters as $saved)
                                    <a class="badge text-bg-light border"
                                       href="{{ route('sales.reports.custom', ['definition' => $definition->id, 'source' => $definition->source, 'filters' => $saved->payload]) }}">
                                        {{ $saved->name }}
                                    </a>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('sales.reports.custom', ['definition' => $definition->id]) }}">Open</a>
                                <form method="POST" action="{{ route('sales.reports.custom.runs') }}"
                                      class="d-inline">
                                    @csrf
                                    <input type="hidden" name="definition_id" value="{{ $definition->id }}">
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Run now</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No saved definitions yet — build a selection above and save it.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Schedule a definition</h2>
                <form method="POST" action="{{ route('sales.reports.custom.schedules') }}">
                    @csrf
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label" for="sched-definition">Definition</label>
                            <select class="form-select" id="sched-definition" name="definition_id" required>
                                <option value="">— choose —</option>
                                @foreach ($definitions as $definition)
                                    <option value="{{ $definition->id }}"
                                        @selected($selectedDefinition?->id === $definition->id)>
                                        {{ $definition->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sched-name">Schedule name</label>
                            <input class="form-control" id="sched-name" name="name" required
                                   placeholder="Daily open AR">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sched-frequency">Frequency</label>
                            <select class="form-select" id="sched-frequency" name="frequency">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button class="btn btn-outline-primary w-100" type="submit">Create schedule</button>
                        </div>
                    </div>
                </form>
                <p class="text-muted small mt-2 mb-0">
                    Due schedules execute via <code>php artisan reports:run-due</code>; every execution is
                    written to report runs and audited.
                </p>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Schedules</h2>
                <div class="table-responsive">
                    <table class="table erp-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Definition</th>
                                <th>Frequency</th>
                                <th>Next run</th>
                                <th>Last run</th>
                                <th>Active</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($schedules as $schedule)
                                <tr>
                                    <td>{{ $schedule->name }}</td>
                                    <td>{{ $schedule->reportDefinition?->code ?? '—' }}</td>
                                    <td>{{ $schedule->frequency }}</td>
                                    <td>{{ $schedule->next_run_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $schedule->last_run_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>{{ $schedule->is_active ? 'yes' : 'no' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No schedules.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="erp-card">
        <h2 class="erp-h3">Recent runs</h2>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Definition</th>
                        <th>Status</th>
                        <th class="text-end">Rows</th>
                        <th>Scheduled</th>
                        <th>By</th>
                        <th>Finished</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentRuns as $run)
                        <tr>
                            <td>{{ $run->id }}</td>
                            <td>{{ $run->reportDefinition?->code ?? '—' }}</td>
                            <td>
                                <span class="badge text-bg-{{ $run->status === 'completed' ? 'success' : 'danger' }}">
                                    {{ $run->status }}
                                </span>
                            </td>
                            <td class="text-end">{{ $run->row_count }}</td>
                            <td>{{ $run->scheduledReport?->name ?? '—' }}</td>
                            <td>{{ $run->triggeredBy?->name ?? '—' }}</td>
                            <td>{{ $run->finished_at?->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No runs yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
