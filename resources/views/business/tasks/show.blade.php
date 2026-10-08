<x-ui.page-header
    :eyebrow="'Business Management · Tasks · '.$task->statusLabel()"
    :title="$task->title"
    :subtitle="$task->project?->name ? 'Project: '.$task->project->name : 'No project — this task stands on its own'"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">
            <i class="bi bi-kanban" aria-hidden="true"></i> Board
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">
            <i class="bi bi-list-task" aria-hidden="true"></i> My tasks
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="row g-3">
    <div class="col-lg-8">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Where it stands</h2>
                    <p class="erp-card-sub">
                        Assigned to {{ $task->assignee?->name ?? 'nobody' }}
                        · created by {{ $task->creator?->name ?? 'the system' }}
                        · priority {{ $task->priorityLabel() }}
                    </p>
                </div>
                <div class="erp-card-actions">
                    <x-ui.status :value="$task->status" :label="$task->statusLabel()" />
                    @if ($task->isOverdue())
                        <span class="erp-chip erp-chip-danger">overdue</span>
                    @endif
                </div>
            </header>

            @if ($task->description)
                <div class="p-3 pt-0">
                    {!! nl2br(e($task->description)) !!}
                </div>
            @endif

            <div class="p-3 pt-0">
                @if ($mayWork && $task->allowedTransitions() !== [])
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="erp-td-muted">Move it to:</span>
                        @foreach ($task->allowedTransitions() as $to)
                            <form method="POST" action="{{ route('tasks.transition', $task) }}" class="d-flex gap-1">
                                @csrf
                                <input type="hidden" name="status" value="{{ $to }}">
                                <input class="form-control form-control-sm" type="text" name="note" maxlength="255"
                                       placeholder="why (optional)" style="max-width:180px">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">
                                    {{ \App\Domain\Business\Task::STATUSES[$to] ?? $to }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                @elseif ($mayWork)
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        Cancelled tasks are closed for good. Nothing further can be moved here — the record is the record.
                    </p>
                @else
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                        Only the assignee, the person who created the task, or somebody with
                        <span class="font-monospace">tasks.manage</span> may move it.
                    </p>
                @endif
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The conversation</h2>
                    <p class="erp-card-sub">Comments are the cheapest way to unblock somebody, so the other party is told about each one — once.</p>
                </div>
                <div class="erp-card-actions"><span class="erp-chip erp-chip-soft">{{ $task->comments->count() }}</span></div>
            </header>
            <div class="p-3 pt-0">
                @forelse ($task->comments as $comment)
                    <div class="erp-comment mb-2">
                        <div class="d-flex justify-content-between">
                            <span class="erp-cell-strong">{{ $comment->user?->name ?? 'a person who left' }}</span>
                            <span class="erp-td-muted">{{ $comment->created_at?->format('d M Y, H:i') }}</span>
                        </div>
                        <div>{!! nl2br(e($comment->body)) !!}</div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-2">No comments yet.</p>
                @endforelse

                @if ($mayWork)
                    <form method="POST" action="{{ route('tasks.comment', $task) }}" class="mt-2">
                        @csrf
                        <label class="form-label" for="body">Add a comment</label>
                        <textarea class="form-control" name="body" id="body" rows="3" maxlength="4000" required></textarea>
                        <button class="btn btn-primary btn-sm mt-2" type="submit"><i class="bi bi-chat-left-text" aria-hidden="true"></i> Comment</button>
                    </form>
                @endif
            </div>
        </section>

        <div class="mt-3">
            <x-ui.related-pages />
        </div>
    </div>

    <div class="col-lg-4">
        <section class="erp-card">
            <header class="erp-card-head"><h2 class="erp-card-title">Its facts</h2></header>
            <dl class="erp-dl erp-dl-tight">
                <dt>State</dt>
                <dd>{{ $task->statusLabel() }}</dd>
                <dt>Priority</dt>
                <dd>{{ $task->priorityLabel() }}</dd>
                <dt>Assignee</dt>
                <dd>{{ $task->assignee?->name ?? 'nobody' }}</dd>
                <dt>Due</dt>
                <dd>{{ optional($task->due_at)->format('d M Y, H:i') ?? 'no date' }}</dd>
                <dt>Completed</dt>
                <dd>{{ optional($task->completed_at)->format('d M Y, H:i') ?? 'not yet' }}</dd>
                <dt>Project</dt>
                <dd>
                    @if ($task->project)
                        <a href="{{ route('projects.show', $task->project) }}">{{ $task->project->name }}</a>
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </section>

        @if ($canManage)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Edit the task</h2>
                        <p class="erp-card-sub">What it says, and how urgent it is. Moving it through its states is a separate act, on the task's own bar.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('tasks.update', $task) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" for="edit_title">Title</label>
                        <input class="form-control" type="text" name="title" id="edit_title" value="{{ $task->title }}" maxlength="191" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_description">What needs doing</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="4">{{ $task->description }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_priority">Priority</label>
                        <select class="form-select" name="priority" id="edit_priority">
                            @foreach ($priorities as $key => $label)
                                <option value="{{ $key }}" @selected($task->priority === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">Save changes</button>
                </form>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Hand it over</h2>
                        <p class="erp-card-sub">Assignment and due dates are a manager's act; the move itself is the assignee's.</p>
                    </div>
                </header>
                <div class="p-3 pt-0">
                    <form method="POST" action="{{ route('tasks.assign', $task) }}" class="mb-3">
                        @csrf
                        <label class="form-label" for="assigned_to">Assignee</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" name="assigned_to" id="assigned_to">
                                <option value="">Nobody</option>
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}" @selected($task->assigned_to === $person->id)>{{ $person->name }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-secondary" type="submit">Assign</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('tasks.reschedule', $task) }}">
                        @csrf
                        <label class="form-label" for="due_at">Due</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" type="datetime-local" name="due_at" id="due_at"
                                   value="{{ optional($task->due_at)->format('Y-m-d\TH:i') }}">
                            <button class="btn btn-outline-secondary" type="submit">Set</button>
                        </div>
                        <div class="form-text">Empty clears the date, which is also recorded on the timeline.</div>
                    </form>
                </div>
            </section>
        @endif

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What happened</h2>
                    <p class="erp-card-sub">The workflow's own record — the audit chain separately says that the task changed.</p>
                </div>
            </header>
            <div class="p-3 pt-0">
                <div class="erp-list">
                    @foreach ($task->events as $event)
                        <div class="erp-list-row erp-list-row-top">
                            <div class="erp-list-row-main">
                                <span class="erp-cell-strong">{{ str_replace('_', ' ', ucfirst($event->event)) }}</span>
                                <div class="erp-td-muted">
                                    {{ $event->user?->name ?? 'the system' }}
                                    @if ($event->from_status && $event->to_status)
                                        · {{ \App\Domain\Business\Task::STATUSES[$event->from_status] ?? $event->from_status }}
                                        → {{ \App\Domain\Business\Task::STATUSES[$event->to_status] ?? $event->to_status }}
                                    @endif
                                    @if ($event->note) · {{ $event->note }} @endif
                                </div>
                            </div>
                            <span class="erp-td-muted">{{ $event->created_at?->format('d M, H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
</div>
