@php
    /* §12-09 — one month of expiries and deadlines.
     *
     * A month is a grid, so it is drawn as one: seven columns of real weeks,
     * Sunday first (which is how a Bangladeshi office reads a calendar). Each
     * entry is a record and each entry's colour is the *record's* state, so the
     * same licence reads the same way here as it does on its own page. */
    $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
@endphp

<x-ui.page-header
    eyebrow="Business Management · Compliance"
    title="Compliance calendar — {{ $calendar['label'] }}"
    subtitle="Every licence expiry, insurance renewal, filing deadline and statutory duty that falls in this month. Days outside the month are shown greyed so the weeks line up, and each entry links to the record it belongs to."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar', ['month' => $calendar['previous']]) }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ $calendar['previous'] }}
        </a>
        @unless ($calendar['is_current'])
            <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar') }}">
                <i class="bi bi-calendar-event" aria-hidden="true"></i> This month
            </a>
        @endunless
        <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar', ['month' => $calendar['next']]) }}">
            {{ $calendar['next'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.renewals') }}">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> Renewals list
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Dated this month" :value="$calendar['total']" icon="bi-calendar-event"
              hint="Expiries and deadlines falling in {{ $calendar['label'] }}" />
    <x-ui.kpi label="Already lapsed" :value="$summary['expired'] + $summary['overdue']" icon="bi-exclamation-triangle"
              hint="Across every register, not just this month" />
    <x-ui.kpi label="Inside 30 days" :value="$summary['expiring'] + $summary['due_soon']" icon="bi-hourglass-split"
              hint="Renewals and deadlines in the next month" />
    <x-ui.kpi label="Live records" :value="$summary['active']" icon="bi-journal-text"
              hint="Everything on the registers that has not been retired" />
</div>

<section class="erp-card mb-3">
    <header class="erp-card-head">
        <div>
            <h2 class="erp-card-title">{{ $calendar['label'] }}</h2>
            <p class="erp-card-sub">Sunday to Saturday, whole weeks, so the first and last days are never orphaned.</p>
        </div>
        <div class="erp-card-actions">
            <span class="erp-chip erp-chip-soft">{{ $calendar['total'] }} entr{{ $calendar['total'] === 1 ? 'y' : 'ies' }}</span>
        </div>
    </header>
    <div class="p-3">
        <div class="erp-cal-head">
            @foreach ($weekdays as $weekday)
                <span>{{ $weekday }}</span>
            @endforeach
        </div>
        <div class="erp-cal-grid">
            @foreach ($calendar['weeks'] as $week)
                @foreach ($week as $day)
                    <div class="erp-cal-cell {{ $day['in_month'] ? '' : 'erp-cal-cell-muted' }} {{ $day['is_today'] ? 'erp-cal-cell-today' : '' }}">
                        <div class="erp-cal-day">
                            <span>{{ $day['date']->format('j') }}</span>
                            @if ($day['is_today'])
                                <span class="erp-chip erp-chip-soft">today</span>
                            @elseif (! $day['in_month'])
                                <span class="erp-td-muted">{{ $day['date']->format('M') }}</span>
                            @endif
                        </div>
                        @foreach ($day['entries'] as $entry)
                            @php
                                $state = $entry->state();
                                $tone = match ($state) {
                                    'expired', 'overdue' => 'erp-cal-entry-danger',
                                    'expiring', 'due_soon' => 'erp-cal-entry-warn',
                                    'valid' => 'erp-cal-entry-ok',
                                    default => 'erp-cal-entry-muted',
                                };
                            @endphp
                            <a class="erp-cal-entry {{ $tone }}"
                               href="{{ route('records.show', $entry) }}"
                               title="{{ $entry->kindLabel() }} — {{ $entry->stateLabel() }}">
                                {{ \Illuminate\Support\Str::limit($entry->title, 34) }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>

        @if ($calendar['total'] === 0)
            <div class="erp-filter-note mt-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Nothing on the registers is dated in {{ $calendar['label'] }}. The renewals list shows what is coming after it.
            </div>
        @endif
    </div>
</section>

<x-ui.related-pages />
