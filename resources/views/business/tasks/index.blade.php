<x-ui.page-header
    eyebrow="Business Management · Tasks"
    title="Your work, in the order it hurts"
    subtitle="Overdue first, then by priority, then by the soonest due date — computed when the page is read, so a task that became overdue five minutes ago is already at the top. Moving a card and commenting on it are yours to do; what a task may move to is a state machine, not a preference."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">
            <i class="bi bi-kanban" aria-hidden="true"></i> Board
        </a>
        @if ($seesEverybody)
            <a class="btn btn-outline-secondary" href="{{ route('tasks.all') }}">
                <i class="bi bi-people" aria-hidden="true"></i> Everybody's tasks
            </a>
        @endif
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('tasks.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New task
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Open, mine" :value="$summary['mine_open']" icon="bi-list-task" hint="Everything assigned to you that is not done or cancelled" />
    <x-ui.kpi label="Overdue, mine" :value="$summary['mine_overdue']" icon="bi-alarm"
              :hint="$summary['mine_overdue'] > 0 ? 'Past the due date and still open' : 'Nothing of yours is past its date'" />
    <x-ui.kpi label="Completed this week" :value="$summary['completed_this_week']" icon="bi-check2-circle"
              hint="Company-wide, marked done since Monday" />
    @if ($seesEverybody)
        <x-ui.kpi label="Open, everybody" :value="$summary['all_open']" icon="bi-people" hint="Across the company, including yours" />
    @endif
</div>

<form class="erp-filterbar" method="GET" action="{{ route('tasks.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="closed">Show</label>
        <select class="form-select" name="closed" id="closed" data-erp-autosubmit>
            <option value="">Open work</option>
            <option value="1" @selected($includeClosed)>Everything, including done</option>
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
    </div>
</form>

<x-ui.table-shell title="Assigned to you" :count="$tasks->count().' task(s)'">
    <thead>
        <tr>
            <th>Task</th>
            <th>Project</th>
            <th>Priority</th>
            <th>Due</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($tasks as $task)
            <tr>
                <td>
                    <a class="erp-cell-strong" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                    @if ($task->description)
                        <div class="erp-td-muted">{{ \Illuminate\Support\Str::limit($task->description, 90) }}</div>
                    @endif
                </td>
                <td class="erp-td-muted">
                    @if ($task->project)
                        <a href="{{ route('projects.show', $task->project) }}">{{ $task->project->name }}</a>
                    @else
                        —
                    @endif
                </td>
                <td><span class="erp-chip {{ $task->priority === 'urgent' ? 'erp-chip-danger' : ($task->priority === 'high' ? 'erp-chip-warn' : 'erp-chip-soft') }}">{{ $task->priorityLabel() }}</span></td>
                <td class="erp-td-muted">
                    {{ optional($task->due_at)->format('d M Y, H:i') ?? 'no date' }}
                    @if ($task->isOverdue())
                        <div><span class="erp-chip erp-chip-danger">overdue</span></div>
                    @endif
                </td>
                <td><x-ui.status :value="$task->status" :label="$task->statusLabel()" /></td>
                <td class="text-end">
                    <div class="d-flex gap-1 justify-content-end">
                        @foreach (array_slice($task->allowedTransitions(), 0, 1) as $to)
                            <form method="POST" action="{{ route('tasks.transition', $task) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $to }}">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">
                                    {{ \App\Domain\Business\Task::STATUSES[$to] ?? $to }}
                                </button>
                            </form>
                        @endforeach
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('tasks.show', $task) }}">Open</a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty icon="bi-check2-circle" title="Nothing is assigned to you"
                                text="When somebody assigns you work it appears here. Tasks you create for yourself are assigned to you in the same form." />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>
