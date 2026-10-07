@extends('layouts.app')

@section('page_title', 'Dashboard')

@section('content')
    @php
        $done = collect($checklist)->filter(fn ($step) => $step['done'])->count();
        $total = max(1, count($checklist));
        $progress = (int) round($done / $total * 100);

        // Widgets arrive permission-filtered from DashboardController; each one
        // carries its own metric payload (figure, empty state, or a statement
        // that its module has no source yet). Nothing is estimated.
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

    @php
        // The strip is built from the same payloads the panels use, so a figure
        // can never disagree with the panel it came from.
        $salesMetric = $metrics['todays_sales'] ?? null;
        $cashMetric = $metrics['todays_cash_position'] ?? null;
        $receivableMetric = $metrics['receivable_aging'] ?? null;
        $payableMetric = $metrics['payable_aging'] ?? null;
        $lowMetric = $metrics['low_stock_alert'] ?? null;
        $approvalsMetric = $metrics['pending_approvals'] ?? null;
    @endphp

    {{-- KPI strip: real figures, each one linking to the screen that explains it. --}}
    <section class="erp-kpi-grid" aria-label="Key figures">
        <x-ui.kpi :href="$salesMetric['href'] ?? route('sales.invoices.index')" label="Today's sales" icon="bi-receipt"
                  :value="$salesMetric['primary'] ?? '—'" :hint="$salesMetric['caption'] ?? null" />

        <x-ui.kpi :href="$cashMetric['href'] ?? route('accounting.coa')" label="Cash & bank" icon="bi-cash-stack"
                  :value="$cashMetric['primary'] ?? '—'" :hint="$cashMetric['caption'] ?? null" />

        <x-ui.kpi :href="$receivableMetric['href'] ?? route('customers.due')" label="Receivable outstanding" icon="bi-arrow-down-circle"
                  :value="$receivableMetric['primary'] ?? '—'" hint="Bucketed by each invoice's due date" />

        <x-ui.kpi :href="$payableMetric['href'] ?? route('purchase.payables')" label="Payable outstanding" icon="bi-arrow-up-circle"
                  :value="$payableMetric['primary'] ?? '—'" hint="Bucketed by each bill's due date" />

        <x-ui.kpi :href="route('approvals.index')" label="Open approvals" icon="bi-inbox"
                  :value="$approvalsMetric['primary'] ?? '0'"
                  :hint="$approvalsMetric['caption'] ?? null" />

        <x-ui.kpi :href="$lowMetric['href'] ?? route('inventory.stock')" label="Low stock" icon="bi-graph-down-arrow"
                  :value="$lowMetric['primary'] ?? '—'" :hint="$lowMetric['caption'] ?? null" />

        <div class="erp-kpi">
            <p class="erp-kpi-label"><i class="bi bi-list-check" aria-hidden="true"></i>Setup progress</p>
            <p class="erp-kpi-value">{{ $progress }}%</p>
            <div class="erp-progress" role="img" aria-label="{{ $done }} of {{ $total }} setup steps complete">
                <span style="width: {{ $progress }}%"></span>
            </div>
            <p class="erp-kpi-foot">{{ $done }} of {{ $total }} first-run steps complete</p>
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
                    @php($state = $metrics[$widget->code]['state'] ?? null)
                    <span class="erp-chip {{ match ($state) {
                        'ok' => 'erp-chip-ok',
                        'empty' => 'erp-chip-outline',
                        'unavailable' => 'erp-chip-warn',
                        default => 'erp-chip-outline',
                    } }}">{{ match ($state) {
                        'ok' => 'Live',
                        'empty' => 'No data yet',
                        'unavailable' => 'No source yet',
                        default => 'Not wired',
                    } }}</span>
                </header>

                <div class="erp-widget-body">
                    @php($metric = $metrics[$widget->code] ?? null)

                    @if ($metric === null)
                        <x-ui.empty icon="bi-bar-chart" title="No figure for this panel yet"
                                    text="This container has no metric yet — it will not be filled with an estimate." />
                    @elseif ($metric['state'] === 'ok')
                        @if ($metric['primary'] !== null)
                            <p class="erp-widget-metric">{{ $metric['primary'] }}</p>
                        @endif
                        @if ($metric['caption'])
                            <p class="erp-widget-label">{{ $metric['caption'] }}</p>
                        @endif

                        @if (! empty($metric['rows']))
                            <ul class="erp-activity">
                                @foreach ($metric['rows'] as $row)
                                    <li>
                                        <span class="{{ ! empty($row['muted']) ? 'erp-td-muted' : '' }}">{{ $row['label'] }}</span>
                                        <strong class="erp-widget-split-value">{{ $row['value'] }}</strong>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($metric['series']))
                            <div class="erp-widget-series">
                                @foreach ($metric['series'] as $point)
                                    <div class="erp-widget-bar" title="{{ $point['label'] }}: {{ $point['detail'] ?? $point['value'] }}">
                                        <span style="width: {{ $point['width'] }}%"></span>
                                        <em>{{ $point['short'] ?? $point['label'] }}</em>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($metric['note'])
                            <p class="erp-widget-note">{{ $metric['note'] }}</p>
                        @endif
                    @else
                        <p class="erp-widget-label">{{ $metric['caption'] }}</p>
                        <p class="erp-widget-note">{{ $metric['note'] }}</p>
                    @endif

                    @if (! empty($metric['href']) && ! empty($metric['href_label']))
                        <a class="erp-widget-link" href="{{ $metric['href'] }}">
                            {{ $metric['href_label'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
