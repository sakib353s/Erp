@php
    /* §12-16 — the visitor log: today at the gate, and everybody in the book. */
    $today = \Carbon\Carbon::today();
    $stateLabels = [
        'expected' => 'Expected',
        'inside' => 'Inside',
        'out' => 'Checked out',
        'no_show' => 'No show',
        'cancelled' => 'Cancelled',
    ];
    $minutesLabel = fn (?int $minutes): string => $minutes === null
        ? '—'
        : ($minutes >= 60 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : $minutes.'m');
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors"
    title="Who is in the building, who is coming, and who has been"
    subtitle="A booking is not an arrival: pre-registering writes down who is expected, by whom and why, and issues no badge. The gate fills in when somebody actually walks through it — which is what makes “inside right now” a question the register can answer, and what makes the blacklist a decision rather than a suggestion."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('business.visitors.walkin') }}">
                <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Check somebody in
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('business.visitors.create') }}">
                <i class="bi bi-calendar-plus" aria-hidden="true"></i> Pre-register
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.expected') }}">
            <i class="bi bi-journal-text" aria-hidden="true"></i> Gate diary
            @if ($summary['expected_today'] > 0)
                <span class="erp-chip erp-chip-soft ms-1">{{ $summary['expected_today'] }} today</span>
            @endif
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.reports') }}">
            <i class="bi bi-bar-chart" aria-hidden="true"></i> Reports
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Inside now" :value="$summary['inside']" icon="bi-person-check" hero
              :hint="$summary['inside'] > 0 ? 'On the premises and not checked out' : 'Nobody is inside the building'"
              :href="route('business.visitors.index', ['status' => 'inside'])" />
    <x-ui.kpi label="Expected today" :value="$summary['expected_today']" icon="bi-calendar-check"
              hint="Booked in ahead of time and not yet arrived" :href="route('business.visitors.expected')" />
    <x-ui.kpi label="Arrived today" :value="$summary['arrived_today']" icon="bi-door-open"
              :hint="$summary['walk_ins_today'].' of them walked in without a booking'" />
    <x-ui.kpi label="Average stay this month" :value="$minutesLabel($summary['average_minutes'])" icon="bi-hourglass-split"
              hint="Checked-out visits only — read from the two timestamps, never stored" />
    <x-ui.kpi label="No-shows this month" :value="$summary['month_no_shows']" icon="bi-person-dash"
              :hint="$summary['month_cancelled'].' booking(s) were cancelled outright'" />
    <x-ui.kpi label="People on file" :value="$summary['on_file']" icon="bi-journal-person"
              :hint="$summary['blacklisted'].' of them are on the blacklist'" :href="route('business.visitors.people')" />
</div>

@if ($overstaying->isNotEmpty())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>{{ $overstaying->count() }} visitor(s) checked in before today and never checked out.</strong>
            A gate that forgets to close a visit stops meaning anything:
            {{ $overstaying->take(3)->map(fn ($visit) => ($visit->visitor?->name ?? 'visitor').' (badge '.$visit->badge_no.', in '.$visit->checked_in_at?->format('d M H:i').')')->implode(', ') }}{{ $overstaying->count() > 3 ? ', and '.($overstaying->count() - 3).' more' : '' }}.
            Close the ones that have gone home below.
        </div>
    </div>
@endif

@if ($inside->isNotEmpty())
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">On the premises now <span class="erp-chip erp-chip-outline">{{ $inside->count() }}</span></h2>
                <p class="erp-card-sub">Badge numbers are issued per company per day, so this list is what an evacuation drill would be checked against.</p>
            </div>
        </header>
        <div class="px-3 pb-2">
            @foreach ($inside as $visit)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('business.visitors.show', $visit) }}">{{ $visit->visitor?->name }}</a>
                        <div class="erp-td-muted">
                            <span class="erp-chip erp-chip-outline me-1">{{ $visit->badge_no }}</span>
                            {{ $visit->purposeLabel() }} · in at {{ $visit->checked_in_at?->format('H:i') }}
                            @if ($visit->host?->name)
                                · to see {{ $visit->host->name }}
                            @endif
                            · {{ $minutesLabel($visit->checked_in_at?->diffInMinutes(now(), false)) }} inside
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if ($visit->isOverstaying())
                            <span class="erp-chip erp-chip-warn">Over {{ \App\Domain\Business\VisitorVisit::OVERSTAY_HOURS }}h</span>
                        @endif
                        @if ($canManage)
                            <form method="POST" action="{{ route('business.visitors.check-out', $visit) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">
                                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Check out
                                </button>
                            </form>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.show', $visit) }}">Open</a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('business.visitors.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="status">State</label>
        <select class="form-select" name="status" id="status" data-erp-autosubmit>
            <option value="">Every state</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ $stateLabels[$status] ?? $status }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="purpose">Purpose</label>
        <select class="form-select" name="purpose" id="purpose" data-erp-autosubmit>
            <option value="">Every purpose</option>
            @foreach ($purposes as $key => $purpose)
                <option value="{{ $key }}" @selected(request('purpose') === $key)>{{ $purpose['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="branch">Gate</label>
        <select class="form-select" name="branch" id="branch" data-erp-autosubmit>
            <option value="">Every gate</option>
            @foreach ($branches as $option)
                <option value="{{ $option->id }}" @selected((string) request('branch') === (string) $option->id)>{{ $option->name }}</option>
            @endforeach
            <option value="0" @selected(request('branch') === '0')>Company-wide</option>
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="day">Day</label>
        <input class="form-control" type="date" name="day" id="day" value="{{ $day?->toDateString() }}">
    </div>
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ request('q') }}" placeholder="Name, phone, organisation, host or badge">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('business.visitors.index') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell
    title="{{ $day ? 'Visits on '.$day->format('d M Y') : 'Every visit on the register' }}"
    :count="$visits->total().' visit(s)'">
    <thead>
        <tr>
            <th>Visitor</th>
            <th>Purpose</th>
            <th>Host</th>
            <th>Expected</th>
            <th>In / out</th>
            <th>Badge</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($visits as $visit)
            <tr>
                <td data-label="Visitor">
                    <a class="erp-cell-strong" href="{{ route('business.visitors.show', $visit) }}">{{ $visit->visitor?->name ?? '—' }}</a>
                    <div class="erp-td-muted">
                        {{ $visit->visitor?->organisation ?? ($visit->visitor?->phone ?? 'No details on file') }}
                        @if ($visit->visitor?->isBlacklisted())
                            <span class="erp-chip erp-chip-danger ms-1">Blacklisted</span>
                        @endif
                    </div>
                </td>
                <td data-label="Purpose">
                    <i class="bi {{ \App\Domain\Business\VisitorRegistry::icon($visit->purpose) }} me-1" aria-hidden="true"></i>{{ $visit->purposeLabel() }}
                    @if ($visit->meet_at)
                        <div class="erp-td-muted">{{ $visit->meet_at }}</div>
                    @endif
                </td>
                <td data-label="Host">{{ $visit->host?->name ?? 'Nobody named' }}</td>
                <td data-label="Expected">
                    @if ($visit->scheduled_for)
                        {{ $visit->scheduled_for->format('d M Y') }}
                        <div class="erp-td-muted">{{ $visit->scheduled_for->format('H:i') }} — booked</div>
                    @else
                        <span class="erp-td-muted">Walk-in</span>
                    @endif
                </td>
                <td data-label="In / out">
                    {{ $visit->checked_in_at?->format('d M H:i') ?? '—' }}
                    <div class="erp-td-muted">
                        {{ $visit->checked_out_at ? 'out '.$visit->checked_out_at->format('d M H:i').' · '.$visit->dwellLabel() : ($visit->isInside() ? 'still inside' : 'not arrived') }}
                    </div>
                </td>
                <td data-label="Badge">{{ $visit->badge_no ?? '—' }}</td>
                <td data-label="State"><x-ui.status :value="$visit->status" :label="$visit->stateLabel()" /></td>
                <td class="erp-td-actions">
                    @if ($canManage && $visit->isExpected())
                        <form method="POST" action="{{ route('business.visitors.check-in', $visit) }}">
                            @csrf
                            <button class="btn btn-sm btn-primary" type="submit">Check in</button>
                        </form>
                    @elseif ($canManage && $visit->isInside())
                        <form method="POST" action="{{ route('business.visitors.check-out', $visit) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Check out</button>
                        </form>
                    @else
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.show', $visit) }}">Open</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nobody has been through the gate for this filter"
                        text="Pre-register the people you know are coming — the desk turns the booking into a badge at the door — or check somebody in as they arrive. Either way the row keeps the time in, the time out and the reason they were inside."
                        icon="bi-person-badge"
                        :action="$canManage ? 'Check somebody in' : null"
                        :href="$canManage ? route('business.visitors.walkin') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($visits->hasPages())
        <x-slot:footer>{{ $visits->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
