<?php

namespace App\Http\Controllers;

use App\Domain\Business\Project;
use App\Domain\Business\Services\TaskService;
use App\Domain\Business\Task;
use App\Domain\Foundation\User;
use App\Http\Requests\StoreTaskRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §12-13 — tasks, from three angles: what is mine, what is everybody's, and the
 * board.
 *
 * The scope is decided by the query, not by the view: “My tasks” asks for
 * `assigned_to = me`, “All tasks” is behind `tasks.view_all`, and the kanban
 * shows the whole company's cards only to somebody who may see them — otherwise
 * it shows their own, because a board that shows nothing is not a board.
 *
 * Moving a card and commenting on it are the two things an *assignee* must be
 * able to do without being a manager: the state machine decides what is legal
 * ({@see TaskService::transition()}), and `mayWorkOn()` decides who may try.
 */
class TaskController extends Controller
{
    public function __construct(protected TaskService $tasks) {}

    /** My tasks: overdue first, then by priority, then by due date. */
    public function index(Request $request): View
    {
        $person = $request->user();
        $companyId = (int) $person->company_id;

        return view('business.tasks.index', [
            'tasks' => $this->tasks->mine($companyId, $person->id, includeClosed: $request->boolean('closed')),
            'summary' => $this->tasks->summary($companyId, $person, $this->seesEverybody($person)),
            'includeClosed' => $request->boolean('closed'),
            'seesEverybody' => $this->seesEverybody($person),
            'canManage' => (bool) $person->can('tasks.manage'),
        ]);
    }

    /** Everybody's tasks — the register, with filters. */
    public function all(Request $request): View
    {
        $person = $request->user();
        $companyId = (int) $person->company_id;

        return view('business.tasks.all', [
            'tasks' => $this->tasks->all($companyId, $this->filters($request), includeClosed: $request->boolean('closed')),
            'summary' => $this->tasks->summary($companyId, $person, true),
            'filters' => $this->filters($request),
            'includeClosed' => $request->boolean('closed'),
            'projects' => Project::query()->where('company_id', $companyId)->orderBy('name')->get(),
            'people' => User::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'canManage' => (bool) $person->can('tasks.manage'),
        ]);
    }

    /** The board: one column per status, from the database. */
    public function kanban(Request $request): View
    {
        $person = $request->user();
        $companyId = (int) $person->company_id;

        $filters = $this->filters($request);

        if (! $this->seesEverybody($person)) {
            $filters['assigned_to'] = $person->id;
        }

        return view('business.tasks.kanban', [
            'columns' => $this->tasks->board($companyId, $filters),
            'summary' => $this->tasks->summary($companyId, $person, $this->seesEverybody($person)),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'filters' => $filters,
            'people' => $this->seesEverybody($person)
                ? User::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get()
                : collect(),
            'projects' => Project::query()->where('company_id', $companyId)->orderBy('name')->get(),
            'scopedToMe' => ! $this->seesEverybody($person),
            'canManage' => (bool) $person->can('tasks.manage'),
        ]);
    }

    public function create(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        return view('business.tasks.create', [
            'projects' => Project::query()->where('company_id', $companyId)->where('status', '!=', 'completed')->orderBy('name')->get(),
            'people' => User::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(),
            'priorities' => Task::PRIORITIES,
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $task = $this->tasks->create($request->validated(), $request->user());

        return redirect()->route('tasks.show', $task)->with('status', 'Task created.');
    }

    public function show(Request $request, Task $task): View
    {
        $this->guardVisible($request, $task);

        return view('business.tasks.show', [
            'task' => $task->load(['assignee', 'creator', 'project', 'comments.user', 'events.user']),
            'mayWork' => $this->mayWorkOn($request->user(), $task),
            'canManage' => (bool) $request->user()->can('tasks.manage'),
            'people' => $request->user()->can('tasks.manage')
                ? User::query()->where('company_id', $task->company_id)->where('status', 'active')->orderBy('name')->get()
                : collect(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ]);
    }

    /** Change what the task says — a manager's edit, not the workflow. */
    public function update(StoreTaskRequest $request, Task $task): RedirectResponse
    {
        $data = $request->validated();

        $task->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? $task->priority,
            'project_id' => $data['project_id'] ?? null,
        ])->save();

        if (($data['due_at'] ?? null) !== $task->due_at?->toDateTimeString()) {
            $this->tasks->reschedule($task, $data['due_at'] ?? null, $request->user());
        }

        if (array_key_exists('assigned_to', $data)) {
            $this->tasks->assign($task, $data['assigned_to'] === null ? null : User::find($data['assigned_to']), $request->user());
        }

        return redirect()->route('tasks.show', $task)->with('status', 'Task updated.');
    }

    public function assign(Request $request, Task $task): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tasks->assign($task, $validated['assigned_to'] === null ? null : User::find($validated['assigned_to']), $request->user());

        return back()->with('status', $validated['assigned_to'] === null ? 'Task unassigned.' : 'Task assigned.');
    }

    public function transition(Request $request, Task $task): RedirectResponse
    {
        $this->guardVisible($request, $task);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Task::STATUSES))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $this->mayWorkOn($request->user(), $task)) {
            abort(403, 'Only the assignee or somebody with tasks.manage may move this task.');
        }

        $this->tasks->transition($task, $validated['status'], $request->user(), $validated['note'] ?? null);

        return back()->with('status', 'Moved to '.($task->fresh()->statusLabel()).'.');
    }

    public function reschedule(Request $request, Task $task): RedirectResponse
    {
        abort_unless($request->user()->can('tasks.manage'), 403, 'Changing a due date needs tasks.manage.');

        $validated = $request->validate(['due_at' => ['nullable', 'date']]);

        $this->tasks->reschedule($task, $validated['due_at'] ?? null, $request->user());

        return back()->with('status', 'Due date updated.');
    }

    public function comment(Request $request, Task $task): RedirectResponse
    {
        $this->guardVisible($request, $task);

        if (! $this->mayWorkOn($request->user(), $task)) {
            abort(403, 'Only the assignee or somebody with tasks.manage may comment on this task.');
        }

        $validated = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $this->tasks->comment($task, $request->user(), $validated['body']);

        return back()->with('status', 'Comment added.');
    }

    /* ------------------------------------------------------------- internals */

    protected function filters(Request $request): array
    {
        return [
            'status' => $request->string('status')->toString() ?: null,
            'priority' => $request->string('priority')->toString() ?: null,
            'project_id' => $request->integer('project_id') ?: null,
            'assigned_to' => $request->integer('assigned_to') ?: null,
            'only_overdue' => $request->boolean('overdue'),
            'search' => trim($request->string('q')->toString()),
        ];
    }

    protected function seesEverybody(User $person): bool
    {
        return $person->isSuperAdmin() || $person->can('tasks.view_all');
    }

    protected function mayWorkOn(User $person, Task $task): bool
    {
        return $task->assigned_to === $person->id
            || $task->created_by === $person->id
            || $person->can('tasks.manage');
    }

    protected function guardVisible(Request $request, Task $task): void
    {
        abort_if((int) $task->company_id !== (int) $request->user()->company_id, 404);

        if ($this->seesEverybody($request->user())) {
            return;
        }

        abort_unless(
            in_array($request->user()->id, [$task->assigned_to, $task->created_by], true),
            403,
            'This task is somebody else’s: seeing everybody’s work needs tasks.view_all.',
        );
    }
}
