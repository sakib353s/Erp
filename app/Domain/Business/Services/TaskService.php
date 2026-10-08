<?php

namespace App\Domain\Business\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\Project;
use App\Domain\Business\Task;
use App\Domain\Business\TaskComment;
use App\Domain\Business\TaskEvent;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §12-13 — tasks and projects, and the rules a board needs to be worth reading.
 *
 *  · **A task has a state machine.** TODO → in progress → review → done, with
 *    blocked as a real place to be and cancelled as a real way to stop. The map
 *    lives on the model ({@see Task::TRANSITIONS}) and this service is the only
 *    thing that consults it: a board that lets any card go anywhere is a board
 *    nobody maintains, because the columns stop meaning anything. Done may be
 *    reopened (work does come back); cancelled is final.
 *  · **A move leaves a trail.** Every assignment, status change and due-date
 *    change writes a `task_events` row *and* an audit event. The audit chain says
 *    something changed; the event log says what the workflow did — who moved it
 *    out of blocked, and the note they left.
 *  · **“My tasks” is a query, not a filter in a view.** The scope is
 *    `assigned_to = me`, and “all tasks” is a permission (`tasks.view_all`), so a
 *    member of staff with only `tasks.view_own` never loads rows they may not see
 *    — the screen does not hide them, the query does not return them.
 *  · **Overdue is about the clock, not a stored flag.** It is computed on read,
 *    so a task that became overdue five minutes ago is overdue, and no nightly
 *    job can be the reason a board lied.
 *
 * Completing a task sets `completed_at` (kept when it is reopened, so the history
 * of “done twice” stays visible) and notifies the assignee on assignment, never
 * on save.
 */
class TaskService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected NotificationCenter $notifications,
    ) {}

    /* -------------------------------------------------------------- writing */

    /**
     * @param  array{title: string, description?: ?string, project_id?: ?int, priority?: ?string, assigned_to?: ?int, branch_id?: ?int, due_at?: ?string}  $data
     */
    public function create(array $data, User $actor): Task
    {
        $companyId = (int) $this->companyId();

        $task = Task::create([
            'company_id' => $companyId,
            'project_id' => $data['project_id'] ?? null,
            // §12-11: an action item from a meeting arrives as a task with the
            // meeting it came out of, so the minutes page and the board show the
            // same piece of work rather than two copies of it.
            'meeting_id' => $data['meeting_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => Task::STATUS_TODO,
            'priority' => $data['priority'] ?? 'normal',
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $actor->id,
            'branch_id' => $data['branch_id'] ?? $actor->default_branch_id,
            'due_at' => $data['due_at'] ?? null,
            'position' => (int) Task::query()->where('company_id', $companyId)->max('position') + 1,
        ]);

        $this->event($task, TaskEvent::CREATED, $actor, note: null, to: Task::STATUS_TODO);

        $this->audit->record([
            'action' => 'business.task_created',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'actor_id' => $actor->id,
            'after' => [
                'title' => $task->title,
                'priority' => $task->priority,
                'assigned_to' => $task->assigned_to,
                'due_at' => $task->due_at?->toDateTimeString(),
                'meeting_id' => $task->meeting_id,
            ],
        ]);

        if ($task->assigned_to !== null && $task->assignee !== null) {
            $this->tell($task, $task->assignee, 'task.assigned', 'Assigned to you');
        }

        return $task;
    }

    /** Hand a task to somebody (or to nobody, which is also a decision worth logging). */
    public function assign(Task $task, ?User $assignee, User $actor): Task
    {
        $this->guardCompany($task);

        $before = $task->assigned_to;

        if ($before === $assignee?->id) {
            return $task;
        }

        $task->forceFill(['assigned_to' => $assignee?->id])->save();

        $this->event($task, TaskEvent::ASSIGNED, $actor, note: $assignee?->name ?? 'nobody');

        $this->audit->record([
            'action' => 'business.task_assigned',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'actor_id' => $actor->id,
            'before' => ['assigned_to' => $before],
            'after' => ['assigned_to' => $assignee?->id],
        ]);

        if ($assignee !== null) {
            $this->tell($task, $assignee, 'task.assigned', 'Assigned to you');
        }

        return $task->refresh();
    }

    /**
     * Move a task. The only door: an illegal move is refused with the statuses it
     * could have gone to, so the screen can offer them rather than guess.
     */
    public function transition(Task $task, string $to, User $actor, ?string $note = null): Task
    {
        $this->guardCompany($task);

        $from = $task->status;

        if (! in_array($to, Task::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'A task that is “%s” cannot move to “%s”. It can go to: %s.',
                    Task::STATUSES[$from] ?? $from,
                    Task::STATUSES[$to] ?? $to,
                    implode(', ', array_map(fn (string $s): string => Task::STATUSES[$s] ?? $s, $task->allowedTransitions())) ?: 'nowhere — it is closed.',
                ),
            ]);
        }

        $task->forceFill([
            'status' => $to,
            'completed_at' => $to === Task::STATUS_DONE ? now() : $task->completed_at,
        ])->save();

        $this->event($task, TaskEvent::STATUS_CHANGED, $actor, note: $note, from: $from, to: $to);

        $this->audit->record([
            'action' => 'business.task_moved',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'actor_id' => $actor->id,
            'before' => ['status' => $from],
            'after' => ['status' => $to, 'note' => $note],
            'reason' => $note,
        ]);

        // The person waiting on the work hears about it, not the person who did it.
        if ($task->assigned_to !== null && $task->assigned_to !== $actor->id && $task->assignee !== null) {
            $this->tell($task, $task->assignee, 'task.moved', 'Moved to '.($task->statusLabel()));
        }

        return $task->refresh();
    }

    public function reschedule(Task $task, ?string $dueAt, User $actor): Task
    {
        $this->guardCompany($task);

        $before = $task->due_at?->toDateTimeString();

        $task->forceFill(['due_at' => $dueAt])->save();

        $this->event($task, TaskEvent::DUE_CHANGED, $actor, note: $dueAt ?? 'no due date');

        $this->audit->record([
            'action' => 'business.task_rescheduled',
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'actor_id' => $actor->id,
            'before' => ['due_at' => $before],
            'after' => ['due_at' => $task->due_at?->toDateTimeString()],
        ]);

        return $task->refresh();
    }

    public function comment(Task $task, User $author, string $body): TaskComment
    {
        $this->guardCompany($task);

        $comment = TaskComment::create([
            'company_id' => $task->company_id,
            'task_id' => $task->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $this->event($task, TaskEvent::COMMENTED, $author, note: Str::limit($body, 200));

        // A comment is the cheapest way to unblock somebody, so the other party
        // is told about it — once per comment, and never to its own author.
        foreach (array_filter([$task->assigned_to, $task->created_by]) as $userId) {
            if ((int) $userId === $author->id) {
                continue;
            }

            $person = User::query()->find($userId);

            if ($person !== null) {
                $this->tell($task, $person, 'task.commented', $author->name.' commented', $comment->id);
            }
        }

        return $comment;
    }

    /* -------------------------------------------------------------- reading */

    /** The board: one column per status, in board order, from the database. */
    public function board(int $companyId, array $filters = []): array
    {
        $tasks = $this->query($companyId, $filters)->get();

        $columns = [];

        foreach (Task::BOARD_ORDER as $status) {
            $columns[$status] = $tasks->where('status', $status)->values();
        }

        return $columns;
    }

    /**
     * A person's own work, most urgent first: overdue, then high priority, then
     * due soonest.
     *
     * @return Collection<int, Task>
     */
    public function mine(int $companyId, int $userId, bool $includeClosed = false): Collection
    {
        $tasks = $this->query($companyId, ['assigned_to' => $userId, 'include_closed' => $includeClosed])->get();

        return $this->inWorkOrder($tasks);
    }

    /**
     * Everybody's work — only ever called behind `tasks.view_all`.
     *
     * @return Collection<int, Task>
     */
    public function all(int $companyId, array $filters = [], bool $includeClosed = false): Collection
    {
        $tasks = $this->query($companyId, $filters + ['include_closed' => $includeClosed])->get();

        return $this->inWorkOrder($tasks);
    }

    /**
     * The numbers the task screens show. Computed from the same queries the
     * lists use, so a card and the list behind it agree.
     *
     * @return array{mine_open: int, mine_overdue: int, all_open: int, all_overdue: int, completed_this_week: int, by_status: array<string, int>, by_priority: array<string, int>}
     */
    public function summary(int $companyId, User $person, bool $seesEverybody): array
    {
        $mine = Task::query()->where('company_id', $companyId)->where('assigned_to', $person->id);
        $all = Task::query()->where('company_id', $companyId);

        $byStatus = (clone $all)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $byPriority = (clone $mine)->open()->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority');

        return [
            'mine_open' => (clone $mine)->open()->count(),
            'mine_overdue' => (clone $mine)->overdue()->count(),
            'all_open' => $seesEverybody ? (clone $all)->open()->count() : 0,
            'all_overdue' => $seesEverybody ? (clone $all)->overdue()->count() : 0,
            'completed_this_week' => (clone $all)->where('status', Task::STATUS_DONE)
                ->where('completed_at', '>=', now()->startOfWeek())->count(),
            'by_status' => array_map('intval', $byStatus->all()),
            'by_priority' => array_map('intval', $byPriority->all()),
        ];
    }

    /** Projects with their open/total task counts, for the projects screen. */
    public function projects(int $companyId): Collection
    {
        return Project::query()
            ->where('company_id', $companyId)
            ->withCount(['tasks', 'tasks as open_tasks_count' => fn ($q) => $q->open()])
            ->orderByRaw("status = 'active' DESC")
            ->orderBy('due_on')
            ->get();
    }

    /* ------------------------------------------------------------- internals */

    protected function query(int $companyId, array $filters)
    {
        $query = Task::query()->where('company_id', $companyId)->with(['assignee', 'project']);

        foreach (['status', 'priority', 'project_id', 'assigned_to', 'branch_id'] as $field) {
            $value = $filters[$field] ?? null;

            if ($value !== null && $value !== '' && $value !== 'all') {
                $query->where($field, $value);
            }
        }

        if (($filters['only_overdue'] ?? false) === true) {
            $query->overdue();
        }

        if (($filters['include_closed'] ?? false) !== true) {
            $query->open();
        }

        if (($filters['search'] ?? '') !== '') {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
        }

        return $query->orderBy('position')->orderBy('due_at');
    }

    /** @param  Collection<int, Task>  $tasks */
    protected function inWorkOrder(Collection $tasks): Collection
    {
        $priority = array_flip(['urgent', 'high', 'normal', 'low']);

        return $tasks->sortBy([
            fn (Task $task) => $task->isOverdue() ? 0 : 1,
            fn (Task $task) => $priority[$task->priority] ?? 9,
            fn (Task $task) => $task->due_at?->timestamp ?? PHP_INT_MAX,
        ])->values();
    }

    protected function event(Task $task, string $event, User $actor, ?string $note = null, ?string $from = null, ?string $to = null): TaskEvent
    {
        return TaskEvent::create([
            'company_id' => $task->company_id,
            'task_id' => $task->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $actor->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    protected function tell(Task $task, User $person, string $eventType, string $title, ?int $commentId = null): void
    {
        $this->notifications->notify(
            $person,
            $eventType,
            $title.' — '.$task->title,
            $task->project?->name,
            [
                'priority' => $task->priority === 'urgent' ? 'high' : 'normal',
                'action_url' => route('tasks.show', $task, false),
                'data' => ['task_id' => $task->id, 'status' => $task->status],
                // One notification per event per task per person: a re-save is
                // not a second message.
                'dedupe_key' => 'task:'.$task->id.':'.$eventType.':'.$person->id.($commentId !== null ? ':'.$commentId : ''),
            ],
        );
    }

    protected function guardCompany(Task $task): void
    {
        abort_if((int) $task->company_id !== (int) $this->companyId(), 404);
    }

    protected function companyId(): int
    {
        $companyId = $this->context->companyId();

        abort_if($companyId === null, 500, 'Company context missing.');

        return (int) $companyId;
    }
}
