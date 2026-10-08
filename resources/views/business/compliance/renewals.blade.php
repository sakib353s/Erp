@php
    /* §12-09 — what is running out, across every register at once.
     *
     * One page on purpose: a licence, a policy and a contract keep separate
     * shelves, and the question "what has to be dealt with this quarter?" does
     * not care which shelf anything is on. The four sections are the honest
     * buckets — lapsed, inside the month, inside the horizon, and the papers
     * that carry no date at all. */
    $kindLabel = $kind ? $registry->plural($kind) : null;
@endphp

<x-ui.page-header
    eyebrow="Business Management · Compliance"
    title="{{ $kindLabel ? 'Renewals: '.strtolower($kindLabel) : 'What is running out' }}"
    subtitle="Lapsed first, then the next thirty days, then the rest of the quarter — every expiry and every deadline on one list, oldest first. The undated shelf is separate, because “no expiry on file” is not the same as “safe”."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('records.index') }}">
            <i class="bi bi-journal-text" aria-hidden="true"></i> All registers
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar') }}">
            <i class="bi bi-calendar-event" aria-hidden="true"></i> Calendar
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Already lapsed" :value="$lenses['lapsed']->count()" icon="bi-exclamation-triangle"
              :hint="$lenses['lapsed']->count() > 0 ? 'Expired or past the deadline — act or retire' : 'Nothing has lapsed'" />
    <x-ui.kpi label="Next 30 days" :value="$lenses['near']->count()" icon="bi-hourglass-split"
              hint="Renew or file before the date passes" />
    <x-ui.kpi label="31–{{ $lenses['horizon'] }} days" :value="$lenses['later']->count()" icon="bi-calendar-check"
              hint="Visible now so it is never a surprise later" />
    <x-ui.kpi label="No date on file" :value="$lenses['undated']->count()" icon="bi-question-circle"
              hint="Registrations and papers with no term at all" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('compliance.renewals') }}">
    <div class="erp-filter">
        <label class="form-label" for="kind">Shelf</label>
        <select class="form-select" name="kind" id="kind" data-erp-autosubmit>
            <option value="">Every register</option>
            @foreach ($registry->options() as $kindKey => $optionLabel)
                <option value="{{ $kindKey }}" @selected($kind === $kindKey)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        @if ($kind)
            <a class="btn btn-link" href="{{ route('compliance.renewals') }}">Every register</a>
        @endif
    </div>
</form>

@php
    $sections = [
        ['title' => 'Already lapsed', 'rows' => $lenses['lapsed'], 'note' => 'These were true once. Renew, complete or retire them — a lapsed row left alone is how a register stops being trusted.'],
        ['title' => 'Inside the next 30 days', 'rows' => $lenses['near'], 'note' => 'The month is where renewals are cheap and late fees are not.'],
        ['title' => 'Between 31 and '.$lenses['horizon'].' days', 'rows' => $lenses['later'], 'note' => 'Long enough to plan, close enough to see.'],
    ];
@endphp

@foreach ($sections as $section)
    <x-ui.table-shell title="{{ $section['title'] }}" :count="$section['rows']->count().' record(s)'">
        <x-slot:tools>
            <span class="erp-chip erp-chip-outline">{{ $section['note'] }}</span>
        </x-slot:tools>
        <thead>
            <tr>
                <th>Record</th>
                <th>Shelf</th>
                <th>Tracked date</th>
                <th>Days</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($section['rows'] as $record)
                <tr>
                    <td data-label="Record">
                        <a class="erp-cell-strong" href="{{ route('records.show', $record) }}">{{ $record->title }}</a>
                        @if ($record->reference_no)
                            <div class="erp-td-muted">{{ $record->reference_no }}</div>
                        @endif
                    </td>
                    <td data-label="Shelf" class="erp-td-muted">{{ $record->kindLabel() }}</td>
                    <td data-label="Tracked date" class="erp-td-muted">{{ $record->trackedOn()?->format('d M Y') ?? '—' }}</td>
                    <td data-label="Days" class="erp-td-num">
                        @php($days = (int) $record->daysLeft())
                        {{ $days < 0 ? abs($days).' ago' : $days.' left' }}
                    </td>
                    <td data-label="State"><x-ui.status :value="$record->state()" :label="$record->stateLabel()" /></td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.show', $record) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty title="Nothing here" text="Nothing on this list falls in this window." icon="bi-check2-circle" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>
@endforeach

<x-ui.table-shell title="Carrying no date at all" :count="$lenses['undated']->count().' record(s)'">
    <x-slot:tools>
        <span class="erp-chip erp-chip-outline">A TIN, a number, a logo — nothing to track. And any licence that should have a term.</span>
    </x-slot:tools>
    <thead>
        <tr>
            <th>Record</th>
            <th>Shelf</th>
            <th>Reference</th>
            <th>Recorded</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lenses['undated'] as $record)
            <tr>
                <td data-label="Record"><a class="erp-cell-strong" href="{{ route('records.show', $record) }}">{{ $record->title }}</a></td>
                <td data-label="Shelf" class="erp-td-muted">{{ $record->kindLabel() }}</td>
                <td data-label="Reference" class="erp-td-muted">{{ $record->reference_no ?: '—' }}</td>
                <td data-label="Recorded" class="erp-td-muted">{{ $record->created_at?->format('d M Y') ?? '—' }}</td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.show', $record) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <x-ui.empty title="Every record carries a date" text="There is nothing on the registers without an expiry or a deadline." icon="bi-calendar-check" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<x-ui.related-pages />
