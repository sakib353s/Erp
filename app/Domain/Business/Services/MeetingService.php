<?php

namespace App\Domain\Business\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\Meeting;
use App\Domain\Business\MeetingAttendee;
use App\Domain\Business\MeetingEvent;
use App\Domain\Business\Task;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * §12-11 — the meeting's engine.
 *
 * A meeting is a small thing that four larger things hang off, and each of them
 * is here rather than in a controller:
 *
 *  · **scheduling** — and, importantly, *what it refuses*: two meetings booked
 *    over the same people in the same hour is how a diary stops being true. The
 *    clash is named (who, and which meeting), and it can be overridden on purpose
 *    so an unavoidable overlap is a decision rather than a surprise.
 *  · **the life of one** — held, moved, cancelled. Moving re-notifies; holding
 *    stamps the time; cancelling needs a reason, because “cancelled” without a
 *    why is the sentence nobody can act on.
 *  · **the minutes** — a meeting can only be minuted once it has been held (you
 *    cannot minute a meeting that has not happened), and attendance is marked per
 *    person: present, absent, or an apology, which is not the same as absent.
 *  · **the action items** — and these are *tasks*, created through
 *    {@see TaskService} with a `meeting_id`, so what a meeting decides lands on
 *    the task board, in “my tasks”, with the same states, transitions, event log
 *    and notifications as any other work. A meeting that keeps its own separate
 *    to-do list is a meeting whose decisions quietly go missing.
 *
 * Notifications are deduped per meeting, per person, per event — moving a meeting
 * twice is two messages (the time changed twice), but re-saving the minutes is
 * one.
 */
class MeetingService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected NotificationCenter $notifications,
        protected TaskService $tasks,
    ) {}

    /* ------------------------------------------------------------- scheduling */

    /**
     * @param  array{title: string, agenda?: ?string, location?: ?string, starts_at: string, ends_at?: ?string, branch_id?: ?int, allow_clash?: mixed}  $data
     * @param  array<int, array{user_id?: ?int, name?: ?string, role?: string}>  $attendees
     */
    public function schedule(array $data, User $actor, array $attendees = []): Meeting
    {
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = isset($data['ends_at']) && $data['ends_at'] ? Carbon::parse($data['ends_at']) : null;

        $ids = $this->attendeeIds($attendees);

        if (! filter_var($data['allow_clash'] ?? false, FILTER_VALIDATE_BOOLEAN) && $ids !== []) {
            $this->assertNoClash($ids, $startsAt, $endsAt);
        }

        $meeting = Meeting::create([
            'company_id' => $actor->company_id,
            'branch_id' => $data['branch_id'] ?? $actor->default_branch_id,
            'title' => $data['title'],
            'agenda' => $data['agenda'] ?? null,
            'location' => $data['location'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Meeting::STATUS_SCHEDULED,
            'chaired_by' => $data['chaired_by'] ?? $actor->id,
            'scheduled_by' => $actor->id,
        ]);

        foreach ($attendees as $attendee) {
            $this->addAttendeeRow($meeting, $attendee['user_id'] ?? null, $attendee['name'] ?? null, $attendee['role'] ?? 'attendee');
        }

        $this->history($meeting, 'scheduled', $actor, sprintf(
            'Scheduled for %s%s, with %d person(s).',
            $startsAt->format('d M Y H:i'),
            $endsAt ? '–'.$endsAt->format('H:i') : '',
            $meeting->attendees()->count(),
        ), [
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at' => $endsAt?->toDateTimeString(),
            'attendees' => $meeting->attendees()->count(),
        ]);

        $this->audit->record([
            'action' => 'business.meeting_scheduled',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'after' => [
                'title' => $meeting->title,
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $endsAt?->toDateTimeString(),
                'attendees' => $meeting->attendees()->count(),
            ],
        ]);

        $this->inviteEveryone($meeting, $actor);

        return $meeting;
    }

    /** Move a meeting. Everybody who was invited is told the new time. */
    public function reschedule(Meeting $meeting, array $data, User $actor): Meeting
    {
        $this->guardEditable($meeting, 'rescheduled');

        $before = $meeting->starts_at->copy();
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = isset($data['ends_at']) && $data['ends_at'] ? Carbon::parse($data['ends_at']) : null;

        $ids = $meeting->attendees->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();

        if (! filter_var($data['allow_clash'] ?? false, FILTER_VALIDATE_BOOLEAN) && $ids !== []) {
            $this->assertNoClash($ids, $startsAt, $endsAt, $meeting->id);
        }

        $meeting->forceFill([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => $data['location'] ?? $meeting->location,
        ])->save();

        $note = $data['reason'] ?? null;

        $this->history($meeting, 'rescheduled', $actor, sprintf(
            'Moved from %s to %s.%s',
            $before->format('d M Y H:i'),
            $startsAt->format('d M Y H:i'),
            $note ? ' '.$note : '',
        ), [
            'from' => $before->toDateTimeString(),
            'to' => $startsAt->toDateTimeString(),
        ]);

        $this->audit->record([
            'action' => 'business.meeting_rescheduled',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'before' => ['starts_at' => $before->toDateTimeString()],
            'after' => ['starts_at' => $startsAt->toDateTimeString()],
            'reason' => $note,
        ]);

        $this->announce($meeting, 'meeting.moved', 'Meeting moved', sprintf(
            '%s — now %s. Was %s.',
            $meeting->title,
            $startsAt->format('d M Y H:i'),
            $before->format('d M Y H:i'),
        ), ':moved:'.$startsAt->toDateTimeString());

        return $meeting;
    }

    /** The meeting happened. */
    public function hold(Meeting $meeting, User $actor): Meeting
    {
        $this->guardEditable($meeting, 'held');

        $meeting->forceFill([
            'status' => Meeting::STATUS_HELD,
            'held_at' => now(),
        ])->save();

        $this->history($meeting, 'held', $actor, 'Held on '.now()->format('d M Y').'. Minutes are still to be written.');

        $this->audit->record([
            'action' => 'business.meeting_held',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'before' => ['status' => Meeting::STATUS_SCHEDULED],
            'after' => ['status' => Meeting::STATUS_HELD],
        ]);

        return $meeting;
    }

    public function cancel(Meeting $meeting, User $actor, string $reason): Meeting
    {
        if ($meeting->status === Meeting::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'reason' => 'This meeting was already cancelled on '.($meeting->cancelled_at?->format('d M Y') ?? 'an earlier date').'.',
            ]);
        }

        $meeting->forceFill([
            'status' => Meeting::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
        ])->save();

        $this->history($meeting, 'cancelled', $actor, 'Cancelled: '.$reason);

        $this->audit->record([
            'action' => 'business.meeting_cancelled',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'before' => ['status' => $meeting->getOriginal('status')],
            'after' => ['status' => Meeting::STATUS_CANCELLED],
            'reason' => $reason,
        ]);

        $this->announce($meeting, 'meeting.cancelled', 'Meeting cancelled', sprintf(
            '%s on %s was cancelled: %s',
            $meeting->title,
            $meeting->starts_at->format('d M Y H:i'),
            $reason,
        ), ':cancelled');

        return $meeting;
    }

    /* ---------------------------------------------------------------- people */

    public function addAttendee(Meeting $meeting, ?User $user, ?string $name, string $role, User $actor): MeetingAttendee
    {
        $this->guardEditable($meeting, 'invited');

        $attendee = $this->addAttendeeRow($meeting, $user?->id, $name, $role);

        $this->history($meeting, 'invited', $actor, 'Added '.$attendee->displayName().' to the list.', [
            'attendee_id' => $attendee->id,
        ]);

        if ($user !== null && $user->id !== $actor->id) {
            $this->notify($meeting, $user, 'meeting.invited', 'Meeting invitation', sprintf(
                '%s — %s%s',
                $meeting->title,
                $meeting->starts_at->format('d M Y H:i'),
                $meeting->location ? ' at '.$meeting->location : '',
            ), ':invited:'.$user->id);
        }

        return $attendee;
    }

    /** Somebody says whether they are coming. */
    public function respond(Meeting $meeting, User $person, string $response): MeetingAttendee
    {
        if (! array_key_exists($response, Meeting::RESPONSES)) {
            throw ValidationException::withMessages([
                'response' => 'Answer with one of: '.implode(', ', array_keys(Meeting::RESPONSES)).'.',
            ]);
        }

        $attendee = $meeting->attendees()->where('user_id', $person->id)->first();

        if ($attendee === null) {
            abort(403, 'You are not on this meeting’s list.');
        }

        $attendee->forceFill([
            'response' => $response,
            'responded_at' => now(),
        ])->save();

        $this->history($meeting, 'responded', $person, $person->name.' answered: '.$attendee->responseLabel().'.', [
            'response' => $response,
        ]);

        return $attendee;
    }

    /**
     * Who was actually there. Marked once the meeting is over — before that there
     * is nothing to record.
     *
     * @param  array<int, string>  $states  attendee id => present|absent|apology
     */
    public function recordAttendance(Meeting $meeting, array $states, User $actor): Meeting
    {
        if ($meeting->status === Meeting::STATUS_SCHEDULED) {
            throw ValidationException::withMessages([
                'attendance' => 'The meeting has not been held yet, so there is no attendance to record. Mark it held first.',
            ]);
        }

        $marked = 0;
        $counts = ['present' => 0, 'absent' => 0, 'apology' => 0];

        foreach ($meeting->attendees as $attendee) {
            if (! array_key_exists($attendee->id, $states)) {
                continue;
            }

            $state = $states[$attendee->id] === '' ? null : $states[$attendee->id];

            if ($state !== null && ! array_key_exists($state, Meeting::ATTENDANCE)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Attendance is present, absent or an apology — “'.$state.'” is not one of them.',
                ]);
            }

            $attendee->forceFill(['attendance' => $state])->save();

            if ($state !== null) {
                $counts[$state]++;
                $marked++;
            }
        }

        $this->history($meeting, 'attendance', $actor, sprintf(
            'Attendance marked: %d present, %d absent, %d apology.',
            $counts['present'],
            $counts['absent'],
            $counts['apology'],
        ), $counts);

        $this->audit->record([
            'action' => 'business.meeting_attendance_marked',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'after' => $counts + ['marked' => $marked],
        ]);

        return $meeting->refresh();
    }

    /* --------------------------------------------------------------- minutes */

    /**
     * Write down what was said. Empty minutes are refused rather than stored:
     * “the minutes are on file” has to mean something, and a held meeting with a
     * blank page is the failure this desk exists to prevent. A meeting cannot be
     * minuted before it has been held — a diary note is not a minute.
     */
    public function recordMinutes(Meeting $meeting, string $minutes, User $actor): Meeting
    {
        if ($meeting->status === Meeting::STATUS_SCHEDULED) {
            throw ValidationException::withMessages([
                'minutes' => 'This meeting has not been held yet. Minutes are the record of what happened, so mark it held first.',
            ]);
        }

        if (trim($minutes) === '') {
            throw ValidationException::withMessages([
                'minutes' => 'Minutes cannot be empty. If nothing was decided, say that in a sentence — an empty page reads as “nobody wrote it down”.',
            ]);
        }

        $wasRecorded = $meeting->hasMinutes();

        $meeting->forceFill([
            'minutes' => $minutes,
            'minutes_recorded_at' => now(),
            'minutes_recorded_by' => $actor->id,
        ])->save();

        $this->history($meeting, 'minutes', $actor, $wasRecorded
            ? 'Minutes rewritten. The previous text is in the audit trail.'
            : 'Minutes recorded.');

        $this->audit->record([
            'action' => 'business.meeting_minutes_recorded',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'before' => ['has_minutes' => $wasRecorded],
            'after' => ['has_minutes' => true, 'characters' => mb_strlen($minutes)],
        ]);

        // One message per meeting per person: minutes published on Tuesday are
        // available on Wednesday too.
        $this->announce($meeting, 'meeting.minutes', 'Minutes available', sprintf(
            '%s — minutes for the meeting of %s are on file.',
            $meeting->title,
            $meeting->starts_at->format('d M Y'),
        ), ':minutes');

        return $meeting;
    }

    /* ---------------------------------------------------------- action items */

    /**
     * What the meeting decided, turned into real work.
     *
     * @param  array{title: string, description?: ?string, assigned_to?: ?int, due_at?: ?string, priority?: string}  $data
     */
    public function raiseActionItem(Meeting $meeting, array $data, User $actor): Task
    {
        if ($meeting->status === Meeting::STATUS_SCHEDULED) {
            throw ValidationException::withMessages([
                'title' => 'Action items come out of a meeting that has happened. Mark it held first, or record the work as a task of its own.',
            ]);
        }

        $task = $this->tasks->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'branch_id' => $meeting->branch_id ?? $actor->default_branch_id,
            // The link back is the whole point: the same piece of work, visible on
            // the task board and on the meeting that produced it.
            'meeting_id' => $meeting->id,
        ], $actor);

        $this->history($meeting, 'action_item', $actor, 'Action item: '.$task->title, [
            'task_id' => $task->id,
        ]);

        $this->audit->record([
            'action' => 'business.meeting_action_item_raised',
            'entity_type' => 'meeting',
            'entity_id' => $meeting->id,
            'actor_id' => $actor->id,
            'branch_id' => $meeting->branch_id,
            'after' => ['task_id' => $task->id, 'title' => $task->title],
        ]);

        return $task;
    }

    /**
     * The action items this application knows about, newest first, overdue first.
     *
     * @return Collection<int, Task>
     */
    public function actionItems(User $person, bool $seesEverybody, bool $includeClosed = false): Collection
    {
        $query = Task::query()
            ->where('company_id', $person->company_id)
            ->whereNotNull('meeting_id')
            ->with(['meeting', 'assignee']);

        if (! $seesEverybody) {
            $query->where('assigned_to', $person->id);
        }

        if (! $includeClosed) {
            $query->open();
        }

        return $query->get()
            ->sortBy([
                fn (Task $task) => $task->isOverdue() ? 0 : 1,
                fn (Task $task) => $task->due_at?->timestamp ?? PHP_INT_MAX,
            ])
            ->values();
    }

    /* --------------------------------------------------------------- reading */

    /**
     * @param  array{scope?: string, q?: string, per_page?: int}  $filters
     */
    public function index(array $filters, User $person, bool $seesEverybody): LengthAwarePaginator
    {
        $query = $this->visible($person);

        $scope = (string) ($filters['scope'] ?? '');

        if ($scope === 'upcoming') {
            $query->upcoming();
        } elseif ($scope === 'minutes-due') {
            $query->held()->where(fn (Builder $q) => $q->whereNull('minutes')->orWhere('minutes', ''));
        } elseif ($scope === 'mine' && ! $seesEverybody) {
            $query->forPerson($person->id);
        } elseif ($scope === '') {
            $query->whereIn('status', [Meeting::STATUS_SCHEDULED, Meeting::STATUS_HELD]);
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('agenda', 'like', "%{$search}%");
            });
        }

        // Upcoming first (soonest at the top), then what has already happened
        // (most recent first) — the order somebody actually reads a diary in.
        return $query
            ->withCount('attendees')
            ->orderByRaw('case when starts_at >= ? then 0 else 1 end', [now()])
            ->orderByRaw('case when starts_at >= ? then starts_at end asc', [now()])
            ->orderByRaw('case when starts_at < ? then starts_at end desc', [now()])
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();
    }

    /**
     * The minutes register: what has been written up, and — the column that
     * matters — what has not.
     *
     * @param  array{state?: string, per_page?: int}  $filters
     */
    public function minutesRegister(array $filters, User $person): LengthAwarePaginator
    {
        $query = $this->visible($person)->held()->with(['minuteTaker', 'attendees']);

        $state = (string) ($filters['state'] ?? '');

        if ($state === 'recorded') {
            $query->whereNotNull('minutes')->where('minutes', '!=', '');
        } elseif ($state === 'pending') {
            $query->where(fn (Builder $q) => $q->whereNull('minutes')->orWhere('minutes', ''));
        }

        return $query->orderByDesc('starts_at')->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
    }

    /**
     * @return array<string, int>
     */
    public function summary(User $person, bool $seesEverybody): array
    {
        $mine = $this->visible($person);

        $all = (clone $mine)->get();

        $counts = [
            'upcoming' => 0,
            'today' => 0,
            'awaiting_minutes' => 0,
            'cancelled' => 0,
            'attended' => 0,
            'action_items' => 0,
            'action_overdue' => 0,
        ];

        foreach ($all as $meeting) {
            if ($meeting->status === Meeting::STATUS_SCHEDULED && $meeting->starts_at->isFuture()) {
                $counts['upcoming']++;
            }

            if ($meeting->status === Meeting::STATUS_SCHEDULED && $meeting->isToday()) {
                $counts['today']++;
            }

            if ($meeting->needsMinutes()) {
                $counts['awaiting_minutes']++;
            }

            if ($meeting->status === Meeting::STATUS_CANCELLED) {
                $counts['cancelled']++;
            }

            if ($meeting->status === Meeting::STATUS_HELD) {
                $counts['attended']++;
            }
        }

        $items = $this->actionItems($person, $seesEverybody);

        $counts['action_items'] = $items->count();
        $counts['action_overdue'] = $items->filter(fn (Task $task) => $task->isOverdue())->count();

        return $counts;
    }

    /** A person sees the meetings they are on; a manager sees the office's. */
    public function visible(User $person): Builder
    {
        $query = Meeting::query()->where('company_id', $person->company_id);

        if (! $person->can('business.meetings.manage')) {
            $query->where(function (Builder $q) use ($person) {
                $q->whereHas('attendees', fn ($a) => $a->where('user_id', $person->id))
                    ->orWhere('chaired_by', $person->id)
                    ->orWhere('scheduled_by', $person->id);
            });
        }

        $ids = $this->context->accessibleBranchIds();

        if ($ids !== null) {
            $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids)->orWhereNull('branch_id'));
        }

        return $query;
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * The clash check, in words a person can act on: who is already booked, and
     * for what. Overlapping *deliberately* is allowed — an unavoidable clash is a
     * decision — but it has to be taken knowingly.
     *
     * @param  array<int, int>  $ids
     */
    public function clashes(array $ids, Carbon $startsAt, ?Carbon $endsAt, ?int $ignoreMeetingId = null): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $endsAt ??= $startsAt->copy()->addMinutes(Meeting::DEFAULT_MINUTES);

        return Meeting::query()
            ->where('status', Meeting::STATUS_SCHEDULED)
            ->when($ignoreMeetingId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreMeetingId))
            ->whereBetween('starts_at', [$startsAt->copy()->subDay(), $endsAt->copy()->addDay()])
            ->whereHas('attendees', fn (Builder $q) => $q->whereIn('user_id', $ids))
            ->with(['attendees.user'])
            ->get()
            ->filter(fn (Meeting $meeting) => $meeting->starts_at->lt($endsAt) && $meeting->endsAt()->gt($startsAt))
            ->values();
    }

    /** @param  array<int, int>  $ids */
    private function assertNoClash(array $ids, Carbon $startsAt, ?Carbon $endsAt): void
    {
        $clashes = $this->clashes($ids, $startsAt, $endsAt);

        if ($clashes->isEmpty()) {
            return;
        }

        $already = [];

        foreach ($clashes as $clash) {
            foreach ($clash->attendees as $attendee) {
                if ($attendee->user_id !== null && in_array((int) $attendee->user_id, $ids, true)) {
                    $already[$attendee->displayName()] = sprintf(
                        '%s (%s–%s)',
                        $clash->title,
                        $clash->starts_at->format('H:i'),
                        $clash->endsAt()->format('H:i'),
                    );
                }
            }
        }

        $names = collect($already)
            ->map(fn (string $meeting, string $who) => $who.' is in '.$meeting)
            ->take(3)
            ->implode('; ');

        throw ValidationException::withMessages([
            'starts_at' => 'That slot is already taken: '.$names.
                '. Move the time, or tick “schedule anyway” to record the overlap on purpose.',
        ]);
    }

    private function addAttendeeRow(Meeting $meeting, ?int $userId, ?string $name, string $role): MeetingAttendee
    {
        if (! array_key_exists($role, Meeting::ROLES)) {
            throw ValidationException::withMessages([
                'role' => 'A person on the list is the chair, the minute-taker, or an attendee.',
            ]);
        }

        return MeetingAttendee::firstOrCreate(
            ['meeting_id' => $meeting->id, 'user_id' => $userId],
            [
                'company_id' => $meeting->company_id,
                'name' => $userId === null ? ($name ?: 'Unnamed attendee') : null,
                'role' => $role,
                'response' => 'pending',
                'invited_at' => now(),
            ],
        );
    }

    /** @return array<int, int> */
    private function attendeeIds(array $attendees): array
    {
        return collect($attendees)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function inviteEveryone(Meeting $meeting, User $actor): void
    {
        foreach ($meeting->attendees as $attendee) {
            if ($attendee->user === null || $attendee->user_id === $actor->id) {
                continue; // the person who called it knows about it
            }

            $this->notify($meeting, $attendee->user, 'meeting.invited', 'Meeting invitation', sprintf(
                '%s — %s%s',
                $meeting->title,
                $meeting->starts_at->format('d M Y H:i'),
                $meeting->location ? ' at '.$meeting->location : '',
            ), ':invited:'.$attendee->user_id);
        }
    }

    /** Tell everybody on the list something about this meeting, once each. */
    private function announce(Meeting $meeting, string $event, string $title, string $body, string $suffix): void
    {
        foreach ($meeting->attendees as $attendee) {
            if ($attendee->user !== null) {
                $this->notify($meeting, $attendee->user, $event, $title, $body, $suffix);
            }
        }
    }

    private function notify(Meeting $meeting, User $person, string $event, string $title, string $body, string $suffix): void
    {
        $this->notifications->notify($person, $event, $title, $body, [
            'priority' => $meeting->isToday() ? 'high' : 'normal',
            'action_url' => route('meetings.show', $meeting, false),
            'data' => ['meeting_id' => $meeting->id, 'starts_at' => $meeting->starts_at->toDateTimeString()],
            'dedupe_key' => 'meeting:'.$meeting->id.$suffix,
        ]);
    }

    private function guardEditable(Meeting $meeting, string $action): void
    {
        if ($meeting->status === Meeting::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'meeting' => 'This meeting was cancelled'.($meeting->cancelled_reason ? ' ('.$meeting->cancelled_reason.')' : '').
                    ', so it cannot be '.$action.'. Schedule a new one if the conversation is back on.',
            ]);
        }
    }

    private function history(Meeting $meeting, string $action, User $actor, string $note, array $meta = []): MeetingEvent
    {
        return MeetingEvent::create([
            'company_id' => $meeting->company_id,
            'meeting_id' => $meeting->id,
            'action' => $action,
            'happened_at' => now(),
            'note' => $note,
            'meta' => $meta ?: null,
            'actor_id' => $actor->id,
        ]);
    }
}
