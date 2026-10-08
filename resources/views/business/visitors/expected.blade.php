@php
    /* §12-16 — the pre-registration diary: who is coming, and when. */
    $dayLabel = fn (\Carbon\Carbon $day): string => $day->isToday()
        ? 'Today, '.$day->format('d M')
        : ($day->isTomorrow() ? 'Tomorrow, '.$day->format('d M') : $day->format('l, d M'));
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors · Pre-registration"
    title="The gate diary"
    subtitle="Everything the front desk has been told to expect, day by day. A booking is not an arrival: nothing here has a badge and none of it counts as inside until somebody is actually at the door — and a booking whose day has gone by is a no-show to be closed, not a row to be quietly forgotten."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('business.visitors.create') }}">
                <i class="bi bi-calendar-plus" aria-hidden="true"></i> Book a visit
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.index') }}">
            <i class="bi bi-people" aria-hidden="true"></i> The log
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Expected today" :value="$summary['expected_today']" icon="bi-calendar-check" hero
              hint="Booked and not yet arrived" />
    <x-ui.kpi label="Booked tomorrow" :value="$diary['tomorrow']->count()" icon="bi-calendar-event" hint="Somebody has already been told to expect them" />
    <x-ui.kpi label="Later this week" :value="$diary['rest']->sum(fn ($rows) => $rows->count())" icon="bi-calendar-week" hint="The rest of the seven-day diary" />
    <x-ui.kpi label="Missed bookings" :value="$missed->count()" icon="bi-person-dash"
              :hint="$missed->count() > 0 ? 'Their day has passed — close them as no-shows' : 'Nothing has been left open'"
              :href="route('business.visitors.index', ['status' => 'expected'])" />
    <x-ui.kpi label="Inside right now" :value="$summary['inside']" icon="bi-person-check" hint="Read from the log, not from this diary" />
    <x-ui.kpi label="Longest stay open" :value="$summary['longest_open'].'m'" icon="bi-hourglass-split" hint="A visitor still checked in from an earlier day" />
</div>

@if ($missed->isNotEmpty())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <div>
            <strong>{{ $missed->count() }} booking(s) went past their day without anybody arriving.</strong>
            Marking them is not bookkeeping for its own sake — it is what stops the diary from promising a visitor who came last Tuesday.
            Close them below.
        </div>
    </div>
@endif

@foreach ([
    ['key' => 'today', 'day' => \Carbon\Carbon::today()],
    ['key' => 'tomorrow', 'day' => \Carbon\Carbon::tomorrow()],
] as $column)
    <section class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">{{ $dayLabel($column['day']) }} <span class="erp-chip erp-chip-outline">{{ $diary[$column['key']]->count() }}</span></h2>
                <p class="erp-card-sub">
                    {{ $column['key'] === 'today'
                        ? 'Whoever is at the door is checked in from the log; these are the people the desk is waiting for.'
                        : 'Known a day ahead, so the hosts can be ready and the gate is not surprised.' }}
                </p>
            </div>
        </div>

        <div class="px-3 pb-2">
            @forelse ($diary[$column['key']] as $visit)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('business.visitors.show', $visit) }}">{{ $visit->visitor?->name }}</a>
                        <div class="erp-td-muted">
                            <i class="bi {{ \App\Domain\Business\VisitorRegistry::icon($visit->purpose) }} me-1" aria-hidden="true"></i>{{ $visit->purposeLabel() }}
                            · {{ $visit->scheduled_for?->format('H:i') }}
                            @if ($visit->host?->name)
                                · to see {{ $visit->host->name }}
                            @endif
                            @if ($visit->meet_at)
                                · {{ $visit->meet_at }}
                            @endif
                            @if ($visit->visitor?->organisation)
                                · {{ $visit->visitor->organisation }}
                            @endif
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if ($visit->visitor?->isBlacklisted())
                            <span class="erp-chip erp-chip-danger">Blacklisted</span>
                        @endif
                        @if ($canManage && $visit->scheduled_for?->isToday())
                            <form method="POST" action="{{ route('business.visitors.check-in', $visit) }}" data-confirm="Check {{ $visit->visitor?->name }} in now?">
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit">They are here</button>
                            </form>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.show', $visit) }}">Open</a>
                    </div>
                </div>
            @empty
                <p class="erp-td-muted px-1 py-2 mb-0">
                    {{ $column['key'] === 'today' ? 'Nobody is expected today — the gate is open to walk-ins.' : 'Nothing booked for tomorrow yet.' }}
                </p>
            @endforelse
        </div>
    </section>
@endforeach

@if ($diary['rest']->isNotEmpty())
    <x-ui.table-shell title="Later this week" :count="$diary['rest']->sum(fn ($rows) => $rows->count()).' booking(s)'">
        <thead>
            <tr><th>Day</th><th>Time</th><th>Visitor</th><th>Purpose</th><th>Host</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($diary['rest'] as $date => $rows)
                @foreach ($rows as $visit)
                    <tr>
                        <td data-label="Day">{{ \Carbon\Carbon::parse($date)->format('D, d M') }}</td>
                        <td data-label="Time">{{ $visit->scheduled_for?->format('H:i') }}</td>
                        <td data-label="Visitor">
                            <a class="erp-cell-strong" href="{{ route('business.visitors.show', $visit) }}">{{ $visit->visitor?->name }}</a>
                        </td>
                        <td data-label="Purpose">{{ $visit->purposeLabel() }}</td>
                        <td data-label="Host">{{ $visit->host?->name ?? '—' }}</td>
                        <td class="erp-td-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.show', $visit) }}">Open</a></td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </x-ui.table-shell>
@endif

@if ($missed->isNotEmpty())
    <x-ui.table-shell title="Bookings whose day has passed" :count="$missed->count().' booking(s)'">
        <thead>
            <tr><th>Day it was for</th><th>Visitor</th><th>Host</th><th>Purpose</th><th></th></tr>
        </thead>
        <tbody>
            @foreach ($missed as $visit)
                <tr>
                    <td data-label="Day it was for">{{ $visit->scheduled_for?->format('d M Y · H:i') }}</td>
                    <td data-label="Visitor">
                        <a class="erp-cell-strong" href="{{ route('business.visitors.show', $visit) }}">{{ $visit->visitor?->name }}</a>
                        <div class="erp-td-muted">{{ $visit->visitor?->phone ?? 'No phone on file' }}</div>
                    </td>
                    <td data-label="Host">{{ $visit->host?->name ?? '—' }}</td>
                    <td data-label="Purpose">{{ $visit->purposeLabel() }}</td>
                    <td class="erp-td-actions">
                        @if ($canManage)
                            <form method="POST" action="{{ route('business.visitors.no-show', $visit) }}" data-confirm="Record that {{ $visit->visitor?->name }} never arrived?">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Never arrived</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table-shell>
@endif

<x-ui.related-pages />
