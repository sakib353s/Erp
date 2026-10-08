@extends('layouts.app')

@section('page_title', strip_tags($family['title']))

@section('content')
    @php
        // Titles and blurbs are written with entities because the centre prints
        // them in three places; decoding once here keeps the escaping that
        // matters (the reader's data) and drops the double-escaping that does not.
        $title = html_entity_decode(strip_tags($family['title']));
        $blurb = html_entity_decode($family['blurb']);
        $locked = $family['built'] - $openable > 0 ? $family['built'] - $openable : 0;
    @endphp

    <x-ui.page-header
        eyebrow="Reports · {{ $title }}"
        :title="$title"
        :subtitle="$blurb.' Every row below is a report this installation really registers, with the question it answers and where its numbers come from; the ones your role does not open are listed with the key they need rather than hidden.'"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('reports.index') }}">
                <i class="bi bi-grid" aria-hidden="true"></i> All families
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('reports.scheduled') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Scheduled
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Reports in this family"
            value="{{ $family['built'] }}"
            icon="{{ $family['icon'] }}"
            hint="Registered routes, counted from the router" />
        <x-ui.kpi
            label="You may open"
            value="{{ $openable }}"
            icon="bi-unlock"
            :hint="$locked > 0 ? $locked.' more are listed below with the key they need' : 'Nothing here is out of reach'" />
        <x-ui.kpi
            label="Needs the hub key"
            value="{{ $family['permission'] }}"
            icon="bi-key"
            hint="The permission this whole page is behind" />
        <x-ui.kpi
            label="Scheduled runs"
            value="{{ $schedules }}"
            icon="bi-clock-history"
            hint="Active schedules whose definitions live in this company" />
    </div>

    @isset($family['planned'])
        <div class="erp-note mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">What is not here yet, and why</strong>
                {{ $family['planned'] }}
            </div>
        </div>
    @endisset

    @if ($family['built'] === 0)
        <x-ui.empty
            title="No reports in this family yet"
            text="Nothing in this family has a registered route, so there is nothing honest to list here. The catalogue leaf exists; the reports behind it do not." />
    @else
        <div class="row row-cols-1 row-cols-xl-2 g-3">
            @foreach ($entries as $entry)
                <div class="col">
                <section class="erp-card erp-card-tight h-100 d-flex flex-column">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">{!! $entry['title'] !!}</h2>
                            <p class="erp-card-sub">{{ $entry['answers'] }}</p>
                        </div>
                        @if (! $entry['allowed'])
                            <span class="erp-chip erp-chip-soft"><i class="bi bi-lock" aria-hidden="true"></i> locked</span>
                        @endif
                    </header>

                    <dl class="erp-dl erp-dl-tight mt-2">
                        <dt class="erp-field-label">Reads</dt>
                        <dd>{{ $entry['reads'] }}</dd>
                        <dt class="erp-field-label">Permission</dt>
                        <dd class="font-monospace mb-0">{{ $entry['permission'] ?? '—' }}</dd>
                    </dl>

                    <div class="mt-auto pt-3 d-flex flex-wrap gap-2">
                        @if ($entry['allowed'])
                            <a class="btn btn-outline-secondary btn-sm" href="{{ $entry['url'] }}">
                                Open <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @elseif ($entry['url'] !== null)
                            <span class="erp-chip erp-chip-soft">
                                Needs <span class="font-monospace ms-1">{{ $entry['permission'] }}</span>
                            </span>
                        @endif

                        @if ($entry['opens_register'])
                            <span class="erp-chip erp-chip-outline">
                                <i class="bi bi-journal-text" aria-hidden="true"></i>
                                opened from the register
                            </span>
                        @endif
                    </div>
                </section>
                </div>
            @endforeach
        </div>
    @endif

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The other families</h2>
                <p class="erp-card-sub">The rest of the centre, with what each one holds.</p>
            </div>
        </header>
        <div class="d-flex flex-wrap gap-2 p-3">
            @foreach ($families as $slug => $familyRow)
                @continue($slug === $family['slug'])
                @if ($familyRow['allowed'])
                    <a class="erp-chip erp-chip-outline" href="{{ route($familyRow['route']) }}">
                        <i class="bi {{ $familyRow['icon'] }}" aria-hidden="true"></i>{!! $familyRow['title'] !!}
                        <span class="erp-td-muted">{{ $familyRow['built'] }}</span>
                    </a>
                @else
                    <span class="erp-chip erp-chip-soft">
                        <i class="bi bi-lock" aria-hidden="true"></i>{!! $familyRow['title'] !!}
                    </span>
                @endif
            @endforeach
        </div>
    </section>

    <x-ui.related-pages />
@endsection
