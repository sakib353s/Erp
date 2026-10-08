<?php

namespace Tests\Feature;

use App\Domain\Business\Meeting;
use App\Domain\Business\MeetingAttendee;
use App\Domain\Business\Task;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use Carbon\Carbon;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §12-11 — meetings, minutes and action items.
 *
 * What is pinned, in the order the office would care about it:
 *
 *  · the diary refuses to book people twice in the same hour, names who and for
 *    what, and can be overridden on purpose — a clash is a decision, not a
 *    surprise;
 *  · a meeting is only for the people on it (403 by URL for anybody else, 404 for
 *    another company), and the office's diary is for the people who run it;
 *  · the life of a meeting is ordered: it is held before it is minuted, and it is
 *    held before attendance means anything; cancelling needs a reason and a
 *    cancelled meeting cannot be moved back to life;
 *  · an action item is a **task** with a link back to the meeting — it appears in
 *    my tasks and on the board, keeps the meeting's date and owner, and the
 *    minutes page reads the same rows;
 *  · the reminder goes out once per meeting per person, and skips the people who
 *    said they cannot come.
 */
class MeetingBoardTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $headOffice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->headOffice = $this->defaultBranch();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* --------------------------------------------------------------- helpers */

    protected function viewer(User $user): void
    {
        $user->roles()->attach($this->roleWith(['business.meetings.view'])->id);
    }

    protected function organiser(User $user): void
    {
        $user->roles()->attach($this->roleWith(['business.meetings.view', 'business.meetings.manage'])->id);
    }

    /**
     * A meeting called through the real screen.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<int, int>  $attendees
     */
    protected function callMeeting(array $overrides = [], array $attendees = []): Meeting
    {
        $startsAt = $overrides['starts_at'] ?? now()->addDay()->setTime(10, 0)->toDateTimeString();

        $payload = array_merge([
            'title' => 'Monthly stock review',
            'agenda' => "1. Aisle four count\n2. Narayanganj transfer",
            'location' => 'Board room',
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt)->addHour()->toDateTimeString(),
            'attendees' => $attendees,
        ], $overrides);

        $this->actingAs($this->admin)->post(route('meetings.store'), $payload)->assertRedirect();

        return Meeting::query()->latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------------ write */

    public function test_a_meeting_is_called_with_its_people_and_every_listener_is_told(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $other = $this->makeUser(['name' => 'Sohel Rana']);

        $meeting = $this->callMeeting([], [$person->id, $other->id]);

        $this->assertSame('scheduled', $meeting->status);
        $this->assertSame($this->admin->id, $meeting->scheduled_by);
        $this->assertSame($this->admin->id, $meeting->chaired_by, 'the person who calls it chairs it unless told otherwise');
        $this->assertSame(2, $meeting->attendees()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.meeting_scheduled',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
        ]);
        $this->assertDatabaseHas('meeting_events', [
            'meeting_id' => $meeting->id,
            'action' => 'scheduled',
        ]);

        // The people on the list are told; the person who called it is not
        // notified about their own meeting.
        $this->assertDatabaseHas('notifications', ['user_id' => $person->id, 'event_type' => 'meeting.invited']);
        $this->assertDatabaseHas('notifications', ['user_id' => $other->id, 'event_type' => 'meeting.invited']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->admin->id, 'event_type' => 'meeting.invited']);

        $this->actingAs($this->admin)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('Monthly stock review')
            ->assertSee('Rahima Begum');
    }

    public function test_a_slot_already_taken_by_somebody_on_the_list_is_refused_and_names_who(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);

        $this->callMeeting(['title' => 'Morning briefing'], [$person->id]);

        $this->actingAs($this->admin)->post(route('meetings.store'), [
            'title' => 'Another meeting at the same hour',
            'starts_at' => now()->addDay()->setTime(10, 30)->toDateTimeString(),
            'ends_at' => now()->addDay()->setTime(11, 30)->toDateTimeString(),
            'attendees' => [$person->id],
        ])->assertSessionHasErrors('starts_at');

        $this->assertSame(1, Meeting::query()->count());

        $message = $this->allFlashedErrors();
        $this->assertStringContainsString('Rahima Begum', $message);
        $this->assertStringContainsString('Morning briefing', $message);
    }

    public function test_a_clash_can_be_recorded_on_purpose(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);

        $this->callMeeting(['title' => 'Morning briefing'], [$person->id]);

        $meeting = $this->callMeeting([
            'title' => 'Overlapping by decision',
            'starts_at' => now()->addDay()->setTime(10, 30)->toDateTimeString(),
            'ends_at' => now()->addDay()->setTime(11, 30)->toDateTimeString(),
            'allow_clash' => 1,
        ], [$person->id]);

        $this->assertSame('scheduled', $meeting->status);
        $this->assertSame(2, Meeting::query()->count());
    }

    /* ------------------------------------------------------------------ life */

    public function test_the_life_of_a_meeting_is_ordered_held_then_minuted(): void
    {
        $meeting = $this->callMeeting();

        // Not held yet: no attendance to record, no minutes to write, no action
        // items to raise.
        $this->actingAs($this->admin)->post(route('meetings.attendance', $meeting), [
            'attendance' => [$meeting->attendees()->first()->id => 'present'],
        ])->assertSessionHasErrors('attendance');

        $this->actingAs($this->admin)->post(route('meetings.recordMinutes', $meeting), [
            'minutes' => 'We decided something.',
        ])->assertSessionHasErrors('minutes');

        $this->actingAs($this->admin)->post(route('meetings.actionItem', $meeting), [
            'title' => 'Do the thing',
        ])->assertSessionHasErrors('title');

        $this->assertSame(0, Task::query()->whereNotNull('meeting_id')->count());

        // Held: now all three are possible.
        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting))->assertRedirect();

        $this->assertSame('held', $meeting->fresh()->status);
        $this->assertNotNull($meeting->fresh()->held_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.meeting_held',
            'entity_id' => $meeting->id,
        ]);
    }

    public function test_empty_minutes_are_refused_rather_than_stored(): void
    {
        $meeting = $this->callMeeting();
        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting));

        $this->actingAs($this->admin)->post(route('meetings.recordMinutes', $meeting), [
            'minutes' => '   ',
        ])->assertSessionHasErrors('minutes');

        $this->assertFalse($meeting->fresh()->hasMinutes());
        $this->assertTrue($meeting->fresh()->needsMinutes());
    }

    public function test_minutes_go_on_file_and_everybody_on_the_list_is_told_once(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $meeting = $this->callMeeting([], [$person->id]);

        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting));

        $body = "Present: everybody.\n1. Aisle four recounted on Friday.\n2. Transfer to Narayanganj approved for 20 October.";

        $this->actingAs($this->admin)->post(route('meetings.recordMinutes', $meeting), ['minutes' => $body])
            ->assertRedirect();

        $fresh = $meeting->fresh();

        $this->assertTrue($fresh->hasMinutes());
        $this->assertSame($body, $fresh->minutes);
        $this->assertNotNull($fresh->minutes_recorded_at);
        $this->assertSame($this->admin->id, $fresh->minutes_recorded_by);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $person->id)
            ->where('event_type', 'meeting.minutes')
            ->count());

        // Rewriting the minutes does not send a second message.
        $this->actingAs($this->admin)->post(route('meetings.recordMinutes', $meeting), [
            'minutes' => $body."\n3. Added after the fact.",
        ])->assertRedirect();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $person->id)
            ->where('event_type', 'meeting.minutes')
            ->count());

        $this->actingAs($this->admin)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('Aisle four recounted on Friday');
    }

    public function test_attendance_is_marked_per_person_and_an_apology_is_not_an_absence(): void
    {
        $present = $this->makeUser(['name' => 'Rahima Begum']);
        $apology = $this->makeUser(['name' => 'Sohel Rana']);

        $meeting = $this->callMeeting([], [$present->id, $apology->id]);
        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting));

        $rows = $meeting->attendees()->get()->keyBy('user_id');

        $this->actingAs($this->admin)->post(route('meetings.attendance', $meeting), [
            'attendance' => [
                $rows[$present->id]->id => 'present',
                $rows[$apology->id]->id => 'apology',
            ],
        ])->assertRedirect();

        $fresh = $meeting->fresh();
        $this->assertSame(1, $fresh->presentCount());
        $this->assertSame(2, $fresh->attendedCount(), 'an apology counts as accounted for, not as absent');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.meeting_attendance_marked',
            'entity_id' => $meeting->id,
        ]);
    }

    public function test_cancelling_needs_a_reason_and_a_cancelled_meeting_cannot_be_moved_back_to_life(): void
    {
        $meeting = $this->callMeeting();

        $this->actingAs($this->admin)->post(route('meetings.cancel', $meeting), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('scheduled', $meeting->fresh()->status);

        $this->actingAs($this->admin)->post(route('meetings.cancel', $meeting), [
            'reason' => 'The stock take moved to Thursday.',
        ])->assertRedirect();

        $fresh = $meeting->fresh();

        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('The stock take moved to Thursday.', $fresh->cancelled_reason);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.meeting_cancelled',
            'entity_id' => $meeting->id,
        ]);

        // Moving or holding it now is refused with a sentence that says why.
        $this->actingAs($this->admin)->post(route('meetings.reschedule', $meeting), [
            'starts_at' => now()->addWeek()->setTime(9, 0)->toDateTimeString(),
        ])->assertSessionHasErrors('meeting');

        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting))->assertSessionHasErrors('meeting');
    }

    public function test_moving_a_meeting_tells_the_list_the_new_time_and_records_the_old_one(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $meeting = $this->callMeeting([], [$person->id]);

        $was = $meeting->starts_at->copy();
        $now = now()->addWeek()->setTime(15, 0);

        $this->actingAs($this->admin)->post(route('meetings.reschedule', $meeting), [
            'starts_at' => $now->toDateTimeString(),
            'ends_at' => $now->copy()->addMinutes(45)->toDateTimeString(),
            'reason' => 'The auditor is only free on Wednesday.',
        ])->assertRedirect();

        $this->assertSame($now->toDateTimeString(), $meeting->fresh()->starts_at->toDateTimeString());

        $event = $meeting->fresh()->events()->where('action', 'rescheduled')->first();
        $this->assertNotNull($event);
        $this->assertSame($was->toDateTimeString(), $event->meta['from']);
        $this->assertSame($now->toDateTimeString(), $event->meta['to']);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $person->id)
            ->where('event_type', 'meeting.moved')
            ->count());
    }

    /* ------------------------------------------------------------ action items */

    public function test_an_action_item_is_a_real_task_that_remembers_its_meeting(): void
    {
        $owner = $this->makeUser(['name' => 'Rahima Begum']);
        $meeting = $this->callMeeting([], [$owner->id]);
        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting));

        $this->actingAs($this->admin)->post(route('meetings.actionItem', $meeting), [
            'title' => 'Recount aisle four before the audit',
            'description' => 'Agreed in the meeting: a full count, two people, Friday morning.',
            'assigned_to' => $owner->id,
            'due_at' => now()->addDays(3)->toDateTimeString(),
            'priority' => 'high',
        ])->assertRedirect();

        $task = Task::query()->latest('id')->firstOrFail();

        $this->assertSame($meeting->id, $task->meeting_id);
        $this->assertSame($owner->id, $task->assigned_to);
        $this->assertSame('high', $task->priority);
        $this->assertSame('todo', $task->status);

        // It is the same work the task board shows, in the owner's own list.
        $this->actingAs($owner)->get(route('tasks.index'))->assertOk()->assertSee('Recount aisle four before the audit');

        // And the meeting shows it as what came out of it.
        $this->actingAs($this->admin)
            ->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee('Recount aisle four before the audit');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'business.meeting_action_item_raised',
            'entity_id' => $meeting->id,
        ]);

        // The owner was told by the task engine, not by a second code path.
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'event_type' => 'task.assigned']);
    }

    public function test_the_action_items_page_shows_your_own_work_and_the_office_list_needs_the_task_key(): void
    {
        $mine = $this->makeUser(['name' => 'Rahima Begum']);
        $theirs = $this->makeUser(['name' => 'Sohel Rana']);

        $meeting = $this->callMeeting([], [$mine->id, $theirs->id]);
        $this->actingAs($this->admin)->post(route('meetings.hold', $meeting));

        $this->actingAs($this->admin)->post(route('meetings.actionItem', $meeting), [
            'title' => 'My own action item', 'assigned_to' => $mine->id,
        ]);
        $this->actingAs($this->admin)->post(route('meetings.actionItem', $meeting), [
            'title' => 'Somebody else’s action item', 'assigned_to' => $theirs->id,
        ]);

        $this->viewer($mine);
        $mine->roles()->attach($this->roleWith(['tasks.view_own'])->id);

        $this->actingAs($mine)
            ->get(route('meetings.actionItems'))
            ->assertOk()
            ->assertSee('My own action item')
            ->assertDontSee('Somebody else’s action item');

        // The office's action items are the office's work: that needs tasks.view_all.
        $mine->roles()->attach($this->roleWith(['tasks.view_all'])->id);

        $this->actingAs($mine->fresh())
            ->get(route('meetings.actionItems'))
            ->assertOk()
            ->assertSee('My own action item')
            ->assertSee('Somebody else’s action item');
    }

    /* ---------------------------------------------------------------- the bell */

    public function test_the_reminder_goes_out_once_per_person_and_skips_the_people_who_said_no(): void
    {
        $coming = $this->makeUser(['name' => 'Rahima Begum']);
        $declined = $this->makeUser(['name' => 'Sohel Rana']);

        $meeting = $this->callMeeting(['starts_at' => now()->addMinutes(45)->toDateTimeString()], [$coming->id, $declined->id]);

        $declinedRow = $meeting->attendees()->where('user_id', $declined->id)->first();
        $declinedRow->forceFill(['response' => 'declined'])->save();

        $this->artisan('erp:business:meeting-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $coming->id,
            'event_type' => 'meeting.reminder',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $declined->id,
            'event_type' => 'meeting.reminder',
        ]);

        // A quarter of an hour later it is still one message, not two.
        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('erp:business:meeting-reminders')->assertSuccessful();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $coming->id)
            ->where('event_type', 'meeting.reminder')
            ->count());
    }

    public function test_a_meeting_far_away_is_not_reminded_about_yet(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $this->callMeeting(['starts_at' => now()->addHours(6)->toDateTimeString()], [$person->id]);

        $this->artisan('erp:business:meeting-reminders')->assertSuccessful();

        $this->assertSame(0, Notification::query()->where('event_type', 'meeting.reminder')->count());
    }

    /* ------------------------------------------------------------------ people */

    public function test_inside_the_list_and_outside_it(): void
    {
        $onList = $this->makeUser(['name' => 'Rahima Begum']);
        $stranger = $this->makeUser(['name' => 'Sohel Rana']);

        $meeting = $this->callMeeting([], [$onList->id]);

        $this->viewer($onList);
        $this->viewer($stranger);

        $this->actingAs($onList)->get(route('meetings.show', $meeting))->assertOk();

        // Not on the list: the meeting's existence is information too.
        $this->actingAs($stranger)->get(route('meetings.show', $meeting))->assertForbidden();
        $this->actingAs($stranger)->post(route('meetings.respond', $meeting), ['response' => 'accepted'])->assertForbidden();

        // The diary shows the invitee their meeting and not the stranger's view of it.
        $this->actingAs($onList)->get(route('meetings.index'))->assertOk()->assertSee('Monthly stock review');
        $this->actingAs($stranger)->get(route('meetings.index'))->assertOk()->assertDontSee('Monthly stock review');
    }

    public function test_the_person_on_the_list_answers_for_themselves(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $meeting = $this->callMeeting([], [$person->id]);

        $this->viewer($person);

        $this->actingAs($person)->post(route('meetings.respond', $meeting), ['response' => 'accepted'])->assertRedirect();

        $this->assertSame('accepted', $meeting->attendees()->where('user_id', $person->id)->first()->response);

        $this->actingAs($person)->post(route('meetings.respond', $meeting), ['response' => 'maybe'])->assertSessionHasErrors('response');
    }

    public function test_running_the_diary_is_a_different_permission_from_reading_it(): void
    {
        $person = $this->makeUser(['name' => 'Rahima Begum']);
        $meeting = $this->callMeeting([], [$person->id]);

        $this->viewer($person);

        $this->actingAs($person)->get(route('meetings.create'))->assertForbidden();
        $this->actingAs($person)->post(route('meetings.store'), [
            'title' => 'My own meeting', 'starts_at' => now()->addDay()->toDateTimeString(),
        ])->assertForbidden();
        $this->actingAs($person)->post(route('meetings.hold', $meeting))->assertForbidden();
        $this->actingAs($person)->post(route('meetings.cancel', $meeting), ['reason' => 'Nope'])->assertForbidden();

        // Somebody with no key at all cannot open the desk.
        $stranger = $this->makeUser();
        $this->actingAs($stranger)->get(route('meetings.index'))->assertForbidden();
    }

    public function test_a_meeting_from_another_company_is_not_found(): void
    {
        $meeting = $this->callMeeting();

        // `singleton` is not mass-assignable on purpose — the column is the
        // database-level guard behind a one-company instance. Written by hand,
        // false being the only value its unique index has room for.
        $elsewhere = new Company(['name' => 'Another Company', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        $outsider = User::query()->create([
            'company_id' => $elsewhere->id,
            'name' => 'Outsider',
            'email' => 'outsider@elsewhere.test',
            'password' => self::ADMIN_PASSWORD,
            'status' => 'active',
            'branch_scope' => 'all',
        ]);
        $this->organiser($outsider);
        $this->bindTenantContext($outsider);

        $this->actingAs($outsider)->get(route('meetings.show', $meeting))->assertNotFound();
    }

    /* ------------------------------------------------------------------- menu */

    public function test_the_catalogue_leaves_land_on_the_meeting_desk(): void
    {
        foreach (['/app/meetings', '/app/meetings/create', '/app/meetings/minutes', '/app/meetings/action-items'] as $route) {
            $leaf = MenuItem::query()
                ->where('status', 'active')
                ->where('route', $route)
                ->first();

            $this->assertNotNull($leaf, "the catalogue leaf for {$route} is not in the navigation registry");
        }
    }
}
