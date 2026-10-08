@php
    /* §12-16 — the register of people: the same record every visit hangs off. */
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors · Register"
    title="Everybody the gate has ever met"
    subtitle="One record per person, one row per visit. Keeping them apart is what makes “has this person been here before?” answerable at the door — and what makes the blacklist a fact about a person rather than about a visit that has already ended."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('business.visitors.people.create') }}">
                <i class="bi bi-person-plus" aria-hidden="true"></i> Add a person
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.index') }}"><i class="bi bi-people" aria-hidden="true"></i> The log</a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="People on file" :value="$summary['on_file']" icon="bi-journal-person" hero hint="One record per person, however often they come" />
    <x-ui.kpi label="Blacklisted" :value="$summary['blacklisted']" icon="bi-shield-exclamation"
              :hint="$summary['blacklisted'] > 0 ? 'The gate refuses these people at the door' : 'Nobody is refused at the door'" />
    <x-ui.kpi label="Inside right now" :value="$summary['inside']" icon="bi-person-check" hint="Read from the log, not from this register" />
    <x-ui.kpi label="Visits this month" :value="$summary['month_visits']" icon="bi-door-open" hint="Every gate, every purpose" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('business.visitors.people') }}">
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search the register</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $search }}" placeholder="Name, phone or organisation">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
        @if ($search !== '')
            <a class="btn btn-link" href="{{ route('business.visitors.people') }}">Everyone</a>
        @endif
    </div>
</form>

<x-ui.table-shell title="{{ $search !== '' ? 'People matching “'.$search.'”' : 'The register' }}" :count="$people->count().' person(s)'">
    <thead>
        <tr>
            <th>Person</th>
            <th>Reach</th>
            <th>Papers</th>
            <th class="text-end">Visits</th>
            <th>Last seen</th>
            <th>Standing</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($people as $person)
            <tr>
                <td data-label="Person">
                    <span class="erp-cell-strong">{{ $person->name }}</span>
                    <div class="erp-td-muted">{{ $person->organisation ?? 'No organisation on file' }}</div>
                </td>
                <td data-label="Reach">
                    {{ $person->phone ?? '—' }}
                    <div class="erp-td-muted">{{ $person->email ?? 'No email' }}</div>
                </td>
                <td data-label="Papers">
                    {{ $person->idTypeLabel() ?? 'None recorded' }}
                    @if ($person->maskedIdNumber())
                        <div class="erp-td-muted">{{ $person->maskedIdNumber() }}</div>
                    @endif
                </td>
                <td data-label="Visits" class="text-end">{{ $person->visits_count }}</td>
                <td data-label="Last seen">
                    @php($last = $person->visits->sortByDesc('created_at')->first())
                    {{ $last?->day()?->format('d M Y') ?? 'Never' }}
                    <div class="erp-td-muted">{{ $last?->purposeLabel() ?? 'No visit recorded yet' }}</div>
                </td>
                <td data-label="Standing">
                    @if ($person->is_blacklisted)
                        <span class="erp-chip erp-chip-danger">Blacklisted</span>
                    @else
                        <span class="erp-chip erp-chip-outline">May be admitted</span>
                    @endif
                </td>
                <td class="erp-td-actions">
                    @if ($canManage)
                        @if ($person->is_blacklisted)
                            <form method="POST" action="{{ route('business.visitors.people.restore', $person) }}"
                                  data-confirm="Allow {{ $person->name }} to be admitted again?">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Lift the entry</button>
                            </form>
                        @else
                            <details class="erp-inline-form">
                                <summary class="btn btn-sm btn-outline-secondary">Blacklist</summary>
                                <form method="POST" action="{{ route('business.visitors.people.blacklist', $person) }}" class="mt-2">
                                    @csrf
                                    <label class="form-label" for="blacklist_{{ $person->id }}">Why is {{ $person->name }} refused?</label>
                                    <input class="form-control mb-2" type="text" name="blacklist_reason" id="blacklist_{{ $person->id }}" maxlength="500" required>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Add {{ $person->name }} to the blacklist?">Add to the blacklist</button>
                                </form>
                            </details>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.create', ['visitor' => $person->id]) }}">Book in</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="{{ $search !== '' ? 'Nobody matches that search' : 'Nobody is on the register yet' }}"
                        text="The register fills itself in as people arrive — a walk-in with a name and a phone becomes a record the next visit is matched against. Add somebody ahead of time if the bookings are already known."
                        icon="bi-journal-person"
                        :action="$canManage ? 'Add a person' : null"
                        :href="$canManage ? route('business.visitors.people.create') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<x-ui.related-pages />
