@extends('layouts.app')

@section('page_title', 'Attendance summary')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Attendance summary"
        subtitle="Month-wise counts per employee: present, late, absent, leave — plus the two numbers HR must chase: absences with no approved leave, and unexplained lateness."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.attendance') }}">
                <i class="bi bi-calendar-day" aria-hidden="true"></i> Day sheet
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @php($totals = $summary['totals'])
    @php($monthLabel = \Carbon\Carbon::create($year, $month, 1)->format('F Y'))

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Present days" :value="number_format($totals['present'] + $totals['late'])" icon="bi-check2-circle" :hint="$monthLabel" />
        <x-ui.kpi label="Late arrivals" :value="number_format($totals['late'])" icon="bi-alarm" hint="Present but past shift start + grace" />
        <x-ui.kpi label="Absences without leave" :value="number_format($totals['unapplied_absence'])" icon="bi-question-octagon" hint="No approved request behind the absence" />
        <x-ui.kpi label="Leave days" :value="number_format($totals['leave'])" icon="bi-airplane" hint="Approved leave recorded" />
        <x-ui.kpi label="Late minutes" :value="number_format($totals['late_minutes'])" icon="bi-hourglass-split" hint="Total across the month" />
        <x-ui.kpi label="Overtime" :value="number_format($totals['overtime_minutes']).' min'" icon="bi-plus-circle" hint="Recorded beyond shift" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('hr.attendance.summary') }}" role="search">
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
        <div class="erp-filter">
            <label class="form-label" for="branch">Branch</label>
            <select class="form-select" id="branch" name="branch">
                <option value="all" @selected($branchId === null)>All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($branchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="department">Department</label>
            <select class="form-select" id="department" name="department">
                <option value="">All departments</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($departmentId === $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        </div>
    </form>

    @if ($totals['unapplied_absence'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>{{ $totals['unapplied_absence'] }} absence day(s) have no approved leave behind them.</strong>
                Either the employee filed nothing (deduct, or start a disciplinary note), or a request is still pending —
                approving it will reconcile the days retroactively.
                <a href="{{ route('hr.leave') }}">Review leave requests</a>.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="count($summary['rows']).' employees'" title="{{ $monthLabel }}">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th class="erp-th-num">Present</th>
                <th class="erp-th-num">Late</th>
                <th class="erp-th-num">Absent</th>
                <th class="erp-th-num">Leave</th>
                <th class="erp-th-num">Half day</th>
                <th class="erp-th-num">Unapplied</th>
                <th class="erp-th-num">Late min</th>
                <th class="erp-th-num">Recorded / working</th>
                <th class="erp-th-actions">Report</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($summary['rows'] as $row)
                <tr>
                    <td data-label="Employee">
                        <span class="erp-cell-strong">{{ $row['employee']->full_name }}</span>
                        <span class="erp-td-muted d-block small">{{ $row['employee']->code }}</span>
                    </td>
                    <td data-label="Department" class="erp-td-muted">{{ $row['employee']->displayDepartment() ?? '—' }}</td>
                    <td data-label="Present" class="erp-td-num">{{ $row['present'] }}</td>
                    <td data-label="Late" class="erp-td-num">{{ $row['late'] > 0 ? $row['late'] : '—' }}</td>
                    <td data-label="Absent" class="erp-td-num">{{ $row['absent'] > 0 ? $row['absent'] : '—' }}</td>
                    <td data-label="Leave" class="erp-td-num">{{ $row['leave'] > 0 ? $row['leave'] : '—' }}</td>
                    <td data-label="Half day" class="erp-td-num">{{ $row['half_day'] > 0 ? $row['half_day'] : '—' }}</td>
                    <td data-label="Unapplied" class="erp-td-num">
                        @if ($row['unapplied_absence'] > 0)
                            <span class="erp-amount erp-amount-danger">{{ $row['unapplied_absence'] }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Late min" class="erp-td-num">{{ $row['late_minutes'] > 0 ? $row['late_minutes'] : '—' }}</td>
                    <td data-label="Recorded / working" class="erp-td-num">
                        <span class="{{ $row['recorded_days'] >= $row['working_days'] ? '' : 'erp-td-muted' }}">
                            {{ $row['recorded_days'] }} / {{ $row['working_days'] }}
                        </span>
                    </td>
                    <td data-label="Report" class="erp-td-actions">
                        @if ($perm('attendance.report'))
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('hr.attendance.report', ['employee' => $row['employee'], 'year' => $year]) }}">Open</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11">
                        <x-ui.empty icon="bi-calendar3" title="No employees in scope"
                                    text="Widen the branch or department filter, or add employees first." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
