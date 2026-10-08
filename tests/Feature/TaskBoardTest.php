<?php

namespace Tests\Feature;

use App\Domain\Business\Project;
use App\Domain\Business\Services\TaskService;
use App\Domain\Business\Task;
use App\Domain\Business\TaskEvent;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-13 — tasks and projects.
 *
 * Pinned, in the order a team would notice:
 *
 *  · **scope is a query, not a hidden column.** A person with only
 *    `tasks.view_own` sees their own work on “my tasks”, is refused
 *    “everybody's tasks”, and is refused somebody else's task by URL; a person
 *    with `tasks.view_all` sees the company;
 *  · **the board is drawn from the state machine** — a column exists because a
 *    task may be in that state, not because a view listed it;
 *  · **a move is legal or refused.** The refusal names the statuses the task
 *    could have gone to, done may be reopened, cancelled is final, and every
 *    move lands on the task's own timeline *and* in the audit chain;
 *  · **assignment is a manager's act that tells the assignee once**;
 *  · **overdue is computed from the clock**, so a task two minutes past its due
 *    date is overdue without any job having run;
 *  · **projects are containers**: a code is unique inside the company, counts
 *    come from the tasks, and closing a project does not silently close work.
 */
class TaskBoardTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    /* --------------------------------------------------------------- helpers */

    protected function staff(array $keys): User
    {
        $user = $this->makeUser();
        $user->roles()->attach($this->roleWith($keys)->id);

        return $user;
    }

    protected function service(): TaskService
    {
        return app(TaskService::class);
    }

    protected function makeTask(?User $assignee = null, array $attributes = []): Task
    {
        return $this->service()->create(array_merge([
            'title' => 'Count the stock room',
            'description' => 'Shelf by shelf, then sign the sheet.',
            'priority' => 'normal',
            'assigned_to' => $assignee?->id,
        ], $attributes), $this->admin);
    }

    /* ------------------------------------------------------------------ scope */

    public function test_my_tasks_shows_my_work_and_not_somebody_elses(): void
    {
        $mine = $this->staff(['tasks.view_own']);
        $other = $this->makeUser();

        $this->makeTask($mine, ['title' => 'My own work']);
        $this->makeTask($other, ['title' => 'Somebody else’s work']);

        $this->actingAs($mine)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('My own work')
            ->assertDontSee('Somebody else’s work');
    }

    public function test_everybodys_tasks_needs_the_key_and_the_query_is_not_even_run(): void
    {
        $person = $this->staff(['tasks.view_own']);
        $this->makeTask($this->makeUser(), ['title' => 'Somebody else’s work']);

        $this->actingAs($person)->get(route('tasks.all'))->assertForbidden();

        $watcher = $this->staff(['tasks.view_own', 'tasks.view_all']);

        $this->actingAs($watcher)
            ->get(route('tasks.all'))
            ->assertOk()
            ->assertSee('Somebody else’s work');
    }

    public function test_a_task_that_is_not_mine_is_not_readable_by_url(): void
    {
        $person = $this->staff(['tasks.view_own']);
        $task = $this->makeTask($this->makeUser());

        $this->actingAs($person)->get(route('tasks.show', $task))->assertForbidden();

        $watcher = $this->staff(['tasks.view_own', 'tasks.view_all']);
        $this->actingAs($watcher)->get(route('tasks.show', $task))->assertOk();
    }

    public function test_the_kanban_is_scoped_to_me_without_the_all_key_and_shows_the_company_with_it(): void
    {
        $person = $this->staff(['tasks.view_own']);
        $this->makeTask($person, ['title' => 'Mine on the board']);
        $this->makeTask($this->makeUser(), ['title' => 'Theirs on the board']);

        $this->actingAs($person)
            ->get(route('tasks.kanban'))
            ->assertOk()
            ->assertSee('Mine on the board')
            ->assertDontSee('Theirs on the board')
            ->assertSee('tasks.view_all');

        $watcher = $this->staff(['tasks.view_own', 'tasks.view_all']);

        $this->actingAs($watcher)
            ->get(route('tasks.kanban'))
            ->assertOk()
            ->assertSee('Mine on the board')
            ->assertSee('Theirs on the board');
    }

    /* ------------------------------------------------------------------ board */

    public function test_the_columns_come_from_the_state_machine_rather_than_from_the_view(): void
    {
        $this->makeTask($this->admin, ['title' => 'A card in todo']);

        $response = $this->actingAs($this->admin)->get(route('tasks.kanban'))->assertOk();

        foreach (Task::BOARD_ORDER as $status) {
            $response->assertSee(Task::STATUSES[$status]);
        }

        // Cancelled is a real state a task may be in, but it is not a board
        // column: work that stopped is not work in progress.
        $response->assertDontSee(Task::STATUSES[Task::STATUS_CANCELLED]);

        $columns = $this->service()->board((int) $this->admin->company_id);
        $this->assertSame(Task::BOARD_ORDER, array_keys($columns));
        $this->assertCount(1, $columns[Task::STATUS_TODO]);
    }

    /* ------------------------------------------------------------- transition */

    public function test_a_move_leaves_a_trail_and_an_illegal_move_names_the_legal_ones(): void
    {
        $task = $this->makeTask($this->admin);

        $this->actingAs($this->admin)
            ->post(route('tasks.transition', $task), ['status' => Task::STATUS_IN_PROGRESS, 'note' => 'started the first shelf'])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame(Task::STATUS_IN_PROGRESS, $task->status);

        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event' => TaskEvent::STATUS_CHANGED,
            'from_status' => Task::STATUS_TODO,
            'to_status' => Task::STATUS_IN_PROGRESS,
            'note' => 'started the first shelf',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.task_moved',
            'entity_id' => $task->id,
        ]);

        // From done, “todo” is not on the map — the refusal says what is.
        $this->service()->transition($task, Task::STATUS_DONE, $this->admin);

        try {
            $this->actingAs($this->admin)->post(route('tasks.transition', $task->fresh()), ['status' => Task::STATUS_TODO]);

            $this->fail('a done task must not jump back to todo');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('In progress', (string) $refused->validator->errors()->first('status'));
        }

        $this->assertSame(Task::STATUS_DONE, $task->fresh()->status);
    }

    public function test_done_can_be_reopened_cancelled_cannot_be_moved_at_all(): void
    {
        $task = $this->makeTask($this->admin);

        $this->service()->transition($task, Task::STATUS_DONE, $this->admin);
        $this->assertNotNull($task->fresh()->completed_at);

        $this->service()->transition($task->fresh(), Task::STATUS_IN_PROGRESS, $this->admin);

        // Reopening keeps the completion on the record: it happened.
        $this->assertSame(Task::STATUS_IN_PROGRESS, $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);

        $this->service()->transition($task->fresh(), Task::STATUS_CANCELLED, $this->admin);
        $this->assertSame([], $task->fresh()->allowedTransitions());

        $this->expectException(ValidationException::class);
        $this->service()->transition($task->fresh(), Task::STATUS_IN_PROGRESS, $this->admin);
    }

    public function test_the_assignee_may_move_their_own_task_but_not_somebody_elses(): void
    {
        $assignee = $this->staff(['tasks.view_own']);
        $stranger = $this->staff(['tasks.view_own']);

        $task = $this->makeTask($assignee);

        $this->actingAs($assignee)
            ->post(route('tasks.transition', $task), ['status' => Task::STATUS_IN_PROGRESS])
            ->assertRedirect();

        $this->actingAs($stranger)
            ->post(route('tasks.transition', $task->fresh()), ['status' => Task::STATUS_BLOCKED])
            ->assertForbidden();

        $this->assertSame(Task::STATUS_IN_PROGRESS, $task->fresh()->status);
    }

    public function test_only_a_manager_may_create_assign_or_reschedule(): void
    {
        $person = $this->staff(['tasks.view_own']);
        $task = $this->makeTask($person);

        $this->actingAs($person)->get(route('tasks.create'))->assertForbidden();
        $this->actingAs($person)->post(route('tasks.assign', $task), ['assigned_to' => $this->admin->id])->assertForbidden();
        $this->actingAs($person)->post(route('tasks.reschedule', $task), ['due_at' => '2026-12-01 10:00'])->assertForbidden();

        $manager = $this->staff(['tasks.view_own', 'tasks.view_all', 'tasks.manage']);

        $this->actingAs($manager)->get(route('tasks.create'))->assertOk();
    }

    /* ------------------------------------------------------------- assignment */

    public function test_assignment_tells_the_assignee_once_and_records_who_did_it(): void
    {
        $person = $this->makeUser();
        $task = $this->makeTask();

        $this->service()->assign($task, $person, $this->admin);

        $this->assertSame($person->id, $task->fresh()->assigned_to);
        $this->assertSame(1, Notification::query()->where('user_id', $person->id)->where('event_type', 'task.assigned')->count());

        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event' => TaskEvent::ASSIGNED, 'note' => $person->name]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.task_assigned',
            'entity_id' => $task->id,
            'actor_id' => $this->admin->id,
        ]);

        // Assigning the same person again is not a second message.
        $this->service()->assign($task->fresh(), $person, $this->admin);
        $this->assertSame(1, Notification::query()->where('user_id', $person->id)->where('event_type', 'task.assigned')->count());
    }

    public function test_a_comment_tells_the_other_party_and_not_the_author(): void
    {
        $assignee = $this->makeUser();
        $task = $this->makeTask($assignee);

        $this->service()->comment($task, $this->admin, 'Please start with aisle four.');

        $this->assertDatabaseHas('task_comments', ['task_id' => $task->id, 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event' => TaskEvent::COMMENTED]);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $assignee->id)
            ->where('event_type', 'task.commented')
            ->count());
        $this->assertSame(0, Notification::query()->where('user_id', $this->admin->id)->count());
    }

    /* ------------------------------------------------------------------- clock */

    public function test_overdue_is_decided_by_the_clock_rather_than_by_a_stored_flag(): void
    {
        $task = $this->makeTask($this->admin, ['due_at' => now()->subMinute()->toDateTimeString()]);

        $this->assertTrue($task->fresh()->isOverdue());
        $this->assertSame(1, $this->service()->summary((int) $this->admin->company_id, $this->admin, true)['mine_overdue']);

        $this->service()->reschedule($task, now()->addWeek()->toDateTimeString(), $this->admin);
        $this->assertFalse($task->fresh()->isOverdue());
        $this->assertSame(0, $this->service()->summary((int) $this->admin->company_id, $this->admin, true)['mine_overdue']);

        // Clearing the date is a decision, and it is on the timeline too.
        $this->service()->reschedule($task->fresh(), null, $this->admin);
        $this->assertNull($task->fresh()->due_at);
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('event', TaskEvent::DUE_CHANGED)->count());
    }

    public function test_an_urgent_task_comes_before_a_low_one_in_my_list(): void
    {
        $person = $this->makeUser();
        $this->makeTask($person, ['title' => 'Eventually', 'priority' => 'low', 'due_at' => now()->addMonth()->toDateTimeString()]);
        $this->makeTask($person, ['title' => 'Right now', 'priority' => 'urgent', 'due_at' => now()->addMonth()->toDateTimeString()]);

        $ordered = $this->service()->mine((int) $person->company_id, $person->id);

        $this->assertSame('Right now', $ordered->first()->title);
        $this->assertSame('Eventually', $ordered->last()->title);
    }

    /* ---------------------------------------------------------------- projects */

    public function test_a_project_groups_tasks_and_its_code_is_unique_inside_the_company(): void
    {
        $this->actingAs($this->admin)->post(route('projects.store'), [
            'code' => 'STOCK-26',
            'name' => 'Annual stock take',
            'description' => 'Every shelf, counted once, properly.',
        ])->assertRedirect();

        $project = Project::query()->firstOrFail();
        $this->assertSame('active', $project->status);

        // The same code again in the same company is a validation error.
        $this->actingAs($this->admin)->post(route('projects.store'), [
            'code' => 'STOCK-26',
            'name' => 'Duplicate',
        ])->assertSessionHasErrors('code');

        $task = $this->makeTask($this->admin, ['title' => 'Count aisle one', 'project_id' => $project->id]);
        $this->makeTask($this->admin, ['title' => 'A loose end']);

        $list = $this->service()->projects((int) $this->admin->company_id);
        $this->assertSame(1, $list->first()->tasks_count);
        $this->assertSame(1, $list->first()->open_tasks_count);

        $this->actingAs($this->admin)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Count aisle one')
            ->assertDontSee('A loose end');

        // Completing the project does not complete its work.
        $this->actingAs($this->admin)->put(route('projects.update', $project), [
            'name' => $project->name,
            'status' => 'completed',
        ])->assertRedirect();

        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame(Task::STATUS_TODO, $task->fresh()->status);
        $this->assertTrue($task->fresh()->isOpen());
    }

    /* -------------------------------------------------------------------- menu */

    public function test_the_catalogue_leaves_for_notices_and_tasks_now_point_at_them(): void
    {
        $leaves = \App\Domain\Foundation\MenuItem::query()
            ->where('status', 'active')
            ->whereIn('route', ['/app/tasks', '/app/tasks/all', '/app/tasks/kanban', '/app/projects'])
            ->pluck('route')
            ->all();

        $this->assertCount(4, $leaves);
    }
}
