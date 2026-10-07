@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Dashboard</h1>
            <p class="erp-page-sub">
                Real-time foundation overview — every number below is a live query, never a fixture.
            </p>
        </div>
    </div>

    @if (! $checklistComplete)
        <section class="erp-card mb-4">
            <header class="erp-card-head">
                <h2 class="erp-card-title">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Onboarding checklist
                </h2>
                <span class="erp-chip erp-chip-soft">
                    {{ collect($checklist)->filter(fn ($s) => $s['done'])->count() }}/{{ count($checklist) }} complete
                </span>
            </header>
            <ul class="erp-checklist">
                @foreach ($checklist as $step)
                    <li class="erp-checklist-item {{ $step['done'] ? 'done' : '' }}">
                        <span class="erp-checklist-mark" aria-hidden="true">
                            <i class="bi {{ $step['done'] ? 'bi-check2-circle' : 'bi-circle' }}"></i>
                        </span>
                        <span class="erp-checklist-label">{{ $step['label'] }}</span>
                        @if (! $step['done'] && $step['route'])
                            <a class="erp-checklist-link" href="{{ $step['route'] }}">Open <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="erp-widget-grid">
        @foreach ($widgets as $widget)
            @php($label = $tr($widget->label_key, $widget->label))
            <article class="erp-widget" data-widget="{{ $widget->code }}">
                <header class="erp-widget-head">
                    <h3 class="erp-widget-title">{{ $label }}</h3>
                    @if ($widget->feature_key)
                        <span class="erp-chip erp-chip-soft">{{ $widget->feature_key }}</span>
                    @endif
                </header>

                <div class="erp-widget-body">
                    @switch($widget->code)
                        @case('pending_approvals')
                            <p class="erp-widget-metric">{{ $realData['pending_approvals'] }}</p>
                            <p class="erp-widget-note">Requests awaiting a decision across your accessible branches.</p>
                            <a class="erp-widget-link" href="{{ route('approvals.index') }}">
                                Open approval inbox <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @break

                        @case('branch_activity')
                            <div class="erp-widget-split">
                                <div>
                                    <p class="erp-widget-label">Recent activity</p>
                                    <ul class="erp-activity">
                                        @forelse ($realData['recent_activity'] as $activity)
                                            <li>
                                                <code>{{ $activity['action'] }}</code>
                                                <span class="text-body-secondary">
                                                    {{ $activity['entity_type'] }}@if($activity['entity_id'])#{{ $activity['entity_id'] }}@endif
                                                    · <time>{{ $activity['created_at']?->diffForHumans() }}</time>
                                                </span>
                                            </li>
                                        @empty
                                            <li class="text-body-secondary">No audit activity recorded yet.</li>
                                        @endforelse
                                    </ul>
                                </div>
                                <div class="erp-widget-split-side">
                                    <p class="erp-widget-label">Branch comparison</p>
                                    <p class="erp-widget-note">
                                        Fills with real per-branch figures once transactional modules record data.
                                    </p>
                                </div>
                            </div>
                        @break

                        @default
                            <div class="erp-empty">
                                <i class="bi bi-bar-chart" aria-hidden="true"></i>
                                <p>No {{ $label }} data yet.</p>
                                <small>This container reports real figures only — it activates when its module records transactions.</small>
                            </div>
                    @endswitch
                </div>
            </article>
        @endforeach
    </div>
@endsection
