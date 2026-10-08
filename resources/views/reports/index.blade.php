@extends('layouts.app')

@section('page_title', 'Reports')

@section('content')
    <x-ui.page-header
        eyebrow="Reports"
        title="Every report this system can actually open"
        subtitle="One page per family the catalogue names, and nothing on it that does not exist: these counts are read out of the router, so a report appears here the moment its route is registered — and a report nobody has built yet cannot be listed at all. Where a family is thin, the hub says what is missing and why instead of leaving a blank card."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('reports.custom') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> Custom reports
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('reports.scheduled') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Families"
            value="{{ count($families) }}"
            icon="bi-diagram-3"
            hint="One page each, with its own permission — reading the sales reports is not reading the payroll" />
        <x-ui.kpi
            label="Reports reachable"
            value="{{ $total }}"
            icon="bi-file-earmark-bar-graph"
            hint="Routes this installation really registers, counted from the router" />
        <x-ui.kpi
            label="You may open"
            value="{{ collect($families)->sum('openable') }}"
            icon="bi-unlock"
            hint="The rest are listed on their hubs with the key they need — none are hidden" />
        <x-ui.kpi
            label="Scheduled"
            value="{{ $schedules ?? 0 }}"
            icon="bi-clock-history"
            hint="Reports that run themselves and file what they produced" />
    </div>

    @if (count($dangling) > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ count($dangling) }} catalogue report(s) name a route this installation does not have</strong>
                They are not listed on any hub, because a link to a page that does not exist is worse than no link.
                This is what the registry found: {{ implode(' · ', $dangling) }}
            </div>
        </div>
    @endif

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
        @foreach ($families as $family)
            <div class="col">
            <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">
                            <i class="bi {{ $family['icon'] }}" aria-hidden="true"></i> {!! $family['title'] !!}
                        </h2>
                        <p class="erp-card-sub">{!! $family['blurb'] !!}</p>
                    </div>
                </header>

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <span class="erp-chip erp-chip-outline">{{ $family['built'] }} report(s)</span>
                    @if ($family['built'] > 0 && $family['openable'] < $family['built'])
                        <span class="erp-chip erp-chip-warn">{{ $family['built'] - $family['openable'] }} need another key</span>
                    @endif
                    @isset($family['planned'])
                        <span class="erp-chip erp-chip-soft">still growing</span>
                    @endisset
                </div>

                @isset($family['planned'])
                    <p class="erp-filter-note mt-2 mb-0">{{ $family['planned'] }}</p>
                @endisset

                <div class="mt-auto pt-3 d-flex gap-2">
                    @if ($family['allowed'])
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route($family['route']) }}">
                            Open the hub <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    @else
                        {{-- Honest, not hidden: the hub exists, the reader just cannot open it. --}}
                        <span class="erp-chip erp-chip-soft">
                            <i class="bi bi-lock" aria-hidden="true"></i> needs {{ $family['permission'] }}
                        </span>
                    @endif
                </div>
            </section>
            </div>
        @endforeach
    </div>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">About these pages</h2>
                <p class="erp-card-sub">Two families in the catalogue have no reports at all yet — they are here, and they say so.</p>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Family</th>
                        <th>Permission</th>
                        <th class="erp-th-num">Reports</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($families as $family)
                        <tr>
                            <td><span class="erp-cell-strong">{!! $family['title'] !!}</span></td>
                            <td class="erp-td-muted font-monospace">{{ $family['permission'] }}</td>
                            <td class="erp-td-num">{{ $family['built'] }}</td>
                            <td>
                                @if ($family['built'] === 0)
                                    <span class="erp-status erp-status-draft">nothing to report yet</span>
                                @elseif (isset($family['planned']))
                                    <span class="erp-status erp-status-active">partly built</span>
                                @else
                                    <span class="erp-status erp-status-posted">built</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3 pt-0">
            <p class="erp-filter-note mb-0">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                A report whose route needs an argument — one account, one customer, one employee — is listed on its hub with the
                <strong>register</strong> it is opened from, because a hub can only link to things that open by themselves.
            </p>
        </div>
    </section>

    <x-ui.related-pages />
@endsection
