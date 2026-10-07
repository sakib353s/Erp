@extends('layouts.app')

@section('page_title', 'Leave calendar')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Leave calendar"
        subtitle="Who is away at the same time. Approved leave only — a pending request is a question, not a plan."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.leave') }}">
                <i class="bi bi-list-check" aria-hidden="true"></i> Requests
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('hr.leave.calendar') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="month">Month</label>
            <select class="form-select" id="month" name="month">
                @for ($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($month === $m)>{{ \Carbon\Carbon::create(null, $m, 1)->format('F') }}</option>
                @endfor
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="year">Year</label>
            <input class="form-control" type="number" id="year" name="year" min="2000" max="2100" value="{{ $year }}">
        </div>
        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Load month</button>
        </div>
    </form>

    @if ($holidays->isNotEmpty())
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-calendar-check" aria-hidden="true"></i>
            <div>
                <strong>Declared holidays this month:</strong>
                {{ $holidays->map(fn ($h) => $h->name.' ('.$h->date->format('d M').')')->implode(' · ') }}
            </div>
        </div>
    @endif

    <x-ui.table-shell
        :title="$start->format('F Y')"
        :count="$requests->count().' approved request(s)'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>On leave</th>
                <th>Types</th>
            </tr>
        </thead>
        <tbody>
            @for ($day = $start->copy(); $day <= $end; $day->addDay())
                @php($key = $day->toDateString())
                @php($entries = $leaveByDay->get($key, collect()))
                @php($holiday = $holidays->first(fn ($h) => $h->date->toDateString() === $key))
                <tr class="{{ $holiday ? 'erp-table-opening' : '' }}">
                    <td data-label="Date">{{ $day->format('d M Y') }}</td>
                    <td data-label="Day" class="erp-td-muted">{{ $day->format('D') }}</td>
                    <td data-label="On leave">
                        @if ($holiday)
                            <span class="erp-chip erp-chip-soft">{{ $holiday->name }}</span>
                        @elseif ($entries->isEmpty())
                            <span class="erp-td-muted">—</span>
                        @else
                            <div class="d-flex flex-column gap-1">
                                @foreach ($entries->take(6) as $entry)
                                    <span>
                                        {{ $entry['request']->employee?->full_name ?? 'Removed employee' }}
                                        <span class="erp-td-muted small">· {{ $entry['request']->leaveType?->name }}</span>
                                    </span>
                                @endforeach
                                @if ($entries->count() > 6)
                                    <span class="erp-td-muted small">+ {{ $entries->count() - 6 }} more</span>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td data-label="Types" class="erp-td-muted">
                        @if ($entries->isNotEmpty())
                            {{ $entries->pluck('request.leaveType.name')->filter()->unique()->implode(', ') }}
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @endfor
        </tbody>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
