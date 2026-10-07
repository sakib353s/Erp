@extends('layouts.app')

@section('page_title', 'Attendance')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Attendance"
        subtitle="One row per employee per day. Corrections to a saved record need a reason — attendance is the evidence payroll deducts against."
        :pin="true">
        <x-slot:actions>
            @if ($perm('attendance.report'))
                <a class="btn btn-outline-secondary" href="{{ route('hr.attendance.summary', request()->query()) }}">
                    <i class="bi bi-calendar3" aria-hidden="true"></i> Monthly summary
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('hr.leave') }}">
                <i class="bi bi-airplane" aria-hidden="true"></i> Leave
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($isHoliday)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                <strong>{{ \Carbon\Carbon::parse($date)->format('d M Y') }} is a declared holiday.</strong>
                Anyone who still worked can be marked present individually; a holiday row means "not due to work".
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('hr.attendance') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="date">Date</label>
            <input class="form-control" type="date" id="date" name="date" value="{{ \Carbon\Carbon::parse($date)->toDateString() }}">
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
        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Load day</button>
        </div>
    </form>

    @if ($perm('attendance.manage'))
        <div class="erp-card mb-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Day-level record</h2>
                <span class="erp-chip erp-chip-outline">{{ \Carbon\Carbon::parse($date)->format('d M Y') }}</span>
            </div>
            <form method="POST" action="{{ route('hr.attendance.non-working') }}" class="erp-split px-3 pb-3">
                @csrf
                <input type="hidden" name="date" value="{{ \Carbon\Carbon::parse($date)->toDateString() }}">
                <input type="hidden" name="branch" value="{{ $branchId }}">
                <div class="erp-help flex-grow-1">
                    Recording a full non-working day writes a row for every active employee who has nothing recorded yet
                    ({{ $weekOffCount }} in scope). It never overwrites a row somebody already saved.
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary" name="status" value="holiday" type="submit">
                        <i class="bi bi-calendar-x" aria-hidden="true"></i> Mark holiday
                    </button>
                    <button class="btn btn-outline-secondary" name="status" value="weekend" type="submit">
                        <i class="bi bi-calendar-week" aria-hidden="true"></i> Mark weekly off
                    </button>
                </div>
            </form>
        </div>
    @endif

    @error('attendance')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <x-ui.table-shell :count="$sheet->count().' employees'" title="Day sheet">
        <x-slot:tools>
            <span class="erp-help">Status saves per row — a saved row is corrected, never duplicated.</span>
        </x-slot:tools>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Recorded</th>
                @if ($perm('attendance.manage'))<th class="erp-th-actions">Mark</th>@endif
            </tr>
        </thead>
        <tbody>
            @forelse ($sheet as $row)
                @php($employee = $row['employee'])
                @php($record = $row['attendance'])
                <tr>
                    <td data-label="Employee">
                        <span class="erp-cell-strong">{{ $employee->full_name }}</span>
                        <span class="erp-td-muted d-block small">{{ $employee->code }} · {{ $employee->displayDesignation() ?? 'No designation' }}</span>
                    </td>
                    <td data-label="Department" class="erp-td-muted">{{ $employee->displayDepartment() ?? '—' }}</td>
                    <td data-label="Recorded">
                        @if ($record)
                            <x-ui.status :value="$record->status" />
                            @if ($record->check_in || $record->check_out)
                                <span class="erp-td-muted d-block small">{{ $record->check_in ?? '—' }} → {{ $record->check_out ?? '—' }}</span>
                            @endif
                            @if ($record->late_minutes > 0)
                                <span class="erp-td-muted d-block small">{{ $record->late_minutes }} min late</span>
                            @endif
                            @if ($record->reason)
                                <span class="erp-td-muted d-block small">“{{ $record->reason }}”</span>
                            @endif
                        @else
                            <span class="erp-status erp-status-planned">not recorded</span>
                        @endif
                    </td>
                    @if ($perm('attendance.manage'))
                        <td data-label="Mark" class="erp-td-actions">
                            <form method="POST" action="{{ route('hr.attendance.mark') }}" class="erp-inline-form">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                                <input type="hidden" name="date" value="{{ \Carbon\Carbon::parse($date)->toDateString() }}">
                                <select class="form-select form-select-sm" name="status" aria-label="Status for {{ $employee->full_name }}">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected($record?->status === $status)>{{ str_replace('_', ' ', $status) }}</option>
                                    @endforeach
                                </select>
                                <input class="form-control form-control-sm erp-input-time" type="time" name="check_in"
                                       value="{{ $record?->check_in ?? $employee->shift_start }}" aria-label="Check in time">
                                <input class="form-control form-control-sm erp-input-time" type="time" name="check_out"
                                       value="{{ $record?->check_out ?? $employee->shift_end }}" aria-label="Check out time">
                                <input class="form-control form-control-sm" type="text" name="reason" maxlength="500"
                                       placeholder="{{ $record ? 'Reason for correction' : 'Note (optional)' }}" aria-label="Reason">
                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $perm('attendance.manage') ? 4 : 3 }}">
                        <x-ui.empty icon="bi-people" title="No active employees in scope"
                                    text="Create employees, or widen the branch filter, before marking attendance." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <x-ui.related-pages />
@endsection
