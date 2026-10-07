@extends('layouts.app')

@section('page_title', 'Audit event')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $event->action }}</h1>
            <p class="erp-page-sub">Sequence #{{ $event->seq }} · {{ $event->created_at?->format('d M Y, H:i:s') }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('audit.index') }}">Back to audit log</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Event</h2></header>
                <dl class="erp-dl">
                    <dt>Action</dt><dd><code>{{ $event->action }}</code></dd>
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
                    Verify the whole chain with <code>php artisan erp:chain-verify {{ $event->company_id }}</code>.
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
@endsection
