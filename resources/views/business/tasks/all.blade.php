<x-ui.page-header
    eyebrow="Business Management · Tasks"
    title="Everybody's work"
    subtitle="The whole company's tasks in one place, filterable. This screen needs tasks.view_all: without it a person sees their own work — the query never returns the rest, so there is nothing on the page to hide.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> My tasks
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">
            <i class="bi bi-kanban" aria-hidden="true"></i> Board
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('tasks.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New task
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<form class="erp-filterbar" method="GET" action="{{ route('tasks.all') }}">
    <div class="erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $filters['search'] ?? '' }}" placeholder="Title or description">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">State</label>
        <select class="form-select" name="status" id="status">
            <option value="">Any state</option>
            @foreach ($statuses as $key => $label)
                <option value="{{ $key }}" @selected(($filters['status'] ?? null) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="priority">Priority</label>
        <select class="form-select" name="priority" id="priority">
            <option value="">Any priority</option>
            @foreach ($priorities as $key => $label)
                <option value="{{ $key }}" @selected(($filters['priority'] ?? null) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="assigned_to">Assignee</label>
        <select class="form-select" name="assigned_to" id="assigned_to">
            <option value="">Anybody</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}" @selected((int) ($filters['assigned_to'] ?? 0) === $person->id)>{{ $person->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="project_id">Project</label>
        <select class="form-select" name="project_id" id="project_id">
            <option value="">Any project</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected((int) ($filters['project_id'] ?? 0) === $project->id)>{{ $project->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="overdue" id="overdue" value="1" @checked($filters['only_overdue'] ?? false)>
            <label class="form-check-label" for="overdue">Only overdue</label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="closed" id="closed" value="1" @checked($includeClosed)>
            <label class="form-check-label" for="closed">Include closed</label>
        </div>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
    </div>
</form>

<x-ui.table-shell title="Tasks" :count="$tasks->count().' task(s)'">
    <thead>
        <tr>
            <th>Task</th>
            <th>Assignee</th>
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
                </td>
                <td class="erp-td-muted">{{ $task->assignee?->name ?? 'unassigned' }}</td>
                <td class="erp-td-muted">
                    @if ($task->project)
                        <a href="{{ route('projects.show', $task->project) }}">{{ $task->project->name }}</a>
                    @else
                        —
                    @endif
                </td>
                <td><span class="erp-chip {{ $task->priority === 'urgent' ? 'erp-chip-danger' : ($task->priority === 'high' ? 'erp-chip-warn' : 'erp-chip-soft') }}">{{ $task->priorityLabel() }}</span></td>
                <td class="erp-td-muted">
                    {{ optional($task->due_at)->format('d M Y') ?? '—' }}
                    @if ($task->isOverdue())
                        <div><span class="erp-chip erp-chip-danger">overdue</span></div>
                    @endif
                </td>
                <td><x-ui.status :value="$task->status" :label="$task->statusLabel()" /></td>
                <td class="text-end">
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('tasks.show', $task) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty icon="bi-list-task" title="No task matches those filters"
                                text="Clear a filter or two. A person with tasks.manage can create work for anybody from this screen's New task button." />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>
