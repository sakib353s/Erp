@php
    /* §12-16 — the reports lens: the same rows, cut five ways. */
    $minutesLabel = fn (?int $minutes): string => $minutes === null
        ? '—'
        : ($minutes >= 60 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : $minutes.'m');
    $peak = $report['busiest_day'] !== null ? \Carbon\Carbon::parse($report['busiest_day']) : null;
    $share = fn (int $count): float => $report['visits'] > 0 ? round($count * 100 / $report['visits'], 1) : 0.0;
    $maxDay = ! empty($report['by_day']) ? max($report['by_day']) : 0;
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors · Reports"
    title="What the gate did, read five ways"
    subtitle="A visitor log is not kept for the visitors. It is kept to answer how many people came, at which gate, to see whom and for how long — and whether the people who keep coming back are the ones you want coming back. Every figure here is counted from the visits themselves over the window below, never from a stored total."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.index') }}"><i class="bi bi-people" aria-hidden="true"></i> The log</a>
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.people') }}"><i class="bi bi-journal-person" aria-hidden="true"></i> People on file</a>
    </x-slot:actions>
</x-ui.page-header>

<form class="erp-filterbar" method="GET" action="{{ route('business.visitors.reports') }}">
    <div class="erp-filter">
        <label class="form-label" for="from">From</label>
        <input class="form-control" type="date" name="from" id="from" value="{{ $report['from']->toDateString() }}">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="to">To</label>
        <input class="form-control" type="date" name="to" id="to" value="{{ $report['to']->toDateString() }}">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Read this window</button>
        <a class="btn btn-link" href="{{ route('business.visitors.reports') }}">Last 30 days</a>
    </div>
</form>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Visits" :value="$report['visits']" icon="bi-door-open" hero
              :hint="'Between '.$report['from']->format('d M').' and '.$report['to']->format('d M Y')" />
    <x-ui.kpi label="People" :value="$report['unique_people']" icon="bi-people"
              :hint="$report['returning_people'].' of them came more than once'" />
    <x-ui.kpi label="Average stay" :value="$minutesLabel($report['average_minutes'])" icon="bi-hourglass-split"
              hint="Checked-out visits only — the two timestamps, subtracted" />
    <x-ui.kpi label="Walk-ins" :value="$report['walk_ins']" icon="bi-box-arrow-in-right"
              :hint="number_format((float) $share($report['walk_ins']), 1).'% arrived without a booking'" />
    <x-ui.kpi label="Pre-booked" :value="$report['pre_booked']" icon="bi-calendar-check"
              :hint="number_format((float) $share($report['pre_booked']), 1).'% were expected'" />
    <x-ui.kpi label="No-shows" :value="$report['no_shows']" icon="bi-person-dash"
              :hint="$report['cancelled'].' booking(s) were cancelled outright'" />
</div>

@if ($report['longest'])
    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-stopwatch" aria-hidden="true"></i>
        <div>
            <strong>Longest stay in this window: {{ $report['longest']->dwellLabel() }}</strong> —
            {{ $report['longest']->visitor?->name }} on {{ $report['longest']->day()?->format('d M Y') }}
            (badge {{ $report['longest']->badge_no }}).
            @if ($peak)
                The busiest day was {{ $peak->format('d M Y') }} with {{ $maxDay }} visit(s).
            @endif
        </div>
    </div>
@endif

<section class="erp-card mb-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-card-title">Day by day</h2>
            <p class="erp-card-sub">Every day in the window, including the ones nobody came — an empty Tuesday is a fact about the gate, not a gap in the report.</p>
        </div>
    </div>
    <div class="px-3 pb-3">
        @foreach ($report['by_day'] as $date => $count)
            <div class="erp-list-row">
                <div class="erp-list-row-main">
                    <span class="erp-cell-strong">{{ \Carbon\Carbon::parse($date)->format('D, d M Y') }}</span>
                    <div class="erp-td-muted">{{ $count }} visit(s){{ $count === 0 ? ' — the gate stayed shut' : '' }}</div>
                </div>
                <div class="erp-progress" style="width: 140px" role="img" aria-label="{{ $count }} of {{ $maxDay }} visit(s)">
                    <span style="width: {{ $maxDay > 0 ? max(2, (int) round($count * 100 / $maxDay)) : 2 }}%"></span>
                </div>
            </div>
        @endforeach
    </div>
</section>

<div class="erp-split">
    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">By gate</h2>
                <p class="erp-card-sub">Which door the traffic actually comes through.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            @forelse ($report['by_branch'] as $label => $count)
                <div class="erp-list-row">
                    <div class="erp-list-row-main"><span class="erp-cell-strong">{{ $label }}</span></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip erp-chip-outline">{{ number_format((float) $share($count), 1) }}%</span>
                        <span class="erp-cell-strong">{{ $count }}</span>
                    </div>
                </div>
            @empty
                <p class="erp-td-muted mb-0">No visits in this window.</p>
            @endforelse
        </div>
    </section>

    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">By purpose</h2>
                <p class="erp-card-sub">Why people were inside — the list the gate logs against.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            @forelse ($report['by_purpose'] as $label => $count)
                <div class="erp-list-row">
                    <div class="erp-list-row-main"><span class="erp-cell-strong">{{ $label }}</span></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip erp-chip-outline">{{ number_format((float) $share($count), 1) }}%</span>
                        <span class="erp-cell-strong">{{ $count }}</span>
                    </div>
                </div>
            @empty
                <p class="erp-td-muted mb-0">No visits in this window.</p>
            @endforelse
        </div>
    </section>
</div>

<div class="erp-split mt-3">
    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Who received them</h2>
                <p class="erp-card-sub">The hosts people asked for, with the visits nobody claimed at the bottom.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            @forelse (array_slice($report['by_host'], 0, 12, true) as $label => $count)
                <div class="erp-list-row">
                    <div class="erp-list-row-main"><span class="erp-cell-strong">{{ $label }}</span></div>
                    <span class="erp-cell-strong">{{ $count }}</span>
                </div>
            @empty
                <p class="erp-td-muted mb-0">No visits in this window.</p>
            @endforelse
        </div>
    </section>

    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">People who keep coming</h2>
                <p class="erp-card-sub">Worth knowing by name: couriers, contractors, and whoever is here every week.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            @forelse ($report['repeat_visitors'] as $row)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <span class="erp-cell-strong">{{ $row['visitor']?->name ?? 'Since removed' }}</span>
                        <div class="erp-td-muted">{{ $row['visitor']?->organisation ?? 'No organisation on file' }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @if ($row['visitor']?->is_blacklisted)
                            <span class="erp-chip erp-chip-danger">Blacklisted</span>
                        @endif
                        <span class="erp-chip erp-chip-soft">{{ $row['visits'] }} visit(s)</span>
                    </div>
                </div>
            @empty
                <p class="erp-td-muted mb-0">Nobody came twice in this window.</p>
            @endforelse
        </div>
    </section>
</div>

@if ($blacklisted->isNotEmpty())
    <section class="erp-card mt-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The blacklist <span class="erp-chip erp-chip-danger">{{ $blacklisted->count() }}</span></h2>
                <p class="erp-card-sub">People the gate refuses and the reason it gives. Kept in the report on purpose: a list nobody reads is a list nobody maintains.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            @foreach ($blacklisted as $person)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('business.visitors.people', ['q' => $person->name]) }}">{{ $person->name }}</a>
                        <div class="erp-td-muted">{{ $person->blacklist_reason ?? 'No reason recorded' }}</div>
                    </div>
                    <span class="erp-td-muted">{{ $person->phone ?? 'No phone' }}</span>
                </div>
            @endforeach
        </div>
    </section>
@endif

<x-ui.related-pages />
