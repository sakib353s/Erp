<x-ui.page-header
    :eyebrow="'Business Management · Projects · '.$project->code"
    :title="$project->name"
    :subtitle="$project->description ?: 'No description — the tasks say what it is.'"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('projects.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Projects
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('tasks.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New task
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="row g-3">
    <div class="col-lg-8">
        <x-ui.table-shell title="Its tasks" :count="$tasks->count().' task(s)'">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Assignee</th>
                    <th>Priority</th>
                    <th>Due</th>
                    <th>State</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tasks as $task)
                    <tr>
                        <td><a class="erp-cell-strong" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a></td>
                        <td class="erp-td-muted">{{ $task->assignee?->name ?? 'unassigned' }}</td>
                        <td><span class="erp-chip {{ $task->priority === 'urgent' ? 'erp-chip-danger' : ($task->priority === 'high' ? 'erp-chip-warn' : 'erp-chip-soft') }}">{{ $task->priorityLabel() }}</span></td>
                        <td class="erp-td-muted">
                            {{ optional($task->due_at)->format('d M Y') ?? '—' }}
                            @if ($task->isOverdue())<div><span class="erp-chip erp-chip-danger">overdue</span></div>@endif
                        </td>
                        <td><x-ui.status :value="$task->status" :label="$task->statusLabel()" /></td>
                        <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="{{ route('tasks.show', $task) }}">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-ui.empty icon="bi-list-task" title="Nothing in this project yet"
                                text="Tasks created from the board can be pointed at this project, and every one of them shows up here." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table-shell>
    </div>

    <div class="col-lg-4">
        <section class="erp-card">
            <header class="erp-card-head"><h2 class="erp-card-title">The project</h2></header>
            <dl class="erp-dl erp-dl-tight">
                <dt>Code</dt>
                <dd>{{ $project->code }}</dd>
                <dt>Owner</dt>
                <dd>{{ $project->owner?->name ?? 'nobody yet' }}</dd>
                <dt>State</dt>
                <dd>{{ $project->statusLabel() }}</dd>
                <dt>Starts</dt>
                <dd>{{ optional($project->starts_on)->format('d M Y') ?? 'not set' }}</dd>
                <dt>Due</dt>
                <dd>{{ optional($project->due_on)->format('d M Y') ?? 'open' }}</dd>
                <dt>Tasks</dt>
                <dd>{{ $tasks->count() }} · {{ $tasks->whereIn('status', ['done', 'cancelled'])->count() }} closed</dd>
            </dl>
        </section>

        @if ($canManage)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Keep it current</h2>
                        <p class="erp-card-sub">Closing a project does not close its tasks — finish them, or cancel them deliberately.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('projects.update', $project) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" type="text" name="name" id="name" value="{{ $project->name }}" maxlength="191" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="status">State</label>
                        <select class="form-select" name="status" id="status">
                            @foreach ($statuses as $key => $label)
                                <option value="{{ $key }}" @selected($project->status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="due_on">Due</label>
                        <input class="form-control" type="date" name="due_on" id="due_on" value="{{ optional($project->due_on)->format('Y-m-d') }}">
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">Save</button>
                </form>
            </section>
        @endif
    </div>
</div>
