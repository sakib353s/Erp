@php
    /* §12-11 — the minutes register: what has been written up, and what has not.
       The second column is the one that matters; a held meeting with no minutes
       is a meeting that will be held again. */
    $state = $filters['state'] ?? '';
@endphp

<x-ui.page-header
    eyebrow="Business Management · Meetings · Minutes"
    title="What was written down"
    subtitle="Every meeting that has been held, with the page it produced — and the ones that still owe one. The minutes are kept with the meeting rather than in a folder, so “what did we decide about the Narayanganj lease?” is answered by the register, not by somebody's memory of an email."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The diary
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.actionItems') }}">
            <i class="bi bi-list-check" aria-hidden="true"></i> Action items
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Meetings held" :value="$summary['attended']" icon="bi-journal-check"
              hint="Held meetings on the record" />
    <x-ui.kpi label="Waiting for minutes" :value="$summary['awaiting_minutes']" icon="bi-pencil"
              :hint="$summary['awaiting_minutes'] > 0 ? 'Held and not written up yet' : 'Every held meeting is written up'" />
    <x-ui.kpi label="Cancelled" :value="$summary['cancelled']" icon="bi-x-octagon"
              hint="Called off, with the reason kept" />
    <x-ui.kpi label="Action items open" :value="$summary['action_items']" icon="bi-list-check"
              :hint="$summary['action_overdue'] > 0 ? $summary['action_overdue'].' past their date' : 'Nothing is past its date'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('meetings.minutes') }}">
    <div class="erp-filter">
        <label class="form-label" for="state">Show</label>
        <select class="form-select" name="state" id="state" data-erp-autosubmit>
            <option value="">Everything held</option>
            <option value="recorded" @selected($state === 'recorded')>Minutes on file</option>
            <option value="pending" @selected($state === 'pending')>Still owing minutes</option>
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('meetings.minutes') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="Held meetings" :count="$meetings->total().' meeting(s)'">
    <thead>
        <tr>
            <th>Meeting</th>
            <th>Held</th>
            <th>Chaired by</th>
            <th>Attendance</th>
            <th>Minutes</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($meetings as $meeting)
            <tr>
                <td data-label="Meeting">
                    <a class="erp-cell-strong" href="{{ route('meetings.show', $meeting) }}">{{ $meeting->title }}</a>
                    @if ($meeting->location)
                        <div class="erp-td-muted">{{ $meeting->location }}</div>
                    @endif
                </td>
                <td data-label="Held" class="erp-td-muted">
                    {{ ($meeting->held_at ?? $meeting->starts_at)->format('d M Y') }}
                    <div>{{ $meeting->starts_at->format('H:i') }}–{{ $meeting->endsAt()->format('H:i') }}</div>
                </td>
                <td data-label="Chaired by" class="erp-td-muted">{{ $meeting->chair?->name ?? '—' }}</td>
                <td data-label="Attendance" class="erp-td-muted">
                    {{ $meeting->presentCount() }} present
                    @if ($meeting->attendees->where('attendance', 'apology')->count() > 0)
                        · {{ $meeting->attendees->where('attendance', 'apology')->count() }} apology(s)
                    @endif
                    @if ($meeting->attendees->whereNull('attendance')->count() > 0)
                        <div class="erp-td-muted">{{ $meeting->attendees->whereNull('attendance')->count() }} not marked</div>
                    @endif
                </td>
                <td data-label="Minutes">
                    @if ($meeting->hasMinutes())
                        <span class="erp-chip erp-chip-soft">on file</span>
                        <div class="erp-td-muted">
                            {{ $meeting->minutes_recorded_at?->format('d M Y') }}
                            @if ($meeting->minuteTaker)
                                · {{ $meeting->minuteTaker->name }}
                            @endif
                        </div>
                    @else
                        <span class="erp-chip erp-chip-warn">still owing</span>
                    @endif
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('meetings.show', $meeting) }}#minutes">
                        {{ $meeting->hasMinutes() ? 'Read it' : 'Write it' }}
                    </a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty
                        title="No meeting has been held yet"
                        text="When a meeting is marked held it appears here, and stays until somebody writes down what was decided."
                        icon="bi-journal-check" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($meetings->hasPages())
        <x-slot:footer>{{ $meetings->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
