@extends('layouts.app')

@section('page_title', 'Scheduled reports')

@section('content')
    <x-ui.page-header
        eyebrow="Reports · Scheduled Reports"
        title="Reports that run themselves"
        subtitle="A schedule is an instruction to produce a report on a rhythm, not a promise that it worked. So every execution is written down with its row count and its snapshot, a failure keeps its reason on the row, and the next run time only moves when a run has actually finished — which is what makes “it ran last night” a fact on this page rather than something somebody remembers."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('reports.index') }}">
                <i class="bi bi-grid" aria-hidden="true"></i> All families
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('reports.custom') }}">
                <i class="bi bi-file-earmark-ruled" aria-hidden="true"></i> Saved reports
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Schedules"
            value="{{ $schedules->count() }}"
            icon="bi-clock-history"
            :hint="$schedules->where('is_active', true)->count().' active, '.$schedules->where('is_active', false)->count().' paused'" />
        <x-ui.kpi
            label="Due now"
            value="{{ $due }}"
            icon="bi-alarm"
            hint="Active schedules whose next run time has arrived — the command picks them up" />
        <x-ui.kpi
            label="Runs filed"
            value="{{ $runs->count() }}"
            icon="bi-archive"
            hint="Executions recorded against a schedule, most recent first" />
        <x-ui.kpi
            label="Last failure"
            value="{{ $runs->firstWhere('status', '!=', 'completed')?->started_at?->format('d M H:i') ?? '—' }}"
            icon="bi-exclamation-triangle"
            hint="A failed run keeps its reason; it is never silently retried as if it had worked" />
    </div>

    @if ($due > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $due }} schedule(s) are past their run time</strong>
                The scheduled command produces them; nothing on this screen runs on its own. If they stay past due, the queue or the scheduler is not
                running — which is worth knowing before somebody assumes last night's reports went out.
            </div>
        </div>
    @endif

    <div class="erp-table-shell mb-3" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">
                What is scheduled
                <span class="erp-chip erp-chip-outline">{{ $schedules->count() }} schedule(s)</span>
            </h2>
        </div>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Schedule</th>
                        <th>Report</th>
                        <th>Rhythm</th>
                        <th>Next run</th>
                        <th>Last run</th>
                        <th>State</th>
                        <th>Written by</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($schedules as $schedule)
                        <tr>
                            <td><span class="erp-cell-strong">{{ $schedule->name }}</span></td>
                            <td>
                                {{ $schedule->reportDefinition?->name ?? '—' }}
                                <div class="erp-td-muted font-monospace">{{ $schedule->reportDefinition?->code }}</div>
                            </td>
                            <td><span class="erp-chip erp-chip-soft">{{ $schedule->frequency }}</span></td>
                            <td>
                                {{ $schedule->next_run_at?->format('Y-m-d H:i') ?? '—' }}
                                @if ($schedule->is_active && $schedule->next_run_at && $schedule->next_run_at->isPast())
                                    <div class="erp-td-muted">past due</div>
                                @endif
                            </td>
                            <td class="erp-td-muted">{{ $schedule->last_run_at?->format('Y-m-d H:i') ?? 'never yet' }}</td>
                            <td>
                                <span class="erp-status erp-status-{{ $schedule->is_active ? 'active' : 'paused' }}">
                                    {{ $schedule->is_active ? 'active' : 'paused' }}
                                </span>
                            </td>
                            <td class="erp-td-muted">{{ $schedule->creator?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty
                                    title="Nothing is scheduled yet"
                                    text="Save a custom report first — a schedule has to have a definition to produce — then tell it how often to run."
                                    icon="bi-clock-history" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="erp-split mb-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Schedule a saved report</h2>
                    <p class="erp-card-sub">The definition has to exist and belong to this company. A schedule starts due immediately, so the next run of the command produces it.</p>
                </div>
            </header>
            @if ($definitions->isEmpty())
                <div class="p-3">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        There is nothing to schedule yet — a schedule needs a saved report first.
                        <a href="{{ route('reports.custom') }}">See the saved reports</a>.
                    </p>
                </div>
            @else
                <form class="erp-form p-3" method="post" action="{{ route('reports.scheduled.store') }}">
                    @csrf

                    <div class="erp-form-field">
                        <label class="erp-field-label" for="schedule-definition">Report</label>
                        <select class="form-select" id="schedule-definition" name="definition_id" required>
                            @foreach ($definitions as $definition)
                                <option value="{{ $definition->id }}">{{ $definition->name }} ({{ $definition->code }})</option>
                            @endforeach
                        </select>
                        @error('definition_id')
                            <p class="erp-field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="erp-field-label" for="schedule-name">Name it</label>
                        <input class="form-control" id="schedule-name" type="text" name="name" maxlength="120" required
                               value="{{ old('name') }}" placeholder="e.g. Weekly receivables ageing for the manager">
                        <p class="form-text">This is what the run log will call it, so name it for the person who reads it, not for the table.</p>
                        @error('name')
                            <p class="erp-field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="erp-field-label" for="schedule-frequency">How often</label>
                        <select class="form-select" id="schedule-frequency" name="frequency" required>
                            @foreach (\App\Domain\Reporting\ScheduledReport::FREQUENCIES as $frequency)
                                <option value="{{ $frequency }}">{{ ucfirst($frequency) }}</option>
                            @endforeach
                        </select>
                        @error('frequency')
                            <p class="erp-field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="erp-form-actions">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Schedule it
                        </button>
                    </div>
                </form>
            @endif
        </section>

        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Runs filed by the scheduler</h2>
                    <p class="erp-card-sub">What each scheduled run produced, and what it could not.</p>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Schedule</th>
                            <th>State</th>
                            <th class="erp-th-num">Rows</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($runs as $run)
                            <tr>
                                <td>{{ $run->started_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td><span class="erp-cell-strong">{{ $run->scheduledReport?->name ?? '—' }}</span></td>
                                <td>
                                    <span class="erp-status erp-status-{{ $run->status === 'completed' ? 'posted' : 'failed' }}">{{ $run->status }}</span>
                                    @if ($run->error)
                                        <div class="erp-td-muted">{{ $run->error }}</div>
                                    @endif
                                </td>
                                <td class="erp-td-num">{{ $run->row_count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="erp-td-muted">The scheduler has not filed anything yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 pt-0">
                <p class="erp-filter-note mb-0">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Delivery to recipients and PDF/XLSX filing are not built: a run today produces its rows and records them here. Until a delivery
                    channel exists, this page is the report — it does not pretend to have emailed anything.
                </p>
            </div>
        </section>
    </div>

    <x-ui.related-pages />
@endsection
