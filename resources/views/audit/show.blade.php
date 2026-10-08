@extends('layouts.app')

@section('page_title', 'Audit event')

@section('content')
    <div class="erp-page-head">
        <div>
            <p class="erp-eyebrow mb-1">
                {{ \App\Domain\Audit\AuditVocabulary::moduleLabel($event->action) }}
                @if (\App\Domain\Audit\AuditVocabulary::isSensitive($event->action))
                    · worth somebody's attention
                @endif
            </p>
            <h1 class="erp-h1 mb-1">{{ \App\Domain\Audit\AuditVocabulary::label($event->action) }}</h1>
            <p class="erp-page-sub mb-0">
                <code>{{ $event->action }}</code> · sequence #{{ $event->seq }}
                · {{ $event->created_at?->format('d M Y, H:i:s') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($previous)
                <a class="btn btn-outline-dark" href="{{ route('audit.show', $previous) }}"
                   title="{{ $previous->action }}">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Previous
                </a>
            @endif
            @if ($next)
                <a class="btn btn-outline-dark" href="{{ route('audit.show', $next) }}"
                   title="{{ $next->action }}">
                    Next <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('audit.index') }}">Back to audit log</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Event</h2></header>
                <dl class="erp-dl">
                    <dt>Action</dt><dd><code>{{ $event->action }}</code><div class="erp-page-sub">{{ \App\Domain\Audit\AuditVocabulary::label($event->action) }}</div></dd>
                    <dt>Entity</dt><dd><code>{{ $event->entity_type }}@if($event->entity_id)#{{ $event->entity_id }}@endif</code></dd>
                    <dt>Result</dt><dd><span class="erp-status erp-status-{{ $event->result }}">{{ $event->result }}</span></dd>
                    <dt>Reason</dt><dd>{{ $event->reason ?: '—' }}</dd>
                    <dt>Actor</dt>
                    <dd>
                        {{ $event->actor_type }}
                        @if($event->actor_id)· #{{ $event->actor_id }}@endif
                    </dd>
                    <dt>Branch</dt><dd>{{ $event->branch_id ? '#'.$event->branch_id : 'company-wide' }}</dd>
                    <dt>IP address</dt><dd>{{ $event->ip ?: '—' }}</dd>
                    <dt>Correlation id</dt><dd><code class="small">{{ $event->correlation_id ?: '—' }}</code></dd>
                </dl>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Hash chain</h2>
                    <span class="erp-chip erp-chip-soft">sha256</span>
                </header>
                <dl class="erp-dl erp-dl-tight">
                    <dt>Prev hash</dt>
                    <dd><code class="erp-hash" title="{{ $event->prev_hash }}">{{ $event->prev_hash ?: '(genesis)' }}</code></dd>
                    <dt>Row hash</dt>
                    <dd><code class="erp-hash" title="{{ $event->row_hash }}">{{ $event->row_hash }}</code></dd>
                </dl>
                <p class="form-text mb-0">
                    This row's hash is computed over its own fields plus the hash of sequence
                    {{ max((int) $event->seq - 1, 0) }}, so editing any field here breaks the row and every row after it.
                    Verify the whole chain, and every sealed period, with
                    <code>php artisan erp:chain-verify --company={{ $event->company_id }} --archives</code> — or press
                    <em>Verify now</em> on the log.
                </p>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Before</h2></header>
                <pre class="erp-pre">{{ json_encode($event->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}' }}</pre>
            </section>
            <section class="erp-card mt-3">
                <header class="erp-card-head"><h2 class="erp-card-title">After</h2></header>
                <pre class="erp-pre">{{ json_encode($event->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}' }}</pre>
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
