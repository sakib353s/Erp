@php
    /* §12-09 — the recurring half: what comes round again, and when it is next.
     *
     * Grouped by cadence because that is how the work is actually planned — the
     * monthly returns are one sitting, the yearly ones are a diary note. A row
     * with no cycle sits last, under "Once only", because pretending it repeats
     * would be the one thing worse than forgetting it. */
    $overdue = collect($groups)->sum(fn (array $group) => $group['rows']->filter(fn ($record) => $record->isOverdue())->count());
@endphp

<x-ui.page-header
    eyebrow="Business Management · Compliance"
    title="What comes round again"
    subtitle="VAT returns, TDS deposits, labour-law duties and the registrar's filings — grouped by how often each one comes round. Marking one done does not clear the row: it sets the next due date by its cycle, which is the difference between a calendar and a to-do list."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar') }}">
            <i class="bi bi-calendar-event" aria-hidden="true"></i> Calendar
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.renewals') }}">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> Renewals
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('records.kind', 'obligation') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Record a duty
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Recurring duties" :value="collect($groups)->sum(fn (array $group) => $group['rows']->count())" icon="bi-arrow-repeat"
              hint="Filings and statutory obligations with a cycle on file" />
    <x-ui.kpi label="Overdue" :value="$overdue" icon="bi-alarm"
              :hint="$overdue > 0 ? 'Past the deadline and not marked done' : 'Nothing is past its deadline'" />
    <x-ui.kpi label="Due in 30 days" :value="$summary['due_soon']" icon="bi-calendar-check"
              hint="Everything with a deadline this month" />
    <x-ui.kpi label="Live records" :value="$summary['active']" icon="bi-journal-text"
              hint="Across every register, not just the recurring ones" />
</div>

@forelse ($groups as $group)
    <x-ui.table-shell title="{{ $group['label'] }}" :count="$group['rows']->count().' duty(ies)'">
        <thead>
            <tr>
                <th>Duty</th>
                <th>Authority</th>
                <th>Next due</th>
                <th>Last done</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($group['rows'] as $record)
                <tr>
                    <td data-label="Duty">
                        <a class="erp-cell-strong" href="{{ route('records.show', $record) }}">{{ $record->title }}</a>
                        <div class="erp-td-muted">{{ $record->kindLabel() }}</div>
                    </td>
                    <td data-label="Authority" class="erp-td-muted">{{ $record->issuer ?: '—' }}</td>
                    <td data-label="Next due" class="erp-td-muted">
                        {{ $record->due_on?->format('d M Y') ?? 'Not set' }}
                        @if ($record->due_on && $record->daysLeft() !== null)
                            <div class="erp-td-muted">
                                {{ $record->daysLeft() < 0 ? abs($record->daysLeft()).' day(s) late' : $record->daysLeft().' day(s) left' }}
                            </div>
                        @endif
                    </td>
                    <td data-label="Last done" class="erp-td-muted">{{ $record->last_completed_on?->format('d M Y') ?? 'Never recorded' }}</td>
                    <td data-label="State"><x-ui.status :value="$record->state()" :label="$record->stateLabel()" /></td>
                    <td class="erp-td-actions">
                        @if ($canManage)
                            <form class="d-inline" method="POST" action="{{ route('records.complete', $record) }}"
                                  data-confirm="Mark “{{ $record->title }}” done? The next due date is set by its cycle.">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">
                                    <i class="bi bi-check2" aria-hidden="true"></i> Done
                                </button>
                            </form>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.show', $record) }}">Open</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table-shell>
@empty
    <section class="erp-card">
        <x-ui.empty
            title="No recurring duties on file yet"
            text="Record the ones that come round — the monthly VAT return, the quarterly TDS deposit, the yearly return to the registrar — with their cycle, and this page will keep their next dates for you."
            icon="bi-arrow-repeat"
            :action="$canManage ? 'Record the first one' : null"
            :href="$canManage ? route('records.kind', 'obligation') : null" />
    </section>
@endforelse

<x-ui.related-pages />
