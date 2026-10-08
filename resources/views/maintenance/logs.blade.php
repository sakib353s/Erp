@extends('layouts.app')

@section('page_title', 'Error log')

@php
    use App\Domain\Operations\MaintenanceRun;

    $levelTone = fn (string $level): string => match (true) {
        in_array($level, ['emergency', 'alert', 'critical'], true) => 'erp-chip-warn',
        $level === 'error' => 'erp-chip-warn',
        $level === 'warning' => 'erp-chip-outline',
        default => 'erp-chip-soft',
    };
@endphp

@section('content')
    <x-ui.page-header
        eyebrow="Settings · System maintenance"
        title="Error log"
        subtitle="The newest entries of the application log. Every line is masked before it reaches this page — passwords, tokens, keys, card numbers and mailbox names are replaced — and the entry says how many replacements it made rather than pretending the text was never touched."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('maintenance.index') }}">
                <i class="bi bi-tools" aria-hidden="true"></i> Maintenance
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="row row-cols-1 row-cols-xl-3 g-3 mb-3">
        <div class="col-xl-2">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Log files</h2>
                </header>
                <div class="p-3">
                    @forelse ($files as $candidate)
                        <a class="d-flex justify-content-between align-items-center py-1 text-decoration-none {{ ($file ?? 'laravel.log') === $candidate['name'] ? 'erp-cell-strong' : '' }}"
                           href="{{ route('maintenance.logs', ['file' => $candidate['name']]) }}">
                            <span class="font-monospace">{{ $candidate['name'] }}</span>
                            <span class="erp-td-muted">{{ MaintenanceRun::humanBytes($candidate['bytes']) }}</span>
                        </a>
                        <p class="erp-filter-note mb-1">last written {{ $candidate['modified_human'] }}</p>
                    @empty
                        <p class="erp-filter-note mb-0">
                            <code>storage/logs</code> holds no <code>.log</code> file yet. Nothing has gone wrong loudly
                            enough to be written down — or logging is configured somewhere else, which is worth knowing
                            before an incident.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="col-xl-10">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">{{ $view['file'] }}</h2>
                        <p class="erp-card-sub">
                            {{ number_format($view['scanned']) }} entr(ies) read ·
                            {{ number_format($summary['total']) }} in the newest part of the file
                            @foreach ($summary['levels'] as $name => $count)
                                · {{ $name }} {{ number_format($count) }}
                            @endforeach
                        </p>
                    </div>
                </header>
                <div class="p-3">
                    <form class="row row-cols-1 row-cols-md-4 g-2 align-items-end" method="GET" action="{{ route('maintenance.logs') }}">
                        <input type="hidden" name="file" value="{{ $view['file'] }}">
                        <div class="col">
                            <label class="erp-field-label">Level</label>
                            <select class="form-select" name="level">
                                <option value="">Any level</option>
                                @foreach ($levels as $name)
                                    <option value="{{ $name }}" @selected($level === $name)>{{ ucfirst($name) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col">
                            <label class="erp-field-label">Search after masking</label>
                            <input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="order no, exception, file">
                        </div>
                        <div class="col">
                            <label class="erp-field-label">Show newest</label>
                            <select class="form-select" name="limit">
                                @foreach ([50, 100, 200, 500] as $option)
                                    <option value="{{ $option }}" @selected($limit === $option)>{{ $option }} entries</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col">
                            <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>

    @if ($view['problems'] !== [])
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">How this read was limited</strong>
                <ul class="mb-0">
                    @foreach ($view['problems'] as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($view['entries'] === [])
        <x-ui.empty
            title="Nothing matches"
            text="No entries in the newest part of this file match the level and search given. Widen the filter, or pick another file — an empty result here means the filter excluded everything, not that the log is empty." />
    @else
        @foreach ($view['entries'] as $entry)
            <section class="erp-card mb-2">
                <header class="erp-card-head">
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip {{ $levelTone($entry['level']) }}">{{ $entry['level'] }}</span>
                        <span class="erp-td-muted">{{ $entry['at'] }}</span>
                        @if ($entry['masked'] > 0)
                            <span class="erp-chip erp-chip-outline" title="Values replaced before display">
                                {{ $entry['masked'] }} value(s) masked
                            </span>
                        @endif
                    </div>
                </header>
                <div class="p-3">
                    <pre class="erp-pre mb-0">{{ $entry['message'] }}</pre>

                    @if ($entry['trace'] !== null)
                        <details class="mt-2">
                            <summary class="erp-filter-note">Stack trace</summary>
                            <pre class="erp-pre mt-2 mb-0">{{ $entry['trace'] }}</pre>
                        </details>
                    @endif
                </div>
            </section>
        @endforeach
    @endif

    <div class="erp-note mt-3">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <div>
            <strong class="d-block mb-1">What is masked, and what is not</strong>
            Passwords, tokens, API and encryption keys, bearer and basic auth headers, credentials inside DSNs,
            card-like numbers, mailbox names and Bangladeshi mobile numbers are replaced. Dates, levels, exception
            messages, file paths and stack frames are kept exactly as written — a viewer that hid the reason an error
            happened would send people back to <code>cat</code> on the raw file, which is worse for everybody.
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
