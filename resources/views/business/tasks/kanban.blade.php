<x-ui.page-header
    eyebrow="Business Management · Tasks"
    title="The board"
    subtitle="One column per state, drawn from the task state machine itself — todo, in progress, blocked, in review, done — so a column cannot appear that no task may be in. A card only offers the moves that state allows: done can be reopened, cancelled cannot."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">
            <i class="bi bi-list-task" aria-hidden="true"></i> My tasks
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('tasks.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New task
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

@if ($scopedToMe)
    <p class="erp-filter-note">
        <i class="bi bi-eye" aria-hidden="true"></i>
        This board shows <strong>your</strong> cards. Seeing everybody's needs <span class="font-monospace">tasks.view_all</span>.
    </p>
@endif

<div class="row g-3">
    @foreach ($statuses as $key => $label)
        @continue (! isset($columns[$key]))
        <div class="col-xl col-lg-4 col-md-6">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">{{ $label }}</h2>
                        <p class="erp-card-sub">{{ $columns[$key]->count() }} card(s)</p>
                    </div>
                    <div class="erp-card-actions">
                        <x-ui.status :value="$key" :label="$label" />
                    </div>
                </header>
                <div class="p-2">
                    @forelse ($columns[$key] as $task)
                        <article class="erp-list-row erp-list-row-top">
                            <div class="erp-list-row-main">
                                <a class="erp-cell-strong" href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                                <div class="erp-td-muted">
                                    {{ $task->assignee?->name ?? 'unassigned' }}
                                    @if ($task->project) · {{ $task->project->name }} @endif
                                </div>
                                <div class="mt-1 d-flex gap-1 flex-wrap">
                                    <span class="erp-chip {{ $task->priority === 'urgent' ? 'erp-chip-danger' : ($task->priority === 'high' ? 'erp-chip-warn' : 'erp-chip-soft') }}">{{ $task->priorityLabel() }}</span>
                                    @if ($task->due_at)
                                        <span class="erp-chip {{ $task->isOverdue() ? 'erp-chip-danger' : 'erp-chip-outline' }}">
                                            {{ $task->due_at->format('d M') }}{{ $task->isOverdue() ? ' · overdue' : '' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-end">
                                @foreach (array_slice($task->allowedTransitions(), 0, 2) as $to)
                                    <form method="POST" action="{{ route('tasks.transition', $task) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ $to }}">
                                        <button class="btn btn-outline-secondary btn-sm" type="submit" title="Move to {{ \App\Domain\Business\Task::STATUSES[$to] ?? $to }}">
                                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endforeach
                                @if ($task->allowedTransitions() === [])
                                    <span class="erp-td-muted">closed</span>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="erp-td-muted p-2 mb-0">Nothing here.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endforeach
</div>
