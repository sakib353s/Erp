@extends('layouts.app')

@section('page_title', @t('setting.'.$group, $definition['label'] ?? 'Settings'))

@section('content')
    @php($allGroups = config('erp.settings.groups', []))

    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">@t('setting.'.$group, $definition['label'] ?? ucfirst($group).' settings')</h1>
            <p class="erp-page-sub">{{ $definition['description'] ?? '' }}</p>
        </div>
    </div>

    <ul class="nav erp-settings-tabs mb-3">
        @foreach($allGroups as $groupKey => $groupDef)
            <li class="nav-item">
                <a class="nav-link {{ $groupKey === $group ? 'active' : '' }}"
                   href="{{ route('settings.show', $groupKey) }}">@t('setting.'.$groupKey, $groupDef['label'] ?? ucfirst($groupKey))</a>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('settings.update', $group) }}">
        @csrf

        <section class="erp-card erp-card-max">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">@t('setting.'.$group, $definition['label'] ?? ucfirst($group))</h2>
                    <p class="erp-card-sub">
                        Scope: <strong>{{ $companyOnly ? 'company policy — the same in every branch' : 'per branch may differ' }}</strong>
                        · this group needs <span class="font-monospace">{{ $definition['key'] ?? 'settings.update' }}</span> to read it,
                        <span class="font-monospace">settings.update</span> to change it.
                    </p>
                </div>
                <span class="erp-chip erp-chip-soft">stored in <code>settings</code> table</span>
            </header>

            <div class="row g-3">
                @foreach (($definition['fields'] ?? []) as $key => $meta)
                    @php($type = $meta['type'] ?? 'text')
                    @php($entry = $values[$key] ?? null)
                    @php($stored = is_array($entry) ? ($entry['value'] ?? null) : $entry)
                    @php($value = old("settings.$key", $stored !== null ? $stored : ($meta['default'] ?? null)))
                    @php($label = app(\App\Domain\Foundation\Services\Translator::class)->get('setting.'.$group.'.'.$key, ($meta['label'] ?? ucfirst(str_replace('_', ' ', $key)))))
                    {{-- Every field row carries its key as an anchor: the catalogue's
                         settings leaves deep-link to a single switch (e.g.
                         /app/settings/localization#bengali_numerals), and an anchor
                         that does not exist is a link that silently does nothing.
                         The input keeps its own `setting_<key>` id for the label. --}}
                    <div id="{{ $key }}" class="{{ $type === 'boolean' ? 'col-12' : 'col-md-6' }}">
                        @if ($type === 'boolean')
                            {{-- An unchecked box sends nothing, and a setting that is
                                 never sent is never written: the hidden twin carries the
                                 "off" so a switch can be turned back off. It sits first,
                                 so the checkbox wins when it is on. --}}
                            <input type="hidden" name="settings[{{ $key }}]" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       id="setting_{{ $key }}" name="settings[{{ $key }}]" value="1"
                                       @checked(in_array((string) $value, ['1', 'true', 'on'], true))>
                                <label class="form-check-label" for="setting_{{ $key }}">{{ $label }}</label>
                            </div>
                        @elseif ($type === 'select')
                            <label class="form-label" for="setting_{{ $key }}">{{ $label }}</label>
                            <select class="form-select @error("settings.$key") is-invalid @enderror"
                                    id="setting_{{ $key }}" name="settings[{{ $key }}]">
                                @foreach (($meta['options'] ?? []) as $optValue => $optLabel)
                                    <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                                @endforeach
                            </select>
                        @elseif ($type === 'number')
                            <label class="form-label" for="setting_{{ $key }}">{{ $label }}</label>
                            <input class="form-control @error("settings.$key") is-invalid @enderror" type="number"
                                   id="setting_{{ $key }}" name="settings[{{ $key }}]" value="{{ $value }}"
                                   @isset($meta['min'])min="{{ $meta['min'] }}"@endisset
                                   @isset($meta['max'])max="{{ $meta['max'] }}"@endisset>
                        @else
                            <label class="form-label" for="setting_{{ $key }}">{{ $label }}</label>
                            <input class="form-control @error("settings.$key") is-invalid @enderror" type="text"
                                   id="setting_{{ $key }}" name="settings[{{ $key }}]" value="{{ $value }}">
                        @endif

                        @isset($meta['help'])<div class="form-text">{{ $meta['help'] }}</div>@endisset
                        @error("settings.$key")<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>

            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Save settings
                </button>
                <a class="btn btn-outline-secondary" href="{{ route('settings.show', $group) }}">Reset changes</a>
            </div>
        </section>
    </form>

    @if ($group === 'localization')
        @php($loc = app(\App\Domain\Settings\Services\LocalizationService::class))
        {{-- §15-07: the switches above are only real if the documents obey them,
             so the screen shows what they do by rendering through the same service
             the invoices, the statutory tax invoice and the POS receipt use. This
             is the output, not an illustration of it. --}}
        <section class="erp-card erp-card-max mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What these switches change</h2>
                    <p class="erp-card-sub">Rendered by the service the invoices and the POS receipt render through — save, and the documents change with this sample.</p>
                </div>
                <span class="erp-chip erp-chip-soft">{{ $loc->locale() === 'bn' ? 'বাংলা' : 'English' }}</span>
            </header>
            <dl class="erp-dl erp-dl-tight">
                <dt>Figures</dt>
                <dd class="font-monospace">{{ $loc->number(1234567.5) }}</dd>
                <dt>Quantity</dt>
                <dd class="font-monospace">{{ $loc->qty(12.5) }}</dd>
                <dt>Amount in words</dt>
                <dd>{{ $loc->amountWordsEnabled() ? $loc->words(1234567.5) : 'not printed — the switch is off' }}</dd>
                <dt>বাংলায়</dt>
                <dd>{{ $loc->words(1234567.5, 'bn') }}</dd>
            </dl>
        </section>
    @endif

    @if (! empty($floors))
        <div class="erp-note mt-3">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Some fields in this group have a floor</strong>
                <ul class="mb-0 ps-3">
                    @foreach ($floors as $key => $rule)
                        <li><span class="font-monospace">{{ $key }}</span> — lowest {{ $rule['floor'] }}. {{ $rule['why'] }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if (! $companyOnly)
        <section class="erp-card erp-card-max mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Branches that differ</h2>
                    <p class="erp-card-sub">An outlet may set its own value for this group. The company value stays as it is everywhere else — and a branch can be put back on the company's value at any time.</p>
                </div>
                <div class="erp-card-actions">
                    <a class="erp-chip erp-chip-outline" href="{{ route('settings.branches') }}">
                        <i class="bi bi-diagram-3" aria-hidden="true"></i> Branch settings
                    </a>
                </div>
            </header>
            @if ($branchOverrides === [])
                <div class="p-3">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Every branch currently follows the company value for this group. Nothing has been overridden.
                    </p>
                </div>
            @else
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr><th>Branch</th><th>Keys set there</th><th>Changed</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($branchOverrides as $override)
                                <tr>
                                    <td>
                                        <span class="erp-cell-strong">
                                            {{ \App\Domain\Foundation\Branch::query()->whereKey($override['branch_id'])->value('name') ?? 'Branch #'.$override['branch_id'] }}
                                        </span>
                                    </td>
                                    <td class="erp-td-muted font-monospace">{{ implode(', ', $override['keys']) }}</td>
                                    <td class="erp-td-muted">{{ $override['updated_at'] ? \Illuminate\Support\Carbon::parse($override['updated_at'])->format('Y-m-d H:i') : '—' }}</td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-secondary btn-sm"
                                           href="{{ route('settings.branch.show', $override['branch_id']) }}">
                                            Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
