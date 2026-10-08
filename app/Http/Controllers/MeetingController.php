<?php

namespace App\Http\Controllers;

use App\Domain\Business\Meeting;
use App\Domain\Business\Services\MeetingService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Http\Requests\StoreMeetingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §12-11 — the meeting desk.
 *
 * Five screens: the diary, the scheduling form, one meeting (which is also where
 * it is held, minuted and answered), the minutes register, and the action items
 * every meeting has produced. The engine is {@see MeetingService}; what lives here
 * is scope and shape:
 *
 *  · a meeting from another company is a 404, and one this person is not on the
 *    list of is a 403 — being told a meeting exists that you were not invited to
 *    is itself information;
 *  · the diary is scoped for everybody (you see the meetings you are on) and
 *    whole-office for the people who run them (`business.meetings.manage`);
 *  · action items are read through the task board's own rules, because they *are*
 *    tasks: `tasks.view_all` decides whether you see the office's or only yours.
 */
class MeetingController extends Controller
{
    public function __construct(protected MeetingService $meetings) {}

    /** The diary: what is coming, what has happened, what still needs minutes. */
    public function index(Request $request): View
    {
        $person = $request->user();
        $manages = $this->manages($person);

        $filters = [
            'scope' => $request->string('scope')->toString(),
            'q' => $request->string('q')->toString(),
        ];

        return view('business.meetings.index', [
            'meetings' => $this->meetings->index($filters, $person, $manages),
            'filters' => $filters,
            'summary' => $this->meetings->summary($person, $manages),
            'canManage' => $manages,
            'seesEverybody' => $manages,
            'scope' => $filters['scope'],
        ]);
    }

    public function create(Request $request): View
    {
        return view('business.meetings.create', [
            'people' => $this->people($request->user()),
            'branches' => $this->branches($request->user()),
        ]);
    }

    public function store(StoreMeetingRequest $request): RedirectResponse
    {
        $meeting = $this->meetings->schedule(
            $request->meetingData(),
            $request->user(),
            $request->attendeeRows(),
        );

        return redirect()->route('meetings.show', $meeting)->with(
            'status',
            'Meeting called for '.$meeting->starts_at->format('d M Y H:i')
                .' with '.$meeting->attendees()->count().' person(s) on the list.',
        );
    }

    /** One meeting: the agenda, the answers, the attendance, the minutes, the work. */
    public function show(Request $request, Meeting $meeting): View
    {
        $person = $request->user();
        $this->guard($meeting, $person);

        $meeting->load(['attendees.user', 'events.actor', 'chair', 'scheduler', 'minuteTaker', 'branch', 'actionItems.assignee']);

        return view('business.meetings.show', [
            'meeting' => $meeting,
            'canManage' => $this->manages($person),
            'mine' => $meeting->attendees->firstWhere('user_id', $person->id),
            'people' => $this->people($person),
            'alreadyOnList' => $meeting->attendees->pluck('user_id')->filter()->all(),
            'canSeeEverybodyWork' => (bool) $person->can('tasks.view_all'),
        ]);
    }

    /** “I am coming” / “I cannot”. */
    public function respond(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user());

        $data = $request->validate([
            'response' => ['required', 'string', Rule::in(array_keys(Meeting::RESPONSES))],
        ]);

        $this->meetings->respond($meeting, $request->user(), $data['response']);

        return back()->with('status', 'Thank you — the list now says: '.Meeting::RESPONSES[$data['response']].'.');
    }

    /** It happened. */
    public function hold(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $this->meetings->hold($meeting, $request->user());

        return back()->with('status', 'Marked held. Mark who came, then write the minutes while it is fresh.');
    }

    public function reschedule(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:160'],
            'reason' => ['nullable', 'string', 'max:255'],
            'allow_clash' => ['nullable', 'boolean'],
        ]);

        $this->meetings->reschedule($meeting, $data + ['allow_clash' => $request->boolean('allow_clash')], $request->user());

        return back()->with('status', 'Moved to '.$meeting->fresh()->starts_at->format('d M Y H:i').'. Everybody on the list has been told.');
    }

    public function cancel(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            ['reason.required' => 'A cancelled meeting needs a reason — five people have cleared that hour, and “cancelled” alone does not tell them whether it is happening next week.'],
            ['reason' => 'reason'],
        );

        $this->meetings->cancel($meeting, $request->user(), $data['reason']);

        return back()->with('status', 'Cancelled. The meeting stays on the record with the reason.');
    }

    /** Who was in the room. */
    public function attendance(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate([
            'attendance' => ['nullable', 'array'],
            'attendance.*' => ['nullable', 'string', Rule::in(array_keys(Meeting::ATTENDANCE))],
        ]);

        $this->meetings->recordAttendance($meeting, (array) ($data['attendance'] ?? []), $request->user());

        return back()->with('status', 'Attendance recorded.');
    }

    /** What was said. */
    public function recordMinutes(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate([
            'minutes' => ['required', 'string', 'max:20000'],
        ]);

        $this->meetings->recordMinutes($meeting, $data['minutes'], $request->user());

        return back()->with('status', 'Minutes on file. Everybody on the list has been told they are available.');
    }

    /** Somebody else on the list — a person, or an outsider with a name. */
    public function attendee(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate([
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $meeting->company_id),
            ],
            'name' => ['nullable', 'string', 'max:160'],
            'role' => ['required', 'string', Rule::in(array_keys(Meeting::ROLES))],
        ]);

        if (($data['user_id'] ?? null) === null && trim((string) ($data['name'] ?? '')) === '') {
            return back()->withErrors(['name' => 'Name the person: somebody who is not a user still has to be called something on the list.']);
        }

        $user = isset($data['user_id']) && $data['user_id'] ? User::query()->find($data['user_id']) : null;

        $attendee = $this->meetings->addAttendee($meeting, $user, $data['name'] ?? null, $data['role'], $request->user());

        return back()->with('status', $attendee->wasRecentlyCreated
            ? $attendee->displayName().' added to the list.'
            : $attendee->displayName().' is already on the list.');
    }

    /** What the meeting decided, as work. */
    public function actionItem(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->guard($meeting, $request->user(), manage: true);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $meeting->company_id),
            ],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', Rule::in(array_keys(\App\Domain\Business\Task::PRIORITIES))],
        ]);

        $task = $this->meetings->raiseActionItem($meeting, $data, $request->user());

        return back()->with('status', sprintf(
            'Action item raised: “%s”. It is on the task board%s.',
            $task->title,
            $task->assignee ? ', assigned to '.$task->assignee->name : ' and unassigned so far',
        ));
    }

    /** The minutes register: written up, and still owing. */
    public function minutes(Request $request): View
    {
        $person = $request->user();

        $filters = ['state' => $request->string('state')->toString()];

        return view('business.meetings.minutes', [
            'meetings' => $this->meetings->minutesRegister($filters, $person),
            'filters' => $filters,
            'summary' => $this->meetings->summary($person, $this->manages($person)),
            'canManage' => $this->manages($person),
        ]);
    }

    /** Every action item the office has taken out of a meeting, overdue first. */
    public function actionItems(Request $request): View
    {
        $person = $request->user();
        $seesEverybody = (bool) $person->can('tasks.view_all');

        return view('business.meetings.actionItems', [
            'items' => $this->meetings->actionItems($person, $seesEverybody, $request->boolean('closed')),
            'summary' => $this->meetings->summary($person, $seesEverybody),
            'seesEverybody' => $seesEverybody,
            'includeClosed' => $request->boolean('closed'),
            'canManage' => $this->manages($person),
        ]);
    }

    /* --------------------------------------------------------------- helpers */

    private function manages(User $person): bool
    {
        return (bool) $person->can('business.meetings.manage');
    }

    /**
     * A meeting this person may look at: their own company's, and either one they
     * are on the list of or one they run.
     */
    private function guard(Meeting $meeting, User $person, bool $manage = false): void
    {
        abort_unless((int) $meeting->company_id === (int) $person->company_id, 404);

        if ($manage && ! $this->manages($person)) {
            abort(403);
        }

        if ($this->manages($person)) {
            return; // running the diary means seeing it
        }

        $onList = $meeting->attendees()->where('user_id', $person->id)->exists();

        abort_unless(
            $onList || (int) $meeting->chaired_by === $person->id || (int) $meeting->scheduled_by === $person->id,
            403,
            'You are not on this meeting’s list.',
        );
    }

    /** @return Collection<int, User> */
    private function people(User $person): Collection
    {
        return User::query()
            ->where('company_id', $person->company_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** @return Collection<int, Branch> */
    private function branches(User $person): Collection
    {
        $ids = $person->accessibleBranchIds();

        return Branch::query()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
