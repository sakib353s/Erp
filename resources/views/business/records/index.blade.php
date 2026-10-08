@php
    /* §12-03/04/09/10 — the shelves, then the register itself. */
    $groupNotes = [
        'company' => 'What the company is: its licence to trade, its tax numbers, the certificates that say it exists.',
        'compliance' => 'What the company owes somebody by a date: filings with the registrar, statutory duties, insurance cover.',
        'documents' => 'What the company has signed and what it prints: contracts, agreements, the vault, the brand.',
    ];
@endphp

<x-ui.page-header
    eyebrow="Business Management · Registers"
    title="What the company is, and the day each of it runs out"
    subtitle="A licence, a policy, a contract and a filing are the same animal: somebody issued it, it carries a number, and it stops being true on a date. Keeping them in one register is what makes “what runs out this quarter?” a question with an answer instead of a search."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.renewals') }}">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> What is running out
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar') }}">
            <i class="bi bi-calendar-event" aria-hidden="true"></i> Compliance calendar
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('compliance.obligations') }}">
            <i class="bi bi-calendar-check" aria-hidden="true"></i> Recurring duties
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Live records" :value="$summary['active']" icon="bi-journal-text"
              hint="Everything on the registers that has not been retired" />
    <x-ui.kpi label="Expiring within 30 days" :value="$summary['expiring']" icon="bi-hourglass-split"
              :href="route('records.index', ['state' => 'expiring'])"
              :hint="$summary['expiring'] > 0 ? 'Renew these before they lapse' : 'Nothing expires in the next month'" />
    <x-ui.kpi label="Already expired" :value="$summary['expired']" icon="bi-exclamation-triangle"
              :href="route('records.index', ['state' => 'expired'])"
              :hint="$summary['expired'] > 0 ? 'A lapsed licence is not a paperwork problem' : 'Nothing has lapsed'" />
    <x-ui.kpi label="Due within 30 days" :value="$summary['due_soon']" icon="bi-calendar-check"
              :href="route('records.index', ['state' => 'due_soon'])"
              hint="Filings and duties with a deadline this month" />
    <x-ui.kpi label="Overdue" :value="$summary['overdue']" icon="bi-alarm"
              :href="route('records.index', ['state' => 'overdue'])"
              :hint="$summary['overdue'] > 0 ? 'Past the deadline and not filed' : 'Nothing is past its deadline'" />
    <x-ui.kpi label="No date on file" :value="$summary['undated']" icon="bi-question-circle"
              :href="route('records.index', ['state' => 'undated'])"
              hint="Registrations and assets that carry no term — worth a look, not a panic" />
</div>

@foreach ($registry->groups() as $groupKey => $groupLabel)
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">{{ $groupLabel }}</h2>
                <p class="erp-card-sub">{{ $groupNotes[$groupKey] ?? '' }}</p>
            </div>
            <div class="erp-card-actions">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.index', ['group' => $groupKey]) }}">
                    Only this shelf
                </a>
            </div>
        </header>
        <div class="px-3 pb-2">
            @foreach ($registry->kindsFor($groupKey) as $kindKey => $kind)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <a class="erp-cell-strong" href="{{ route('records.kind', $registry->slug($kindKey)) }}">
                            <i class="bi {{ $kind['icon'] }} me-1" aria-hidden="true"></i>{{ $kind['plural'] }}
                        </a>
                        <div class="erp-td-muted">{{ $kind['hint'] }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip {{ ($counts[$kindKey] ?? 0) > 0 ? 'erp-chip-soft' : 'erp-chip-outline' }}">
                            {{ $counts[$kindKey] ?? 0 }} live
                        </span>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.kind', $registry->slug($kindKey)) }}">Open</a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endforeach

<form class="erp-filterbar" method="GET" action="{{ route('records.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="kind">Kind</label>
        <select class="form-select" name="kind" id="kind" data-erp-autosubmit>
            <option value="">Every kind</option>
            @foreach ($registry->options() as $kindKey => $kindLabel)
                <option value="{{ $kindKey }}" @selected(($filters['kind'] ?? null) === $kindKey)>{{ $kindLabel }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="state">State</label>
        <select class="form-select" name="state" id="state" data-erp-autosubmit>
            <option value="">Live only</option>
            @foreach (\App\Domain\Business\RecordsRegistry::STATES as $stateKey => $stateLabel)
                <option value="{{ $stateKey }}" @selected(($filters['state'] ?? '') === $stateKey)>{{ $stateLabel }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="branch_id">Branch</label>
        <select class="form-select" name="branch_id" id="branch_id" data-erp-autosubmit>
            <option value="">Every branch</option>
            <option value="company" @selected(($filters['branch_id'] ?? '') === 'company')>Company-wide only</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="Title, number, issuer or counterparty">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('records.index') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="The register" :count="$records->total().' record(s)'">
    <thead>
        <tr>
            <th>Record</th>
            <th>Kind</th>
            <th>Issued by / other party</th>
            <th>Tracked date</th>
            <th>State</th>
            <th>Branch</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($records as $record)
            <tr>
                <td data-label="Record">
                    <a class="erp-cell-strong" href="{{ route('records.show', $record) }}">{{ $record->title }}</a>
                    @if ($record->reference_no)
                        <div class="erp-td-muted">{{ $record->reference_no }}</div>
                    @endif
                </td>
                <td data-label="Kind" class="erp-td-muted">{{ $record->kindLabel() }}</td>
                <td data-label="Party" class="erp-td-muted">
                    {{ $record->counterparty ?: ($record->issuer ?: '—') }}
                    @if ($record->value_amount !== null)
                        <div class="erp-td-num">৳{{ number_format((float) $record->value_amount, 2) }}</div>
                    @endif
                </td>
                <td data-label="Tracked date" class="erp-td-muted">
                    @if ($record->trackedOn())
                        {{ $record->trackedOn()->format('d M Y') }}
                        @if ($record->daysLeft() !== null)
                            <div class="erp-td-muted">
                                {{ $record->daysLeft() < 0
                                    ? abs($record->daysLeft()).' day(s) ago'
                                    : $record->daysLeft().' day(s) left' }}
                            </div>
                        @endif
                    @else
                        —
                    @endif
                </td>
                <td data-label="State">
                    <x-ui.status :value="$record->state()" :label="$record->stateLabel()" />
                </td>
                <td data-label="Branch" class="erp-td-muted">{{ $record->branch?->name ?? 'Company-wide' }}</td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.show', $record) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        title="Nothing on this shelf yet"
                        text="Record the trade licence, the TIN and BIN, or a contract — each one carries the date it stops being true, and the register will watch it for you."
                        icon="bi-journal-text" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($records->hasPages())
        <x-slot:footer>{{ $records->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
