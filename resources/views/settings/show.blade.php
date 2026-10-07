@extends('layouts.app')

@section('page_title', $definition['label'] ?? 'Settings')

@section('content')
    @php($allGroups = config('erp.settings.groups', []))

    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $definition['label'] ?? ucfirst($group).' settings' }}</h1>
            <p class="erp-page-sub">{{ $definition['description'] ?? '' }}</p>
        </div>
    </div>

    <ul class="nav erp-settings-tabs mb-3">
        @foreach($allGroups as $groupKey => $groupDef)
            <li class="nav-item">
                <a class="nav-link {{ $groupKey === $group ? 'active' : '' }}"
                   href="{{ route('settings.show', $groupKey) }}">{{ $groupDef['label'] ?? ucfirst($groupKey) }}</a>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('settings.update', $group) }}">
        @csrf

        <section class="erp-card erp-card-max">
            <header class="erp-card-head">
                <h2 class="erp-card-title">{{ $definition['label'] ?? ucfirst($group) }}</h2>
                <span class="erp-chip erp-chip-soft">stored in <code>settings</code> table</span>
            </header>

            <div class="row g-3">
                @foreach (($definition['fields'] ?? []) as $key => $meta)
                    @php($type = $meta['type'] ?? 'text')
                    @php($entry = $values[$key] ?? null)
                    @php($stored = is_array($entry) ? ($entry['value'] ?? null) : $entry)
                    @php($value = old("settings.$key", $stored !== null ? $stored : ($meta['default'] ?? null)))
                    @php($label = ($meta['label'] ?? ucfirst(str_replace('_', ' ', $key))))
                    <div class="{{ $type === 'boolean' ? 'col-12' : 'col-md-6' }}">
                        @if ($type === 'boolean')
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
@endsection
