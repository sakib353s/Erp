@php
    /* §12-11 — the diary. What is coming, what has happened, and what still owes
       minutes. The list is scoped: you see the meetings you are on, and whoever
       runs the diary sees the office's. */
@endphp

<x-ui.page-header
    eyebrow="Business Management · Meetings"
    title="The diary, and what came out of it"
    subtitle="A meeting is only worth the record it leaves: who was asked, who came, what was said, and the work that followed. The minutes are a page under the meeting, and an action item is a real task on the task board — not a line in a document nobody opens again."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.minutes') }}">
            <i class="bi bi-journal-check" aria-hidden="true"></i> Minutes register
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.actionItems') }}">
            <i class="bi bi-list-check" aria-hidden="true"></i> Action items
            @if ($summary['action_overdue'] > 0)
                <span class="erp-chip erp-chip-danger ms-1">{{ $summary['action_overdue'] }} overdue</span>
            @endif
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('meetings.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Call a meeting
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Coming up" :value="$summary['upcoming']" icon="bi-calendar-event"
              hint="{{ $seesEverybody ? 'Across the office, from now on' : 'Meetings you are on, from now on' }}" />
    <x-ui.kpi label="Today" :value="$summary['today']" icon="bi-clock-history"
              hint="Scheduled for today" />
    <x-ui.kpi label="Held, no minutes" :value="$summary['awaiting_minutes']" icon="bi-pencil"
              :hint="$summary['awaiting_minutes'] > 0 ? 'A meeting nobody wrote down is a meeting that will be re-held' : 'Every held meeting has been written up'" />
    <x-ui.kpi label="Action items open" :value="$summary['action_items']" icon="bi-list-check"
              :hint="$summary['action_overdue'] > 0 ? $summary['action_overdue'].' of them are past their date' : 'Nothing is past its date'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('meetings.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="scope">Show</label>
        <select class="form-select" name="scope" id="scope" data-erp-autosubmit>
            <option value="">Open meetings</option>
            <option value="upcoming" @selected($scope === 'upcoming')>Still to come</option>
            <option value="minutes-due" @selected($scope === 'minutes-due')>Held, waiting for minutes</option>
            <option value="mine" @selected($scope === 'mine')>Only the ones I am on</option>
            <option value="all" @selected($scope === 'all')>Everything, including cancelled</option>
        </select>
    </div>
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Title, place or agenda">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('meetings.index') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="Meetings" :count="$meetings->total().' meeting(s)'">
    <thead>
        <tr>
            <th>Meeting</th>
            <th>When</th>
            <th>Where</th>
            <th>People</th>
            <th>State</th>
            <th>Record</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($meetings as $meeting)
            <tr>
                <td data-label="Meeting">
                    <a class="erp-cell-strong" href="{{ route('meetings.show', $meeting) }}">{{ $meeting->title }}</a>
                    @if ($meeting->agenda)
                        <div class="erp-td-muted">{{ \Illuminate\Support\Str::limit($meeting->agenda, 90) }}</div>
                    @endif
                </td>
                <td data-label="When" class="erp-td-muted">
                    {{ $meeting->starts_at->format('d M Y') }}
                    <div>
                        {{ $meeting->starts_at->format('H:i') }}–{{ $meeting->endsAt()->format('H:i') }}
                        @if ($meeting->isToday())
                            <span class="erp-chip erp-chip-warn ms-1">today</span>
                        @endif
                    </div>
                </td>
                <td data-label="Where" class="erp-td-muted">{{ $meeting->location ?: '—' }}</td>
                <td data-label="People" class="erp-td-muted">
                    {{ $meeting->attendees_count }} on the list
                    @if ($meeting->status === \App\Domain\Business\Meeting::STATUS_HELD)
                        <div>{{ $meeting->presentCount() }} present</div>
                    @endif
                </td>
                <td data-label="State">
                    <x-ui.status :value="$meeting->status" :label="$meeting->statusLabel()" />
                </td>
                <td data-label="Record" class="erp-td-muted">
                    @if ($meeting->hasMinutes())
                        <a href="{{ route('meetings.show', $meeting) }}#minutes">Minutes on file</a>
                    @elseif ($meeting->needsMinutes())
                        <span class="erp-chip erp-chip-warn">no minutes yet</span>
                    @elseif ($meeting->status === \App\Domain\Business\Meeting::STATUS_CANCELLED)
                        Cancelled{{ $meeting->cancelled_reason ? ': '.$meeting->cancelled_reason : '' }}
                    @else
                        Not held yet
                    @endif
                </td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('meetings.show', $meeting) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="Nothing in this view"
                        text="No meeting matches the filter. The diary shows the meetings you are on; calling one is under “Call a meeting”."
                        icon="bi-calendar-event" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($meetings->hasPages())
        <x-slot:footer>{{ $meetings->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
