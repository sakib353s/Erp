@php
    /* §12-11 — one meeting: the agenda, the answers, who came, what was said, and
       the work that came out of it. Everything that changes the meeting is on
       this page, in the order the meeting actually happens: it is held, people
       are marked, the minutes are written, the action items are raised. */
    $held = $meeting->status === \App\Domain\Business\Meeting::STATUS_HELD;
    $cancelled = $meeting->status === \App\Domain\Business\Meeting::STATUS_CANCELLED;
    $scheduled = $meeting->status === \App\Domain\Business\Meeting::STATUS_SCHEDULED;

    $bannerTone = match ($meeting->status) {
        'cancelled' => 'erp-note-danger',
        'held' => 'erp-note-ok',
        default => $meeting->isToday() ? 'erp-note-warn' : 'erp-note-info',
    };

    $when = $meeting->starts_at->format('d M Y, H:i').'–'.$meeting->endsAt()->format('H:i');
@endphp

<x-ui.page-header
    eyebrow="Business Management · Meetings"
    title="{{ $meeting->title }}"
    subtitle="{{ $when }}{{ $meeting->location ? ' · '.$meeting->location : '' }}{{ $meeting->chair ? ' · chaired by '.$meeting->chair->name : '' }}"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The diary
        </a>
        @if ($canManage && ! $cancelled)
            <a class="btn btn-outline-secondary" href="#minutes">
                <i class="bi bi-pencil" aria-hidden="true"></i> Minutes
            </a>
        @endif
        @if ($canManage && $held)
            <a class="btn btn-outline-secondary" href="#action-items">
                <i class="bi bi-list-check" aria-hidden="true"></i> Action items
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-note {{ $bannerTone }} mb-3">
    <i class="bi bi-{{ $cancelled ? 'x-octagon' : ($held ? 'check2-circle' : 'calendar-event') }}" aria-hidden="true"></i>
    <div>
        <div class="mb-1"><x-ui.status :value="$meeting->status" :label="$meeting->statusLabel()" /></div>
        <div>
            @if ($cancelled)
                Cancelled{{ $meeting->cancelled_at ? ' on '.$meeting->cancelled_at->format('d M Y') : '' }}{{ $meeting->cancelled_reason ? ': '.$meeting->cancelled_reason : '.' }}
                The meeting stays on the record with the reason — that is what tells five people whether it is happening next week.
            @elseif ($held)
                Held{{ $meeting->held_at ? ' on '.$meeting->held_at->format('d M Y') : '' }}{{ $meeting->chaired_by ? ', chaired by '.$meeting->chair?->name : '' }}.
                @if ($meeting->hasMinutes())
                    The minutes are on file{{ $meeting->minutes_recorded_at ? ' ('.$meeting->minutes_recorded_at->format('d M Y').')' : '' }}.
                @else
                    Nobody has written the minutes yet — the record of what was decided is the only part of a meeting that outlives it.
                @endif
            @else
                Scheduled for {{ $when }}. {{ $meeting->attendedCount() }} of {{ $meeting->attendees->count() }} person(s) have answered.
                @if ($meeting->starts_at->isPast())
                    The time has passed and nobody marked it held — either it happened (mark it) or it did not (move or cancel it).
                @endif
            @endif
        </div>
    </div>
</div>

<div class="erp-split">
    <div class="erp-split-main">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">The agenda</h2>
                @if ($meeting->branch)
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">{{ $meeting->branch->name }}</span></div>
                @endif
            </header>
            <div class="px-3 pb-3">
                @if ($meeting->agenda)
                    <p class="erp-pre">{{ $meeting->agenda }}</p>
                @else
                    <x-ui.empty title="No agenda written" text="A meeting with no agenda is a meeting people come to unprepared — and the minutes then have nothing to measure against." icon="bi-list-check" />
                @endif
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Who is on the list</h2>
                    <p class="erp-card-sub">Invited, answered, and — once the meeting is over — who was actually there. An apology is not an absence.</p>
                </div>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $meeting->attendees->count() }} person(s)</span>
                </div>
            </header>

            @if ($canManage && ! $cancelled)
                <form class="p-3 pt-2 pb-0" method="POST" action="{{ route('meetings.attendance', $meeting) }}">
                    @csrf
                    <div class="erp-table-scroll">
                        <table class="table erp-table erp-table-compact">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Role</th>
                                    <th>Answer</th>
                                    <th>Attendance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($meeting->attendees as $attendee)
                                    <tr>
                                        <td>
                                            <span class="erp-cell-strong">{{ $attendee->displayName() }}</span>
                                            @if ($attendee->isExternal())
                                                <div class="erp-td-muted">not a user of this company</div>
                                            @endif
                                        </td>
                                        <td class="erp-td-muted">{{ $attendee->roleLabel() }}</td>
                                        <td>
                                            <span class="erp-chip {{ $attendee->response === 'accepted' ? 'erp-chip-soft' : ($attendee->response === 'declined' ? 'erp-chip-outline' : 'erp-chip-warn') }}">
                                                {{ $attendee->responseLabel() }}
                                            </span>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" name="attendance[{{ $attendee->id }}]"
                                                    @disabled($scheduled)>
                                                <option value="">Not marked</option>
                                                @foreach (\App\Domain\Business\Meeting::ATTENDANCE as $key => $label)
                                                    <option value="{{ $key }}" @selected($attendee->attendance === $key)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($scheduled)
                        <div class="erp-filter-note mb-2">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            Attendance is recorded after the meeting — mark it held first, then say who came.
                        </div>
                    @else
                        <button class="btn btn-outline-secondary mb-2" type="submit">
                            <i class="bi bi-check2-square" aria-hidden="true"></i> Save attendance
                        </button>
                    @endif
                </form>
            @else
                <div class="px-3 pb-3">
                    @foreach ($meeting->attendees as $attendee)
                        <div class="erp-list-row">
                            <div class="erp-list-row-main">
                                <span class="erp-cell-strong">{{ $attendee->displayName() }}</span>
                                <div class="erp-td-muted">{{ $attendee->roleLabel() }} · {{ $attendee->responseLabel() }}</div>
                            </div>
                            @if ($attendee->attendance)
                                <x-ui.status :value="$attendee->attendance" :label="$attendee->attendanceLabel()" />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($canManage && ! $cancelled)
                @php($offList = $people->whereNotIn('id', $alreadyOnList))
                @if ($offList->isNotEmpty())
                    <form class="p-3 pt-0 erp-inline-form" method="POST" action="{{ route('meetings.attendee', $meeting) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label" for="user_id">Add somebody</label>
                                <select class="form-select" name="user_id" id="user_id">
                                    @foreach ($offList as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="role">As</label>
                                <select class="form-select" name="role" id="role">
                                    @foreach (\App\Domain\Business\Meeting::ROLES as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary w-100" type="submit">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add
                                </button>
                            </div>
                        </div>
                        <div class="erp-filter-note mt-2">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            Somebody who is not a user — the auditor, the landlord — can be added with a name instead.
                        </div>
                    </form>
                @endif
            @endif
        </section>

        <section class="erp-card mt-3" id="minutes">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The minutes</h2>
                    <p class="erp-card-sub">
                        @if ($meeting->minutes_recorded_at)
                            Written by {{ $meeting->minuteTaker?->name ?? 'somebody since removed' }} on {{ $meeting->minutes_recorded_at->format('d M Y') }}.
                        @else
                            What was said, what was decided, and who took it away — the only part of a meeting that survives it.
                        @endif
                    </p>
                </div>
            </header>
            <div class="px-3 pb-3">
                @if ($meeting->hasMinutes())
                    <p class="erp-pre">{{ $meeting->minutes }}</p>
                @elseif ($scheduled)
                    <x-ui.empty
                        title="No minutes — the meeting has not been held"
                        text="Minutes are the record of what happened; a diary note is not a minute. Mark the meeting held when it has happened."
                        icon="bi-pencil" />
                @else
                    <x-ui.empty
                        title="Nothing written down yet"
                        text="This meeting has been held and nobody has written it up. Until somebody does, the only record of what was decided is in people's heads."
                        icon="bi-pencil" />
                @endif

                @if ($canManage && ! $scheduled && ! $cancelled)
                    <form class="erp-inline-form mt-3" method="POST" action="{{ route('meetings.recordMinutes', $meeting) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label" for="minutes">{{ $meeting->hasMinutes() ? 'Rewrite the minutes' : 'Write the minutes' }}</label>
                            <textarea class="form-control @error('minutes') is-invalid @enderror" name="minutes" id="minutes"
                                      rows="6" maxlength="20000"
                                      placeholder="Present: …&#10;1. Stock review — decision taken, by whom.&#10;2. …">{{ old('minutes') }}</textarea>
                            @error('minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Everybody on the list is told once when the minutes go on file.</div>
                        </div>
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-journal-check" aria-hidden="true"></i> Put it on file
                        </button>
                    </form>
                @endif
            </div>
        </section>

        <section class="erp-card mt-3" id="action-items">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What came out of it</h2>
                    <p class="erp-card-sub">An action item is a task with a link back to this meeting — the same work on the task board, with the same states, dates and notifications as anything else.</p>
                </div>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $meeting->actionItems->count() }} item(s)</span>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($meeting->actionItems as $item)
                    <div class="erp-list-row">
                        <div class="erp-list-row-main">
                            <a class="erp-cell-strong" href="{{ route('tasks.show', $item) }}">{{ $item->title }}</a>
                            <div class="erp-td-muted">
                                {{ $item->assignee?->name ?? 'Unassigned' }}
                                @if ($item->due_at)
                                    · due {{ $item->due_at->format('d M Y H:i') }}
                                @endif
                                @if ($item->isOverdue())
                                    <span class="erp-chip erp-chip-danger ms-1">overdue</span>
                                @endif
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <x-ui.status :value="$item->status" :label="$item->statusLabel()" />
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tasks.show', $item) }}">Open task</a>
                        </div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">
                        No action items yet.
                        @if ($scheduled) They come out of a meeting that has happened. @endif
                    </p>
                @endforelse

                @if ($canManage && ! $scheduled && ! $cancelled)
                    <form class="erp-inline-form mt-3" method="POST" action="{{ route('meetings.actionItem', $meeting) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label" for="action_title">What has to be done <span class="text-danger">*</span></label>
                                <input class="form-control @error('title') is-invalid @enderror" type="text" name="title"
                                       id="action_title" maxlength="191" required placeholder="Recount aisle four before the audit">
                                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="assigned_to">Who owns it</label>
                                <select class="form-select" name="assigned_to" id="assigned_to">
                                    <option value="">Nobody yet</option>
                                    @foreach ($meeting->attendees as $attendee)
                                        @if ($attendee->user)
                                            <option value="{{ $attendee->user->id }}">{{ $attendee->displayName() }}</option>
                                        @endif
                                    @endforeach
                                    @foreach ($people->whereNotIn('id', $alreadyOnList) as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="due_at">Due</label>
                                <input class="form-control" type="datetime-local" name="due_at" id="due_at">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="priority">Priority</label>
                                <select class="form-select" name="priority" id="priority">
                                    @foreach (\App\Domain\Business\Task::PRIORITIES as $key => $label)
                                        <option value="{{ $key }}" @selected($key === 'normal')>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="action_description">Detail</label>
                                <textarea class="form-control" name="description" id="action_description" rows="2" maxlength="2000"
                                          placeholder="What the meeting agreed, in the words it was agreed in."></textarea>
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary mt-2" type="submit">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Raise it as a task
                        </button>
                    </form>
                @endif
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What has happened to this meeting</h2>
                    <p class="erp-card-sub">Scheduled, moved, held, cancelled, minuted — with the reasons people gave at the time.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($meeting->events as $event)
                    <div class="erp-list-row erp-list-row-top">
                        <div class="erp-list-row-main">
                            <span class="erp-chip {{ $event->action === 'cancelled' ? 'erp-chip-danger' : ($event->action === 'held' ? 'erp-chip-soft' : 'erp-chip-outline') }}">
                                {{ $event->actionLabel() }}
                            </span>
                            <div>{{ $event->note }}</div>
                        </div>
                        <div class="erp-td-muted text-nowrap">
                            {{ $event->happened_at->format('d M Y H:i') }}
                            @if ($event->actor)
                                <div>{{ $event->actor->name }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">Nothing recorded yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="erp-split-side">
        @if ($mine && ! $cancelled)
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Your answer</h2>
                        <p class="erp-card-sub">Right now: <strong>{{ $mine->responseLabel() }}</strong>.</p>
                    </div>
                </header>
                <div class="p-3 pt-0 d-flex flex-wrap gap-2">
                    @foreach (['accepted' => 'I will be there', 'declined' => 'I cannot come'] as $key => $label)
                        <form method="POST" action="{{ route('meetings.respond', $meeting) }}">
                            @csrf
                            <input type="hidden" name="response" value="{{ $key }}">
                            <button class="btn {{ $mine->response === $key ? 'btn-primary' : 'btn-outline-secondary' }}" type="submit">
                                {{ $label }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($canManage && ! $cancelled)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Did it happen?</h2>
                        <p class="erp-card-sub">Holding it stamps the date and opens the attendance and minutes panels.</p>
                    </div>
                </header>
                <div class="p-3 pt-0">
                    @if ($scheduled)
                        <form method="POST" action="{{ route('meetings.hold', $meeting) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-check2" aria-hidden="true"></i> Mark it held
                            </button>
                        </form>
                    @else
                        <p class="erp-td-muted mb-0">Held{{ $meeting->held_at ? ' on '.$meeting->held_at->format('d M Y') : '' }}.</p>
                    @endif
                </div>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Move it</h2>
                        <p class="erp-card-sub">Everybody on the list is told the new time, and the calendar clash is checked again.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('meetings.reschedule', $meeting) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="re_starts_at">New start <span class="text-danger">*</span></label>
                        <input class="form-control @error('starts_at') is-invalid @enderror" type="datetime-local"
                               name="starts_at" id="re_starts_at" required
                               value="{{ $meeting->starts_at->format('Y-m-d\TH:i') }}">
                        @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="re_ends_at">New end</label>
                        <input class="form-control" type="datetime-local" name="ends_at" id="re_ends_at"
                               value="{{ $meeting->ends_at?->format('Y-m-d\TH:i') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="re_location">Place</label>
                        <input class="form-control" type="text" name="location" id="re_location"
                               value="{{ $meeting->location }}" maxlength="160">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="re_reason">Why the change</label>
                        <input class="form-control" type="text" name="reason" id="re_reason" maxlength="255"
                               placeholder="The auditor is only free on Wednesday">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="allow_clash" id="re_allow_clash" value="1">
                        <label class="form-check-label" for="re_allow_clash">Move it even if somebody is booked</label>
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Move it
                    </button>
                </form>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Call it off</h2>
                        <p class="erp-card-sub">A reason is required: five people have cleared that hour.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('meetings.cancel', $meeting) }}"
                      data-confirm="Cancel “{{ $meeting->title }}”? Everybody on the list is told, with the reason.">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="cancel_reason">Why</label>
                        <input class="form-control @error('reason') is-invalid @enderror" type="text" name="reason"
                               id="cancel_reason" maxlength="255" required placeholder="The stock take moved to Thursday">
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-outline-danger" type="submit">
                        <i class="bi bi-x-octagon" aria-hidden="true"></i> Cancel the meeting
                    </button>
                </form>
            </section>
        @endif
    </div>
</div>

<x-ui.related-pages />
