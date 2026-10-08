@extends('layouts.app')

@section('page_title', $branch->name.' settings')

@section('content')
    @php
        $writable = collect($groups)->reject(fn ($group) => $group['company_only']);
        $policy = collect($groups)->filter(fn ($group) => $group['company_only']);
        $overridden = collect($groups)->sum('overridden');
    @endphp

    <x-ui.page-header
        eyebrow="Settings · Branch Settings"
        title="{{ $branch->name }}"
        subtitle="Each row shows what this branch uses beside the company's own value. A value written here replaces the company's for this branch only; the company value is never touched, and removing an override is how this branch goes back to following it."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('settings.branches') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> All branches
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('settings.index') }}">
                <i class="bi bi-sliders" aria-hidden="true"></i> Company settings
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Branch"
            value="{{ $branch->code }}"
            icon="bi-diagram-3"
            :hint="$branch->name.($branch->is_default ? ' · the default branch' : '')" />
        <x-ui.kpi
            label="Own values"
            value="{{ $overridden }}"
            icon="bi-list-check"
            hint="Settings this branch has decided for itself" />
        <x-ui.kpi
            label="Inherited"
            value="{{ $writable->sum(fn ($group) => count($group['fields'])) - $overridden }}"
            icon="bi-arrow-down-left"
            hint="Values this branch takes from the company" />
        <x-ui.kpi
            label="Policy groups"
            value="{{ $policy->count() }}"
            icon="bi-shield-lock"
            hint="The same in every outlet — listed below, with the reason" />
    </div>

    @if ($overridden > 0)
        <div class="erp-note mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $overridden }} value(s) here come from this branch, not the company</strong>
                If a figure on this branch's documents looks wrong to somebody reading the company settings, this is
                the page that explains it: the branch's own value wins, and the row says so.
            </div>
        </div>
    @endif

    <form method="post" action="{{ route('settings.branch.update', $branch) }}">
        @csrf

        <div class="row row-cols-1 row-cols-xl-2 g-3">
            @foreach ($writable as $key => $group)
                <div class="col">
                    <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                        <header class="erp-card-head">
                            <div>
                                <h2 class="erp-card-title">{{ $group['label'] }}</h2>
                                <p class="erp-card-sub">{{ $group['description'] }}</p>
                            </div>
                            @if ($group['overridden'] > 0)
                                <span class="erp-chip erp-chip-warn">{{ $group['overridden'] }} own value(s)</span>
                            @endif
                        </header>

                        <div class="p-3">
                            @foreach ($group['fields'] as $fieldKey => $field)
                                @php($type = config("erp.settings.groups.{$key}.fields.{$fieldKey}.type", 'text'))
                                @php($meta = config("erp.settings.groups.{$key}.fields.{$fieldKey}", []))
                                @php($inputId = "setting_{$key}_{$fieldKey}")

                                <div class="mb-3">
                                    <label class="erp-field-label d-flex align-items-center gap-2" for="{{ $inputId }}">
                                        {{ $field['label'] }}
                                        @if ($field['overridden'])
                                            <span class="erp-chip erp-chip-soft">this branch</span>
                                        @endif
                                    </label>

                                    @if ($type === 'boolean')
                                        <input type="hidden" name="settings[{{ $key }}][{{ $fieldKey }}]" value="0">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                   id="{{ $inputId }}" name="settings[{{ $key }}][{{ $fieldKey }}]" value="1"
                                                   @checked(in_array((string) $field['value'], ['1', 'true', 'on'], true))>
                                            <label class="form-check-label" for="{{ $inputId }}">
                                                On for {{ $branch->name }}
                                            </label>
                                        </div>
                                    @elseif ($type === 'select')
                                        <select class="form-select" id="{{ $inputId }}" name="settings[{{ $key }}][{{ $fieldKey }}]">
                                            @foreach (($meta['options'] ?? []) as $optValue => $optLabel)
                                                <option value="{{ $optValue }}" @selected((string) $field['value'] === (string) $optValue)>{{ $optLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($type === 'number')
                                        <input class="form-control" type="number" id="{{ $inputId }}"
                                               name="settings[{{ $key }}][{{ $fieldKey }}]" value="{{ $field['value'] }}"
                                               @isset($meta['min'])min="{{ $meta['min'] }}"@endisset
                                               @isset($meta['max'])max="{{ $meta['max'] }}"@endisset>
                                    @else
                                        <input class="form-control" type="text" id="{{ $inputId }}"
                                               name="settings[{{ $key }}][{{ $fieldKey }}]" value="{{ $field['value'] }}">
                                    @endif

                                    <p class="form-text mb-0">
                                        Company value:
                                        <strong>{{ is_bool($field['company_value']) ? ($field['company_value'] ? 'on' : 'off') : ($field['company_value'] ?? '— not set —') }}</strong>
                                        @error("settings.{$key}.{$fieldKey}") <span class="text-danger">{{ $message }}</span> @enderror
                                    </p>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-auto p-3 pt-0">
                            @if ($group['overridden'] > 0)
                                <p class="erp-filter-note mb-0">
                                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                    Remove an own value below to make this branch follow the company again.
                                </p>
                            @endif
                        </div>
                    </section>
                </div>
            @endforeach
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Save {{ $branch->name }}'s values
            </button>
            <span class="erp-filter-note align-self-center mb-0">
                Empty fields mean “follow the company” — they are not stored as blanks.
            </span>
        </div>
    </form>

    @if ($overridden > 0)
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">This branch's own values</h2>
                    <p class="erp-card-sub">Removing one deletes the branch's row, so the company's value takes over again. The history of the change stays in the settings trail.</p>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr><th>Group</th><th>Setting</th><th>Branch value</th><th>Company value</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $key => $group)
                            @foreach ($group['fields'] as $fieldKey => $field)
                                @continue(! $field['overridden'])
                                <tr>
                                    <td class="erp-td-muted">{{ $group['label'] }}</td>
                                    <td><span class="erp-cell-strong">{{ $field['label'] }}</span></td>
                                    <td>
                                        {{ is_bool($field['value']) ? ($field['value'] ? 'on' : 'off') : ($field['value'] ?? '—') }}
                                    </td>
                                    <td class="erp-td-muted">
                                        {{ is_bool($field['company_value']) ? ($field['company_value'] ? 'on' : 'off') : ($field['company_value'] ?? '— not set —') }}
                                    </td>
                                    <td class="text-end">
                                        <form method="post" action="{{ route('settings.branch.forget', [$branch, $key, $fieldKey]) }}"
                                              data-confirm="Make {{ $branch->name }} follow the company value for “{{ $field['label'] }}”? This branch's own value is removed.">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline-secondary btn-sm" type="submit">
                                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Follow the company
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Company policy — the same in every outlet</h2>
                <p class="erp-card-sub">These groups cannot be overridden per branch, and the reason is not tidiness: they are the rules that make the rest of the books trustworthy.</p>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr><th>Group</th><th>What it decides</th><th>Why it is company-wide</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($policy as $key => $group)
                        <tr>
                            <td><span class="erp-cell-strong">{{ $group['label'] }}</span><div class="erp-td-muted font-monospace">{{ $key }}</div></td>
                            <td class="erp-td-muted">{{ $group['description'] }}</td>
                            <td class="erp-td-muted">
                                @if ($key === 'security')
                                    Who may log in and how: a weak outlet would be a weak door into the same books.
                                @elseif ($key === 'audit')
                                    How long evidence is kept. A branch may not shorten the trail its own mistakes are written to.
                                @elseif ($key === 'workflow')
                                    What has to be approved. Approval thresholds are a company's control, not a branch's preference.
                                @else
                                    Which channels notify whom. One branch silencing an alert would silence it for the company.
                                @endif
                            </td>
                            <td class="text-end">
                                <span class="erp-chip erp-chip-soft"><i class="bi bi-shield-lock" aria-hidden="true"></i> company-wide</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <x-ui.related-pages />
@endsection
