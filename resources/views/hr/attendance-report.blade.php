@extends('layouts.app')

@section('page_title', $employee->full_name.' — attendance')

@section('content')
    <x-ui.page-header
        :eyebrow="'People & payroll · '.$employee->code"
        :title="$employee->full_name.' — attendance'"
        :subtitle="($employee->displayDesignation() ?? 'No designation').' · '.($employee->displayDepartment() ?? 'No department').' · joined '.\Carbon\Carbon::parse($employee->joining_date)->format('d M Y')"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.service-book', $employee) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Service book
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('hr.attendance.summary') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Summary
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Present" :value="number_format($totals['present'] + $totals['late'])" icon="bi-check2-circle" :hint="$year" />
        <x-ui.kpi label="Absent" :value="number_format($totals['absent'])" icon="bi-x-octagon" hint="Recorded as not present" />
        <x-ui.kpi label="Absences without leave" :value="number_format($unapplied)" icon="bi-question-octagon" hint="No approved request behind them" />
        <x-ui.kpi label="Leave days" :value="number_format($totals['leave'])" icon="bi-airplane" hint="Approved leave recorded" />
        <x-ui.kpi label="Late minutes" :value="number_format($lateMinutes)" icon="bi-alarm" hint="Shift start + grace" />
        <x-ui.kpi label="Overtime" :value="number_format($overtimeMinutes).' min'" icon="bi-plus-circle" hint="Beyond shift end" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('hr.attendance.report', $employee) }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="year">Year</label>
            <input class="form-control" type="number" id="year" name="year" min="2000" max="2100" value="{{ $year }}">
        </div>
        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        </div>
    </form>

    @forelse ($byMonth as $month => $records)
        <x-ui.table-shell
            :title="\Carbon\Carbon::createFromFormat('Y-m', $month)->format('F Y')"
            :count="$records->count().' recorded days'">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Status</th>
                    <th>In</th>
                    <th>Out</th>
                    <th class="erp-th-num">Late</th>
                    <th class="erp-th-num">Worked</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    <tr>
                        <td data-label="Date">{{ $record->date->format('d M Y') }}</td>
                        <td data-label="Day" class="erp-td-muted">{{ $record->date->format('D') }}</td>
                        <td data-label="Status"><x-ui.status :value="$record->status" /></td>
                        <td data-label="In" class="erp-td-num">{{ $record->check_in ?? '—' }}</td>
                        <td data-label="Out" class="erp-td-num">{{ $record->check_out ?? '—' }}</td>
                        <td data-label="Late" class="erp-td-num">{{ $record->late_minutes > 0 ? $record->late_minutes.' min' : '—' }}</td>
                        <td data-label="Worked" class="erp-td-num">
                            {{ $record->workedMinutes() > 0 ? sprintf('%dh %02dm', intdiv($record->workedMinutes(), 60), $record->workedMinutes() % 60) : '—' }}
                        </td>
                        <td data-label="Note" class="erp-td-muted">
                            {{ $record->reason ?? '—' }}
                            @if ($record->source !== 'manual')<span class="erp-chip erp-chip-outline">{{ $record->source }}</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @empty
        <x-ui.empty icon="bi-calendar-x" title="No attendance recorded in {{ $year }}"
                    text="Nothing has been entered for this employee this year — the day sheet is where that starts." />
    @endforelse

    <x-ui.related-pages />
@endsection
