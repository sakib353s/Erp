@php
    /* §12-03/04/09/10 — one kind's register. Which columns exist, which fields
       the form asks for and which date the shelf tracks all come from the
       registry, so a contract and a licence are the same screen with a
       different configuration rather than two screens that drift apart. */
    $tracksDeadline = (bool) $config['due'];
    $hasTerm = (bool) $config['validity'];
@endphp

<x-ui.page-header
    eyebrow="Business Management · {{ $config['plural'] }}"
    title="{{ $config['plural'] }}"
    subtitle="{{ $config['hint'] }}"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('records.index', ['kind' => $kind]) }}">
            <i class="bi bi-journal-text" aria-hidden="true"></i> All registers
        </a>
        @if ($hasTerm)
            <a class="btn btn-outline-secondary" href="{{ route('compliance.renewals', ['kind' => $kind]) }}">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i> Renewals
            </a>
        @endif
        @if ($tracksDeadline)
            <a class="btn btn-outline-secondary" href="{{ route('compliance.calendar') }}">
                <i class="bi bi-calendar-event" aria-hidden="true"></i> Calendar
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="On the register" :value="$summary['active']" icon="bi-journal-text"
              hint="Live {{ strtolower($config['plural']) }}, retired ones excluded" />
    @if ($hasTerm)
        <x-ui.kpi label="Expiring in 30 days" :value="$summary['expiring']" icon="bi-hourglass-split"
                  :hint="$summary['expiring'] > 0 ? 'Renew before the date passes' : 'Nothing runs out this month'" />
        <x-ui.kpi label="Expired" :value="$summary['expired']" icon="bi-exclamation-triangle"
                  :hint="$summary['expired'] > 0 ? 'A lapsed paper is a decision somebody has to take' : 'Nothing has lapsed'" />
    @endif
    @if ($tracksDeadline)
        <x-ui.kpi label="Due in 30 days" :value="$summary['due_soon']" icon="bi-calendar-check"
                  :hint="$summary['due_soon'] > 0 ? 'File these before the deadline' : 'Nothing is due this month'" />
        <x-ui.kpi label="Overdue" :value="$summary['overdue']" icon="bi-alarm"
                  :hint="$summary['overdue'] > 0 ? 'Past the deadline and not done' : 'Nothing is past its deadline'" />
    @endif
    @if (! $hasTerm && ! $tracksDeadline)
        <x-ui.kpi label="Carrying no date" :value="$summary['undated']" icon="bi-question-circle"
                  hint="This shelf has no term to track — the numbers and the files are the point" />
    @endif
    <x-ui.kpi label="Retired" :value="$summary['retired']" icon="bi-archive"
              hint="Taken off the working list on purpose; still in the register and the audit trail" />
</div>

@if ($canManage)
    <section class="erp-card mb-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Record a {{ strtolower($config['label']) }}</h2>
                <p class="erp-card-sub">The date it stops being true is the reason it belongs here — everything else is what makes it checkable.</p>
            </div>
        </header>
        <form method="POST" action="{{ route('records.store') }}">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="title">Title <span class="text-danger">*</span></label>
                    <input class="form-control @error('title') is-invalid @enderror" type="text" name="title"
                           id="title" value="{{ old('title') }}" maxlength="191" required
                           placeholder="{{ $config['label'] === 'Trade licence' ? 'Trade licence — Dhanmondi outlet' : '' }}">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                @if ($config['reference'])
                    <div class="col-md-3">
                        <label class="form-label" for="reference_no">{{ $config['reference_label'] }}</label>
                        <input class="form-control" type="text" name="reference_no" id="reference_no"
                               value="{{ old('reference_no') }}" maxlength="120">
                    </div>
                @endif
                @if ($config['issuer'])
                    <div class="col-md-4">
                        <label class="form-label" for="issuer">{{ $config['issuer_label'] }}</label>
                        <input class="form-control" type="text" name="issuer" id="issuer"
                               value="{{ old('issuer') }}" maxlength="160">
                    </div>
                @endif
                @if ($config['party'])
                    <div class="col-md-4">
                        <label class="form-label" for="counterparty">{{ $config['party_label'] }} <span class="text-danger">*</span></label>
                        <input class="form-control @error('counterparty') is-invalid @enderror" type="text"
                               name="counterparty" id="counterparty" value="{{ old('counterparty') }}" maxlength="160">
                        @error('counterparty')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif
                @if ($config['value'])
                    <div class="col-md-4">
                        <label class="form-label" for="value_amount">{{ $config['value_label'] }}</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="value_amount"
                               id="value_amount" value="{{ old('value_amount') }}">
                    </div>
                @endif
                @if ($config['issued'])
                    <div class="col-md-3">
                        <label class="form-label" for="issued_on">Issued on</label>
                        <input class="form-control" type="date" name="issued_on" id="issued_on" value="{{ old('issued_on') }}">
                    </div>
                @endif
                @if ($hasTerm)
                    <div class="col-md-3">
                        <label class="form-label" for="starts_on">Starts</label>
                        <input class="form-control" type="date" name="starts_on" id="starts_on" value="{{ old('starts_on') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="expires_on">
                            Expires
                            @if (in_array($kind, ['licence', 'insurance'], true))<span class="text-danger">*</span>@endif
                        </label>
                        <input class="form-control @error('expires_on') is-invalid @enderror" type="date"
                               name="expires_on" id="expires_on" value="{{ old('expires_on') }}">
                        @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endif
                @if ($tracksDeadline)
                    <div class="col-md-3">
                        <label class="form-label" for="due_on">Due on <span class="text-danger">*</span></label>
                        <input class="form-control @error('due_on') is-invalid @enderror" type="date"
                               name="due_on" id="due_on" value="{{ old('due_on') }}">
                        @error('due_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="repeat_months">Comes round</label>
                        <select class="form-select" name="repeat_months" id="repeat_months">
                            <option value="">Once only</option>
                            @foreach (\App\Domain\Business\RecordsRegistry::CADENCES as $months => $label)
                                <option value="{{ $months }}" @selected((string) old('repeat_months') === (string) $months)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Completing it rolls the next date forward by this.</div>
                    </div>
                @endif
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea class="form-control" name="notes" id="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                </div>
            </div>
            <div class="erp-form-actions mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add to the register
                </button>
            </div>
        </form>
    </section>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('records.kind', $registry->slug($kind)) }}">
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
               placeholder="Title or number">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('records.kind', $registry->slug($kind)) }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="{{ $config['plural'] }}" :count="$records->total().' record(s)'">
    <thead>
        <tr>
            <th>Record</th>
            @if ($config['issuer'] || $config['party'])
                <th>{{ $config['party'] ? $config['party_label'] : $config['issuer_label'] }}</th>
            @endif
            @if ($config['value'])<th class="erp-td-num">{{ $config['value_label'] }}</th>@endif
            @if ($hasTerm)<th>Term</th>@endif
            @if ($tracksDeadline)<th>Deadline</th>@endif
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
                        <div class="erp-td-muted">{{ $config['reference_label'] }}: {{ $record->reference_no }}</div>
                    @endif
                </td>
                @if ($config['issuer'] || $config['party'])
                    <td data-label="Party" class="erp-td-muted">{{ $record->counterparty ?: ($record->issuer ?: '—') }}</td>
                @endif
                @if ($config['value'])
                    <td data-label="Value" class="erp-td-num">
                        {{ $record->value_amount !== null ? '৳'.number_format((float) $record->value_amount, 2) : '—' }}
                    </td>
                @endif
                @if ($hasTerm)
                    <td data-label="Term" class="erp-td-muted">
                        {{ $record->starts_on?->format('d M Y') ?? ($record->issued_on?->format('d M Y') ?? '—') }}
                        @if ($record->expires_on)
                            → {{ $record->expires_on->format('d M Y') }}
                        @endif
                    </td>
                @endif
                @if ($tracksDeadline)
                    <td data-label="Deadline" class="erp-td-muted">
                        @if ($record->due_on)
                            {{ $record->due_on->format('d M Y') }}
                            <div class="erp-td-muted">
                                {{ \App\Domain\Business\RecordsRegistry::CADENCES[$record->repeat_months] ?? 'One-off' }}
                                @if ($record->last_completed_on)
                                    · last done {{ $record->last_completed_on->format('d M Y') }}
                                @endif
                            </div>
                        @else
                            {{ $record->last_completed_on ? 'Done '.$record->last_completed_on->format('d M Y').' — no repeat' : '—' }}
                        @endif
                    </td>
                @endif
                <td data-label="State"><x-ui.status :value="$record->state()" :label="$record->stateLabel()" /></td>
                <td data-label="Branch" class="erp-td-muted">{{ $record->branch?->name ?? 'Company-wide' }}</td>
                <td class="erp-td-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('records.show', $record) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        title="Nothing recorded here yet"
                        text="Add the first entry — the number, who issued it and the date it ends. The register keeps the history of every renewal from then on."
                        icon="{{ $config['icon'] }}" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($records->hasPages())
        <x-slot:footer>{{ $records->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
