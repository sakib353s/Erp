@php
    /* §12-11 — calling a meeting. The clash check is the server's, and it names
       the people and the meeting they are already in; overriding it is a tick,
       so an unavoidable overlap is a decision rather than a surprise. */
    $defaultStart = now()->addDay()->setTime(10, 0);
@endphp

<x-ui.page-header
    eyebrow="Business Management · Meetings"
    title="Call a meeting"
    subtitle="Pick the people first: the diary refuses a slot that is already taken by somebody on the list, and says who. Everything else — the agenda, the place, the minute-taker — is what makes the meeting worth attending."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('meetings.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The diary
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('meetings.store') }}">
    @csrf
    <section class="erp-card">
        <header class="erp-card-head">
            <h2 class="erp-card-title">What and when</h2>
        </header>
        <div class="p-3 pt-2">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="title">Subject <span class="text-danger">*</span></label>
                    <input class="form-control @error('title') is-invalid @enderror" type="text" name="title" id="title"
                           value="{{ old('title') }}" maxlength="191" required
                           placeholder="Monthly stock review">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="location">Where</label>
                    <input class="form-control" type="text" name="location" id="location" value="{{ old('location') }}"
                           maxlength="160" placeholder="Board room, or a video link">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="starts_at">Starts <span class="text-danger">*</span></label>
                    <input class="form-control @error('starts_at') is-invalid @enderror" type="datetime-local"
                           name="starts_at" id="starts_at" required
                           value="{{ old('starts_at', $defaultStart->format('Y-m-d\TH:i')) }}">
                    @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="ends_at">Ends</label>
                    <input class="form-control @error('ends_at') is-invalid @enderror" type="datetime-local"
                           name="ends_at" id="ends_at" value="{{ old('ends_at', $defaultStart->copy()->addHour()->format('Y-m-d\TH:i')) }}">
                    @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="branch_id">Branch</label>
                    <select class="form-select" name="branch_id" id="branch_id">
                        <option value="">Company-wide</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="allow_clash" id="allow_clash" value="1"
                               @checked(old('allow_clash'))>
                        <label class="form-check-label" for="allow_clash">Schedule even if somebody is booked</label>
                        <div class="form-text">The overlap is then recorded on purpose.</div>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="agenda">Agenda</label>
                    <textarea class="form-control" name="agenda" id="agenda" rows="3" maxlength="4000"
                              placeholder="One line per item — what has to be decided, and by whom.">{{ old('agenda') }}</textarea>
                </div>
            </div>
        </div>
    </section>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Who is in the room</h2>
                <p class="erp-card-sub">The chair and the minute-taker are on the list like anybody else — they are in the room.</p>
            </div>
        </header>
        <div class="p-3 pt-2">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="chaired_by">Chaired by</label>
                    <select class="form-select" name="chaired_by" id="chaired_by">
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((string) old('chaired_by', auth()->id()) === (string) $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="secretary">Who takes the minutes</label>
                    <select class="form-select" name="secretary" id="secretary">
                        <option value="">Nobody yet</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((string) old('secretary') === (string) $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Everybody else</label>
                    <div class="erp-filter-note">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Hold <kbd>Ctrl</kbd> (or <kbd>⌘</kbd>) to pick several.
                    </div>
                </div>
            </div>

            <div class="row g-2 mt-2" style="max-height: 320px; overflow-y: auto;">
                @foreach ($people as $person)
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="attendees[]"
                                   id="attendee_{{ $person->id }}" value="{{ $person->id }}"
                                   @checked(in_array($person->id, old('attendees', []), false))>
                            <label class="form-check-label" for="attendee_{{ $person->id }}">
                                {{ $person->name }}
                                <span class="erp-td-muted">{{ $person->email }}</span>
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-calendar-plus" aria-hidden="true"></i> Call the meeting
        </button>
        <a class="btn btn-link" href="{{ route('meetings.index') }}">Cancel</a>
    </div>
</form>

<x-ui.related-pages />
