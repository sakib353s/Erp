@php
    /* §12-11 — what the meetings decided, as work. These are tasks with a
       meeting_id: the same rows the task board shows, read here by the meeting
       that produced them, overdue first. */
    $grouped = $items->groupBy(fn ($task) => $task->meeting_id);
    $overdue = $items->filter(fn ($task) => $task->isOverdue());
@endphp

<x-ui.page-header
    eyebrow="Business Management · Meetings · Action items"
    title="What came out of the meetings"
    subtitle="An action item is a task that remembers the meeting it came from — it lives on the task board, is assigned like any other work, and closes like any other work. This page is the other view of the same rows: by the meeting that decided them."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.index') }}">
            <i class="bi bi-calendar-event" aria-hidden="true"></i> The diary
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.minutes') }}">
            <i class="bi bi-journal-check" aria-hidden="true"></i> Minutes
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">
            <i class="bi bi-list-task" aria-hidden="true"></i> My tasks
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Open action items" :value="$items->count()" icon="bi-list-check"
              hint="{{ $seesEverybody ? 'Across every meeting in the company' : 'Raised out of meetings you are on and assigned to you' }}" />
    <x-ui.kpi label="Overdue" :value="$overdue->count()" icon="bi-alarm"
              :hint="$overdue->count() > 0 ? 'Past the due date and still open' : 'Nothing is past its date'" />
    <x-ui.kpi label="Meetings with open work" :value="$grouped->count()" icon="bi-calendar-event"
              hint="Meetings that still have something outstanding" />
    <x-ui.kpi label="Unassigned" :value="$items->whereNull('assigned_to')->count()" icon="bi-person-badge"
              :hint="$items->whereNull('assigned_to')->count() > 0 ? 'Decided in a meeting, owned by nobody yet' : 'Every item has an owner'" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('meetings.actionItems') }}">
    <div class="erp-filter">
        <label class="form-label" for="closed">Show</label>
        <select class="form-select" name="closed" id="closed" data-erp-autosubmit>
            <option value="">Open work</option>
            <option value="1" @selected($includeClosed)>Everything, including closed</option>
        </select>
    </div>
    <div class="erp-filter-note">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        {{ $seesEverybody
            ? 'You hold tasks.view_all, so this is the whole office’s action items.'
            : 'This is your own action items — the ones assigned to you. The office’s need tasks.view_all.' }}
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
    </div>
</form>

@forelse ($grouped as $meetingId => $tasks)
    @php($meeting = $tasks->first()->meeting)
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">
                    @if ($meeting)
                        <a href="{{ route('meetings.show', $meeting) }}">{{ $meeting->title }}</a>
                    @else
                        Meeting since removed
                    @endif
                </h2>
                <p class="erp-card-sub">
                    @if ($meeting)
                        {{ $meeting->starts_at->format('d M Y') }}
                        · {{ $meeting->presentCount() }} present
                        · <a href="{{ route('meetings.show', $meeting) }}#minutes">minutes</a>
                    @else
                        The meeting this came from is no longer on file; the task remains.
                    @endif
                </p>
            </div>
            <div class="erp-card-actions">
                <span class="erp-chip {{ $tasks->contains(fn ($task) => $task->isOverdue()) ? 'erp-chip-danger' : 'erp-chip-outline' }}">
                    {{ $tasks->count() }} item(s)
                </span>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="table erp-table erp-table-compact">
                <thead>
                    <tr>
                        <th>What was decided</th>
                        <th>Owner</th>
                        <th>Due</th>
                        <th>State</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tasks as $task)
                        <tr>
                            <td data-label="What was decided">
                                <a class="erp-cell-strong" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                @if ($task->description)
                                    <div class="erp-td-muted">{{ \Illuminate\Support\Str::limit($task->description, 100) }}</div>
                                @endif
                            </td>
                            <td data-label="Owner" class="erp-td-muted">{{ $task->assignee?->name ?? 'Nobody yet' }}</td>
                            <td data-label="Due" class="erp-td-muted">
                                {{ $task->due_at?->format('d M Y H:i') ?? '—' }}
                                @if ($task->isOverdue())
                                    <div><span class="erp-chip erp-chip-danger">overdue</span></div>
                                @endif
                            </td>
                            <td data-label="State"><x-ui.status :value="$task->status" :label="$task->statusLabel()" /></td>
                            <td class="erp-td-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('tasks.show', $task) }}">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@empty
    <section class="erp-card">
        <x-ui.empty
            title="No action items to show"
            text="Nothing has been raised out of a meeting yet — or everything that was raised has been closed. Both are good news; the button below is how the first one changes."
            icon="bi-list-check"
            :action="$canManage ? 'Go to the diary' : null"
            :href="$canManage ? route('meetings.index') : null" />
    </section>
@endforelse

<x-ui.related-pages />
