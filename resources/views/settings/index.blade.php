@extends('layouts.app')

@section('page_title', 'Settings')

@section('content')
    @php
        $openable = collect($groups)->where('allowed', true)->count();
        $configured = collect($groups)->sum('set');
        $overrideRows = collect($groups)->sum('overrides');
    @endphp

    <x-ui.page-header
        eyebrow="Settings"
        title="What this company has decided"
        subtitle="One row per group of settings, with how many of its values are actually set rather than left at their default, who last touched them, and the key each group needs. Nothing here is decorative: every group on this page is read somewhere in the application — a value nobody reads would be a promise the screen cannot keep."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('settings.branches') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> Branch settings
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('settings.show', 'general') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> General
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Setting groups"
            value="{{ count($groups) }}"
            icon="bi-sliders"
            :hint="$openable.' of them are open to you'"
            :delta="$openable < count($groups) ? (count($groups) - $openable).' need another key' : null" />
        <x-ui.kpi
            label="Values set"
            value="{{ $configured }}"
            icon="bi-toggle-on"
            hint="Explicit rows in the settings table — everything else is still its declared default" />
        <x-ui.kpi
            label="Branch overrides"
            value="{{ $overrideRows }}"
            icon="bi-diagram-3"
            :hint="$branchCount.' branch(es); display and document settings may differ between them'"
            :delta="$overrideRows > 0 ? 'company values are untouched' : null" />
        <x-ui.kpi
            label="Invariant floors"
            value="{{ collect($floors)->sum(fn ($group) => count($group)) }}"
            icon="bi-shield-lock"
            hint="Numbers this system will not go below, whatever a form says" />
    </div>

    <div class="erp-note mb-3">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <div>
            <strong class="d-block mb-1">Some values are floors, not preferences</strong>
            A password minimum, a lockout threshold, the audit retention window: these are refused below a
            fixed floor whichever screen asks, and the refusal is written to the audit trail. Company policy
            (security, audit, workflow, notifications) also cannot be overridden per branch — money and access
            decisions are the same in every outlet.
        </div>
    </div>

    <div class="erp-table-shell" data-erp-table>
        <div class="erp-card-head px-3 pt-3">
            <h2 class="erp-card-title">
                The groups
                <span class="erp-chip erp-chip-outline">{{ count($groups) }} group(s)</span>
            </h2>
        </div>
        <div class="erp-table-scroll">
            <table class="erp-table erp-table-stack">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th>What it decides</th>
                        <th class="erp-th-num">Fields</th>
                        <th class="erp-th-num">Set</th>
                        <th>Scope</th>
                        <th>Last changed</th>
                        <th>Permission</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $key => $group)
                        <tr>
                            <td>
                                <span class="erp-cell-strong">{{ $group['label'] }}</span>
                                <div class="erp-td-muted font-monospace">{{ $key }}</div>
                            </td>
                            <td class="erp-td-muted">{{ $group['description'] }}</td>
                            <td class="erp-td-num">{{ $group['fields'] }}</td>
                            <td class="erp-td-num">
                                {{ $group['set'] }}
                                @if ($group['set'] === 0)
                                    <div class="erp-td-muted">defaults</div>
                                @endif
                            </td>
                            <td>
                                @if ($group['branch_scoped'])
                                    <span class="erp-chip erp-chip-soft">per branch</span>
                                    @if ($group['branches'] > 0)
                                        <div class="erp-td-muted">{{ $group['branches'] }} branch(es) differ</div>
                                    @endif
                                @else
                                    <span class="erp-chip erp-chip-outline">company policy</span>
                                @endif
                            </td>
                            <td class="erp-td-muted">
                                @if ($group['last_changed_at'])
                                    {{ $group['last_changed_at']->format('Y-m-d H:i') }}
                                    <div class="erp-td-muted">{{ $group['last_changed_by'] ?? 'system' }}</div>
                                @else
                                    never — still on defaults
                                @endif
                            </td>
                            <td class="font-monospace erp-td-muted">{{ $group['permission'] }}</td>
                            <td class="text-end">
                                @if ($group['allowed'])
                                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('settings.show', $key) }}">
                                        Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    </a>
                                @else
                                    <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> locked</span>
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
                “Set” counts the values this company has actually chosen. A group that reads
                <strong>never — still on defaults</strong> has never been edited, which is a useful thing to know
                before changing one of its numbers: whatever is in force there came from the system's own defaults,
                not from a decision somebody made.
            </p>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
