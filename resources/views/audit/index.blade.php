@extends('layouts.app')

@section('page_title', 'Audit log')

@section('content')
    @php
        $chainOk = $chain['ok'] ?? null;
        $sealsOk = $archiveVerdicts['ok'] ?? null;
        $sealCount = $archiveVerdicts['archives'] ?? $archives->count();
    @endphp

    <div class="erp-page-head">
        <div>
            <p class="erp-eyebrow mb-1">§16-33 · §16-34 · §16-35</p>
            <h1 class="erp-h1 mb-1">Audit log</h1>
            <p class="erp-page-sub mb-0">
                Append-only and hash-chained: every row carries the hash of the row before it, so an edited row
                cannot hide. {{ number_format($eventTotal) }} events for this company
                across {{ $vocabularySize }} modules.
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            @if ($chainOk === true)
                <x-ui.status value="active" label="Chain verified" />
            @elseif ($chainOk === false)
                <x-ui.status value="rejected" label="Chain broken" />
            @else
                <x-ui.status value="pending" label="Verify via CLI" />
            @endif

            @if ($perm('audit.view'))
                <form method="POST" action="{{ route('audit.verify') }}">
                    @csrf
                    <button class="btn btn-outline-dark" type="submit">
                        <i class="bi bi-shield-check" aria-hidden="true"></i> Verify now
                    </button>
                </form>
            @endif

            @if ($perm('audit.export'))
                <a class="btn btn-outline-dark"
                   href="{{ route('documents.print.show', ['type' => 'audit_report', 'id' => 0, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'filter' => $filters['action'] ?? null]) }}">
                    <i class="bi bi-printer" aria-hidden="true"></i> Printable report
                </a>
                <a class="btn btn-outline-secondary"
                   href="{{ route('audit.export', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'action' => $filters['action'] ?? null, 'entity_type' => $filters['entity_type'] ?? null])) }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                </a>
            @endif
        </div>
    </div>

    @if ($chainOk === false)
        <div class="erp-note erp-note-danger">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>
                <strong>The chain does not verify.</strong>
                <p class="mb-0">
                    It broke at sequence {{ $chain['broken_at'] ?? '?' }} ({{ $chain['reason'] ?? 'unknown reason' }}).
                    Nothing on this page rewrites it: the row is evidence. Export the trail, then investigate the
                    row and the operator account behind it.
                </p>
            </div>
        </div>
    @elseif ($sealsOk === false)
        <div class="erp-note erp-note-warn">
            <i class="bi bi-clipboard-x" aria-hidden="true"></i>
            <div>
                <strong>A sealed period no longer matches its seal.</strong>
                <p class="mb-0">
                    The chain is intact, so this is about the size or the ends of a period that was sealed at the
                    time — which is exactly how a deleted old row shows up. See the seals below.
                </p>
            </div>
        </div>
    @endif

    @if ($lastVerification)
        <p class="erp-page-sub">
            Last verification: {{ $lastVerification->created_at?->format('d M Y H:i') }}
            by {{ $lastVerification->actor_label ?? $lastVerification->actor_type }}
            — {{ ($lastVerification->after['chain_ok'] ?? null) ? 'chain OK' : 'chain did not verify' }}
            @php $after = $lastVerification->after ?? []; @endphp
            @if (($after['archives'] ?? 0) > 0)
                , {{ $after['archives'] }} sealed period(s) {{ ($after['archives_ok'] ?? false) ? 'OK' : 'broken' }}
            @endif
            .
        </p>
    @endif

    <form class="erp-filterbar row g-2 align-items-end mb-3" method="GET" action="{{ route('audit.index') }}">
        <div class="col-sm-6 col-md-3">
            <label class="form-label" for="module">Module</label>
            <select class="form-select" id="module" name="module">
                <option value="">Every module</option>
                @foreach ($modules as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6 col-md-3">
            <label class="form-label" for="action">Action</label>
            <input class="form-control" id="action" name="action" list="known-actions"
                   value="{{ $filters['action'] ?? '' }}" placeholder="sales.invoice_issued">
            <datalist id="known-actions">
                @foreach ($commonActions as $known)
                    <option value="{{ $known }}"></option>
                @endforeach
            </datalist>
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="entity_type">Entity</label>
            <input class="form-control" id="entity_type" name="entity_type" value="{{ $filters['entity_type'] ?? '' }}" placeholder="invoice">
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="reason or action">
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="result">Result</label>
            <input class="form-control" id="result" name="result" value="{{ $filters['result'] ?? '' }}" placeholder="success / failure">
        </div>
        <div class="col-auto form-check ms-2">
            <input class="form-check-input" type="checkbox" id="sensitive" name="sensitive" value="1" @checked(request()->boolean('sensitive'))>
            <label class="form-check-label" for="sensitive">Only what matters</label>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
        @if (request()->query() !== [])
            <div class="col-auto"><a class="btn btn-link" href="{{ route('audit.index') }}">Reset</a></div>
        @endif
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="text-end">Seq</th>
                        <th>When</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Entity</th>
                        <th>By</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td class="text-end"><code>{{ $event->seq }}</code></td>
                            <td class="erp-page-sub">{{ $event->created_at?->format('d M Y, H:i:s') }}</td>
                            <td>
                                <a class="text-decoration-none fw-semibold" href="{{ route('audit.show', $event) }}">
                                    {{ \App\Domain\Audit\AuditVocabulary::label($event->action) }}
                                </a>
                                @if (\App\Domain\Audit\AuditVocabulary::isSensitive($event->action))
                                    <span class="erp-chip erp-chip-warn ms-1" title="Worth somebody's attention">watch</span>
                                @endif
                                <div class="erp-page-sub"><code>{{ $event->action }}</code></div>
                            </td>
                            <td class="erp-page-sub">{{ \App\Domain\Audit\AuditVocabulary::moduleLabel($event->action) }}</td>
                            <td class="erp-page-sub">
                                {{ $event->entity_type }}@if ($event->entity_id)#{{ $event->entity_id }}@endif
                                @if ($event->reason)
                                    {{-- the “why” — the search box looks in it, so it has to be visible --}}
                                    <div class="text-body" title="{{ $event->reason }}">
                                        {{ \Illuminate\Support\Str::limit($event->reason, 90) }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $event->actor_label ?? ($event->actor_type === 'user' ? ($event->actor_id ?: '—') : $event->actor_type) }}</td>
                            <td><x-ui.status :value="$event->result" :label="$event->result" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty title="No events match this filter"
                                            text="The trail is append-only, so nothing was removed — this selection simply has no rows. Widen the dates or clear the module."
                                            icon="bi-search" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $events->links() }}</div>

    <section class="erp-card mt-3">
        <header class="erp-card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="erp-card-title mb-0">Sealed periods</h2>
            @if ($sealCount > 0)
                <span class="erp-page-sub">
                    {{ $sealCount }} sealed
                    @if ($sealsOk === true)
                        · all re-verified against the events still present
                    @elseif ($sealsOk === false)
                        · at least one no longer matches
                    @endif
                </span>
            @endif
        </header>

        @if ($archives->isEmpty())
            <x-ui.empty title="No period has been sealed yet"
                        text="A seal records how many events a closed month held and where its chain started and ended — the only way a deleted old row can be noticed. The sealing command runs on the first morning of each month."
                        icon="bi-shield-lock" />
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="text-end">Events</th>
                            <th class="text-end">Sequence</th>
                            <th>Seal checksum</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($archives as $archive)
                            @php
                                $verdict = null;
                                foreach ($archiveVerdicts['failures'] ?? [] as $failure) {
                                    if ($failure['period'] === $archive->period) {
                                        $verdict = $failure;
                                    }
                                }
                            @endphp
                            <tr>
                                <td class="fw-semibold">{{ $archive->period }}</td>
                                <td class="text-end">{{ number_format($archive->event_count) }}</td>
                                <td class="text-end erp-page-sub">{{ $archive->seq_from }}–{{ $archive->seq_to }}</td>
                                <td><code class="erp-page-sub" title="{{ $archive->checksum }}">{{ substr((string) $archive->checksum, 0, 16) }}…</code></td>
                                <td>
                                    @if ($verdict)
                                        <x-ui.status value="rejected" :label="str_replace('_', ' ', $verdict['reason'])" />
                                    @elseif ($archiveVerdicts === null)
                                        <x-ui.status value="pending" label="Checked by CLI" />
                                    @else
                                        <x-ui.status value="active" label="Matches its seal" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
