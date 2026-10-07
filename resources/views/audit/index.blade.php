@extends('layouts.app')

@section('page_title', 'Audit log')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Audit log</h1>
            <p class="erp-page-sub">Hash-chained, tamper-evident record of every auditable action (Rule 16).</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            @if ($chainStatus === true)
                <span class="erp-status erp-status-active" title="Every row hash matches its predecessor">chain verified</span>
            @elseif ($chainStatus === false)
                <span class="erp-status erp-status-rejected" title="A row hash does not match — investigate immediately">chain broken</span>
            @else
                <span class="erp-chip erp-chip-soft" title="Verification skipped for large logs — run php artisan erp:chain-verify">verify via CLI</span>
            @endif
            @if ($perm('audit.export'))
                <a class="btn btn-outline-secondary"
                   href="{{ route('audit.export', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'action' => $filters['action'] ?? null, 'entity_type' => $filters['entity_type'] ?? null])) }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                </a>
            @endif
        </div>
    </div>

    <form class="row g-2 mb-3" method="GET" action="{{ route('audit.index') }}">
        <div class="col-sm-4 col-md-2">
            <select class="form-select" name="action" aria-label="Action">
                <option value="">Any action</option>
                @foreach($knownActions as $knownAction)
                    <option value="{{ $knownAction }}" @selected(($filters['action'] ?? '') === $knownAction)>{{ $knownAction }}</option>
                @endforeach
                @if(($filters['action'] ?? '') && ! in_array($filters['action'], $knownActions, true))
                    <option value="{{ $filters['action'] }}" selected>{{ $filters['action'] }}</option>
                @endif
            </select>
        </div>
        <div class="col-sm-4 col-md-2">
            <input class="form-control" name="entity_type" value="{{ $filters['entity_type'] ?? '' }}" placeholder="Entity type">
        </div>
        <div class="col-sm-4 col-md-2">
            <input class="form-control" name="result" value="{{ $filters['result'] ?? '' }}" placeholder="Result">
        </div>
        <div class="col-sm-4 col-md-2">
            <input class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="From date">
        </div>
        <div class="col-sm-4 col-md-2">
            <input class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="To date">
        </div>
        <div class="col-sm-4 col-md-2">
            <input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search reason…">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
        <div class="col-auto">
            <a class="btn btn-link" href="{{ route('audit.index') }}">Reset</a>
        </div>
    </form>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table mb-0">
                <thead>
                    <tr>
                        <th class="text-end">Seq</th>
                        <th>When</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Actor</th>
                        <th>Result</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td class="text-end"><code>{{ $event->seq }}</code></td>
                            <td class="text-body-secondary">{{ $event->created_at?->format('d M Y, H:i:s') }}</td>
                            <td><a class="text-decoration-none fw-semibold" href="{{ route('audit.show', $event) }}">{{ $event->action }}</a></td>
                            <td class="text-body-secondary">{{ $event->entity_type }}@if($event->entity_id)#{{ $event->entity_id }}@endif</td>
                            <td>{{ $event->actor_type === 'user' ? ($event->actor_id ?: '—') : $event->actor_type }}</td>
                            <td><span class="erp-status erp-status-{{ $event->result }}">{{ $event->result }}</span></td>
                            <td class="text-body-secondary">{{ \Illuminate\Support\Str::limit($event->reason, 60) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4 text-body-secondary">No audit events match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $events->links() }}</div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
