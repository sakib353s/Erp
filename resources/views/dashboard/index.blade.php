@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
    @php
        $done = collect($checklist)->filter(fn ($step) => $step['done'])->count();
        $total = max(1, count($checklist));
        $progress = (int) round($done / $total * 100);

        // Widgets arrive permission-filtered from DashboardController. The grid
        // renders every container the user may see (D22: 25 containers); the
        // command strip below surfaces the ones with real data today.
        $realWidgets = collect($widgets)->filter(fn ($w) => in_array($w->code, ['pending_approvals', 'branch_activity'], true));
    @endphp

    <x-ui.page-header
        eyebrow="My work"
        title="Dashboard"
        :subtitle="$branch
            ? 'Live position for '.$branch->name.($warehouse ? ' · '.$warehouse->name : '').'. Every figure below is a server query — nothing on this page is a fixture.'
            : 'Live position for this workspace. Every figure below is a server query — nothing on this page is a fixture.'">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('search.index') }}">
                <i class="bi bi-search" aria-hidden="true"></i> Search workspace
            </a>
            @if (! $checklistComplete)
                <a class="btn btn-primary" href="#onboarding">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Finish setup
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- KPI strip: only containers whose data source is wired render a number.
         Unwired modules say so instead of showing an invented figure. --}}
    <section class="erp-kpi-grid" aria-label="Key figures">
        <a class="erp-kpi text-decoration-none" href="{{ route('approvals.index') }}">
            <p class="erp-kpi-label"><i class="bi bi-inbox" aria-hidden="true"></i>Open approvals</p>
            <p class="erp-kpi-value">{{ $realData['pending_approvals'] }}</p>
            <span class="erp-kpi-delta {{ $realData['pending_approvals'] > 0 ? 'down' : 'up' }}">
                <i class="bi bi-{{ $realData['pending_approvals'] > 0 ? 'hourglass' : 'check2' }}" aria-hidden="true"></i>
                {{ $realData['pending_approvals'] > 0 ? 'Awaiting a decision' : 'Queue is clear' }}
            </span>
        </a>

        <div class="erp-kpi">
            <p class="erp-kpi-label"><i class="bi bi-list-check" aria-hidden="true"></i>Setup progress</p>
            <p class="erp-kpi-value">{{ $progress }}%</p>
            <div class="erp-progress" role="img" aria-label="{{ $done }} of {{ $total }} setup steps complete">
                <span style="width: {{ $progress }}%"></span>
            </div>
            <p class="erp-kpi-foot">{{ $done }} of {{ $total }} first-run steps complete</p>
        </div>

        <div class="erp-kpi">
            <p class="erp-kpi-label"><i class="bi bi-activity" aria-hidden="true"></i>Recorded activity</p>
            <p class="erp-kpi-value">{{ $realData['recent_activity']->count() }}</p>
            <span class="erp-kpi-delta">
                <i class="bi bi-shield-check" aria-hidden="true"></i> latest audit entries
            </span>
            <p class="erp-kpi-foot">Append-only trail — created_at alone is never the audit record.</p>
        </div>

        <div class="erp-kpi">
            <p class="erp-kpi-label"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Available panels</p>
            <p class="erp-kpi-value">{{ $widgets->count() }}</p>
            <span class="erp-kpi-delta">
                <i class="bi bi-lock" aria-hidden="true"></i> filtered by your permissions
            </span>
            <p class="erp-kpi-foot">A panel appears once its module records real transactions.</p>
        </div>
    </section>

    @if (! $checklistComplete)
        <section class="erp-card mb-3" id="onboarding">
            <header class="erp-card-head">
                <h2 class="erp-card-title">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Onboarding checklist
                </h2>
                <div class="erp-checklist-progress">
                    <div class="erp-progress"><span style="width: {{ $progress }}%"></span></div>
                    <span class="erp-chip erp-chip-soft">{{ $done }}/{{ $total }}</span>
                </div>
            </header>
            <ul class="erp-checklist">
                @foreach ($checklist as $step)
                    <li class="erp-checklist-item {{ $step['done'] ? 'done' : '' }}">
                        <span class="erp-checklist-mark" aria-hidden="true">
                            <i class="bi {{ $step['done'] ? 'bi-check2-circle' : 'bi-circle' }}"></i>
                        </span>
                        <span class="erp-checklist-label">{{ $step['label'] }}</span>
                        @if (! $step['done'] && $step['route'])
                            <a class="erp-checklist-link" href="{{ $step['route'] }}">
                                Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title"><i class="bi bi-activity" aria-hidden="true"></i> Recent activity</h2>
                    @if ($perm('audit.view'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('audit.index') }}">Full audit trail</a>
                    @endif
                </header>

                @forelse ($realData['recent_activity'] as $activity)
                    <div class="erp-list-row">
                        <div class="erp-list-row-main">
                            <strong>{{ $activity['action'] }}</strong>
                            <span class="erp-td-muted">
                                · {{ $activity['entity_type'] }}@if($activity['entity_id'])#{{ $activity['entity_id'] }}@endif
                            </span>
                        </div>
                        <span class="erp-td-muted small text-nowrap">{{ $activity['created_at']?->diffForHumans() }}</span>
                    </div>
                @empty
                    <x-ui.empty
                        icon="bi-journal-text"
                        title="No activity recorded yet"
                        text="Every posting, approval and permission change lands here with actor and detail. Take a first action and it will appear." />
                @endforelse
            </section>
        </div>

        <div class="col-xl-4">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Quick actions</h2>
                </header>
                <div class="d-flex flex-column gap-2">
                    <a class="btn btn-outline-secondary text-start" href="{{ route('approvals.index') }}">
                        <i class="bi bi-inbox" aria-hidden="true"></i> Review approval queue
                    </a>
                    @if ($perm('sales.orders.view'))
                        <a class="btn btn-outline-secondary text-start" href="{{ route('sales.orders.index') }}">
                            <i class="bi bi-receipt" aria-hidden="true"></i> Sales orders
                        </a>
                    @endif
                    @if ($perm('inventory.products.view'))
                        <a class="btn btn-outline-secondary text-start" href="{{ route('inventory.products.index') }}">
                            <i class="bi bi-boxes" aria-hidden="true"></i> Products &amp; stock
                        </a>
                    @endif
                    @if ($perm('accounting.journals.view'))
                        <a class="btn btn-outline-secondary text-start" href="{{ route('accounting.journals.index') }}">
                            <i class="bi bi-journal-text" aria-hidden="true"></i> Journal entries
                        </a>
                    @endif
                    @if ($perm('settings.view'))
                        <a class="btn btn-outline-secondary text-start" href="{{ route('settings.show', 'general') }}">
                            <i class="bi bi-sliders" aria-hidden="true"></i> Workspace settings
                        </a>
                    @endif
                </div>

                <p class="erp-widget-note mt-3 mb-0">
                    Tip: press <kbd>⌘</kbd><kbd>K</kbd> anywhere to jump to any page you have access to — including pages not shown in the sidebar.
                </p>
            </section>
        </div>
    </div>

    {{-- Container grid. Each panel is a real container: it either reports real
         figures or states plainly that its module has no data yet. --}}
    <header class="erp-card-head mt-4">
        <h2 class="erp-card-title"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Panels</h2>
        <span class="erp-chip erp-chip-outline">{{ $widgets->count() }} available to you</span>
    </header>

    <div class="erp-widget-grid">
        @foreach ($widgets as $widget)
            @php($label = $tr($widget->label_key, $widget->label))
            <article class="erp-widget" data-widget="{{ $widget->code }}">
                <header class="erp-widget-head">
                    <h3 class="erp-widget-title">{{ $label }}</h3>
                    @if ($widget->feature_key)
                        <span class="erp-chip erp-chip-outline">{{ $widget->feature_key }}</span>
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
                                        @forelse ($realData['recent_activity']->take(5) as $activity)
                                            <li>
                                                <code>{{ $activity['action'] }}</code>
                                                <span class="small">
                                                    {{ $activity['entity_type'] }}@if($activity['entity_id'])#{{ $activity['entity_id'] }}@endif
                                                    · <time>{{ $activity['created_at']?->diffForHumans() }}</time>
                                                </span>
                                            </li>
                                        @empty
                                            <li class="small">No audit activity recorded yet.</li>
                                        @endforelse
                                    </ul>
                                </div>
                                <div class="erp-widget-split-side">
                                    <p class="erp-widget-label">Branch comparison</p>
                                    <p class="erp-widget-note">
                                        Fills with real per-branch figures once transactional modules record data —
                                        this container never estimates.
                                    </p>
                                </div>
                            </div>
                        @break

                        @default
                            <x-ui.empty
                                icon="bi-bar-chart"
                                title="No {{ $label }} data yet"
                                text="This panel reports live figures only. It activates as soon as its module records real transactions." />
                    @endswitch
                </div>
            </article>
        @endforeach
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
