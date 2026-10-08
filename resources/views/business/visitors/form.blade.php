@php
    /* §12-16 — one form, two doors: book somebody in, or check them in now. */
    $booking = $mode === 'book';
    $action = $booking ? route('business.visitors.store') : route('business.visitors.walkin.store');
    $backTo = $booking ? route('business.visitors.expected') : route('business.visitors.index');
@endphp

<x-ui.page-header
    :eyebrow="'Business Management · Visitors · '.($booking ? 'Pre-registration' : 'Check in')"
    :title="$booking ? 'Book somebody in before they arrive' : 'Somebody is at the gate'"
    :subtitle="$booking
        ? 'A booking tells the gate who to expect, who they are coming to see and what the visit is for. No badge is issued and no time is recorded until the person is actually standing here — which is exactly why the desk can answer “who is inside?” without hesitating.'
        : 'Checking somebody in writes the time, issues the day\'s next badge and tells the host once. If they were booked in beforehand the booking is what gets filled in, so their history stays on one row.'"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ $backTo }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ $action }}">
    @csrf

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Who is coming</h2>
                <p class="erp-card-sub">Somebody already on the register keeps their history; a new name is added to it.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="visitor_id">Visitor on file</label>
                <select class="form-select @error('visitor_id') is-invalid @enderror" name="visitor_id" id="visitor_id">
                    <option value="">— somebody new —</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((int) old('visitor_id', $preselected) === (int) $person->id)>
                            {{ $person->name }}{{ $person->organisation ? ' · '.$person->organisation : '' }}{{ $person->phone ? ' · '.$person->phone : '' }}{{ $person->is_blacklisted ? ' (blacklisted)' : '' }}
                        </option>
                    @endforeach
                </select>
                @error('visitor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <p class="erp-note erp-note-info mt-2 mb-0">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>Leave this empty and fill the name below when the gate is meeting somebody for the first time. Somebody on the blacklist is refused by the desk, whatever this form says.</span>
                </p>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" type="text" name="name" id="name"
                       value="{{ old('name') }}" placeholder="As it appears on the paper">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-3">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" type="text" name="phone" id="phone" value="{{ old('phone') }}" placeholder="01XXXXXXXXX">
                <div class="form-text">How the gate announces them, and how the next visit finds this person again.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="organisation">Organisation</label>
                <input class="form-control" type="text" name="organisation" id="organisation" value="{{ old('organisation') }}" placeholder="Who they represent, if anyone">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" type="email" name="email" id="email" value="{{ old('email') }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-2">
                <label class="form-label" for="id_type">Paper shown</label>
                <select class="form-select" name="id_type" id="id_type">
                    <option value="">— none recorded —</option>
                    @foreach (\App\Domain\Business\Visitor::ID_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('id_type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label" for="id_number">Number</label>
                <input class="form-control" type="text" name="id_number" id="id_number" value="{{ old('id_number') }}">
                <div class="form-text">Masked on the register.</div>
            </div>
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Why they are here</h2>
                <p class="erp-card-sub">{{ $booking ? 'The day, the time and the person expecting them.' : 'The gate records the purpose and who to tell that they have arrived.' }}</p>
            </div>
        </div>

        <div class="row g-3">
            @if ($booking)
                <div class="col-md-4">
                    <label class="form-label" for="scheduled_for">Day and time</label>
                    <input class="form-control @error('scheduled_for') is-invalid @enderror" type="datetime-local" name="scheduled_for" id="scheduled_for"
                           value="{{ old('scheduled_for', now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')) }}">
                    @error('scheduled_for')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Bookings are taken up to {{ \App\Domain\Business\VisitorVisit::BOOKING_HORIZON_DAYS }} days ahead.</div>
                </div>
            @endif

            <div class="col-md-4">
                <label class="form-label" for="host_user_id">Here to see</label>
                <select class="form-select @error('host_user_id') is-invalid @enderror" name="host_user_id" id="host_user_id">
                    <option value="">— nobody in particular —</option>
                    @foreach ($hosts as $host)
                        <option value="{{ $host->id }}" @selected((int) old('host_user_id') === (int) $host->id)>{{ $host->name }}</option>
                    @endforeach
                </select>
                @error('host_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">The host hears about it once, the moment the visitor is at the gate.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="branch_id">Gate</label>
                <select class="form-select @error('branch_id') is-invalid @enderror" name="branch_id" id="branch_id">
                    <option value="">Company-wide</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id') === (int) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">A gate is a place: somebody scoped to a branch books at their own.</div>
            </div>

            <div class="col-12">
                <span class="form-label d-block">Purpose</span>
                <div class="row g-2">
                    @foreach ($purposes as $key => $purpose)
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="purpose" id="purpose_{{ $key }}"
                                       value="{{ $key }}" @checked(old('purpose', 'meeting') === $key)>
                                <label class="form-check-label" for="purpose_{{ $key }}">
                                    <strong><i class="bi {{ $purpose['icon'] }} me-1" aria-hidden="true"></i>{{ $purpose['label'] }}</strong>
                                    <small class="erp-td-muted d-block">{{ $purpose['blurb'] }}</small>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('purpose')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-4">
                <label class="form-label" for="meet_at">Where they wait</label>
                <input class="form-control" type="text" name="meet_at" id="meet_at" value="{{ old('meet_at') }}" placeholder="Reception, 3rd floor">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="items_carried">Bringing in</label>
                <input class="form-control" type="text" name="items_carried" id="items_carried" value="{{ old('items_carried') }}" placeholder="Laptop, sample box, tools">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="vehicle_no">Vehicle</label>
                <input class="form-control" type="text" name="vehicle_no" id="vehicle_no" value="{{ old('vehicle_no') }}" placeholder="DHA-GA-00-0000">
            </div>

            <div class="col-12">
                <label class="form-label" for="notes">Notes for the gate</label>
                <textarea class="form-control" name="notes" id="notes" rows="3" maxlength="500">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit"
                data-confirm="{{ $booking ? 'Book this visit?' : 'Check this visitor in now?' }}">
            <i class="bi {{ $booking ? 'bi-calendar-plus' : 'bi-box-arrow-in-right' }}" aria-hidden="true"></i>
            {{ $booking ? 'Book the visit' : 'Check in now' }}
        </button>
        <a class="btn btn-link" href="{{ $backTo }}">Cancel</a>
    </div>
</form>

<x-ui.related-pages />
