@extends('layouts.app')

@section('page_title', 'Branch Settings')

@section('content')
    @php
        $totalOverrides = collect($branches)->sum('overrides');
        $differing = collect($branches)->where('overrides', '>', 0)->count();
    @endphp

    <x-ui.page-header
        eyebrow="Settings · Branch Settings"
        title="What each branch decides for itself"
        subtitle="Display and document settings can differ between outlets — the receipt footer, the label template, the paper width. What may not differ is policy: who can log in, how long evidence is kept, what has to be approved. Those groups are listed below so you can see where they live, and the screen says why they cannot be overridden rather than hiding them."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('settings.index') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> All settings
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Branches"
            value="{{ $branches->count() }}"
            icon="bi-diagram-3"
            hint="Every branch in this company, whether or not it has its own values" />
        <x-ui.kpi
            label="Branches that differ"
            value="{{ $differing }}"
            icon="bi-shuffle"
            hint="Branches holding at least one value of their own" />
        <x-ui.kpi
            label="Overrides"
            value="{{ $totalOverrides }}"
            icon="bi-list-check"
            hint="Rows stored against a branch instead of the company" />
        <x-ui.kpi
            label="Company policy groups"
            value="{{ count($companyOnly) }}"
            icon="bi-shield-lock"
            hint="{{ implode(', ', $companyOnly) }} — the same in every outlet" />
    </div>

    @if ($totalOverrides > 0)
        <div class="erp-note mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $differing }} branch(es) hold values of their own</strong>
                A branch value replaces the company's for that branch only. Removing it is the way to go back to
                following the company — not writing the company's current number in, which would freeze it at
                today's figure and quietly stop following tomorrow.
            </div>
        </div>
    @endif

    <div class="erp-table-shell mb-3" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">
                The branches
                <span class="erp-chip erp-chip-outline">{{ $branches->count() }} branch(es)</span>
            </h2>
        </div>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Code</th>
                        <th class="erp-th-num">Overrides</th>
                        <th class="erp-th-num">Groups</th>
                        <th>Last changed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($branches as $row)
                        <tr>
                            <td>
                                <span class="erp-cell-strong">{{ $row['branch']->name }}</span>
                                @if ($row['branch']->is_default)
                                    <span class="erp-chip erp-chip-soft">default</span>
                                @endif
                            </td>
                            <td class="erp-td-muted font-monospace">{{ $row['branch']->code }}</td>
                            <td class="erp-td-num">{{ $row['overrides'] }}</td>
                            <td class="erp-td-num">{{ $row['groups'] }}</td>
                            <td class="erp-td-muted">
                                {{ $row['last_changed_at'] ? \Illuminate\Support\Carbon::parse($row['last_changed_at'])->format('Y-m-d H:i') : 'follows the company' }}
                            </td>
                            <td class="text-end">
                                @if ($row['in_scope'])
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('settings.branch.show', $row['branch']) }}">
                                        Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </a>
                                @else
                                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> outside your scope</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty
                                    title="No branches yet"
                                    text="A branch has to exist before it can hold settings of its own."
                                    icon="bi-diagram-3" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 pt-0">
            <p class="erp-filter-note mb-0">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                A branch outside your scope is listed but cannot be opened: reading another outlet's configuration is
                not the same permission as changing it, and neither is granted by accident.
            </p>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
