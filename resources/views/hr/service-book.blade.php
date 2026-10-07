@extends('layouts.app')

@section('page_title', $employee->full_name.' — service book')

@section('content')
    <x-ui.page-header
        :eyebrow="'People & payroll · '.$employee->code"
        :title="$employee->full_name"
        :subtitle="($employee->displayDesignation() ?? 'No designation').' · '.($employee->displayDepartment() ?? 'No department')"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.attendance.report', $employee) }}">
                <i class="bi bi-calendar3" aria-hidden="true"></i> Attendance report
            </a>
            @if ($perm('employees.edit'))
                <a class="btn btn-outline-secondary" href="{{ route('employees.edit', $employee) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit employee
                </a>
            @endif
            @if ($perm('employees.view'))
                <a class="btn btn-outline-secondary" href="{{ route('employees.show', $employee) }}">
                    <i class="bi bi-person-badge" aria-hidden="true"></i> Profile
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Present days recorded" :value="number_format($summary['present'])" icon="bi-check2-circle" hint="All years on record" />
        <x-ui.kpi label="Approved leave days" :value="number_format($summary['leave_days'], 2)" icon="bi-airplane" hint="Sum of approved requests" />
        <x-ui.kpi label="Absent days" :value="number_format($summary['absent'])" icon="bi-x-octagon" hint="Recorded absence" />
        <x-ui.kpi label="Late minutes" :value="number_format($summary['late_minutes'])" icon="bi-alarm" hint="Shift start + grace" />
    </div>

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Employment</h2>
            </div>
            <dl class="erp-dl erp-dl-tight px-3 pb-3">
                <dt>Employee code</dt>
                <dd>{{ $employee->code }}</dd>
                <dt>Status</dt>
                <dd><x-ui.status :value="$employee->status" /></dd>
                <dt>Employment type</dt>
                <dd>{{ str_replace('_', ' ', $employee->employment_type) }}</dd>
                <dt>Joined</dt>
                <dd>{{ $employee->joining_date?->format('d M Y') ?? '—' }}</dd>
                <dt>Confirmed</dt>
                <dd>{{ $employee->confirmation_date?->format('d M Y') ?? 'Not confirmed' }}</dd>
                <dt>Branch</dt>
                <dd>{{ $employee->branch?->name ?? '—' }}</dd>
                <dt>Reports to</dt>
                <dd>{{ $employee->manager?->full_name ?? '—' }}</dd>
                <dt>Shift</dt>
                <dd>{{ $employee->shift_start }} – {{ $employee->shift_end }} · {{ $employee->late_grace_minutes }} min grace</dd>
                <dt>Weekly off</dt>
                <dd>{{ ucfirst((string) $employee->weekly_off) }}</dd>
                <dt>Annual leave</dt>
                <dd>{{ $employee->annual_leave_days }} day(s)</dd>
                <dt>Bank</dt>
                <dd>{{ $employee->bank_name ? $employee->bank_name.' · '.$employee->bank_account_no : 'Not on file' }}</dd>
                <dt>Mobile wallet</dt>
                <dd>{{ $employee->mobile_wallet ?? 'Not on file' }}</dd>
            </dl>
            <div class="erp-help px-3 pb-3">
                Salary is deliberately absent from this page: payroll owns money, HR owns the person. When the payroll module
                ships, it reads these fields rather than duplicating them.
            </div>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Leave history</h2>
                <span class="erp-chip erp-chip-outline">{{ $leaves->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Period</th>
                            <th class="erp-th-num">Days</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($leaves as $leave)
                            <tr>
                                <td data-label="Type">{{ $leave->leaveType?->name ?? '—' }}</td>
                                <td data-label="Period">{{ $leave->from_date->format('d M Y') }} → {{ $leave->to_date->format('d M Y') }}</td>
                                <td data-label="Days" class="erp-td-num">{{ number_format((float) $leave->days, 2) }}</td>
                                <td data-label="Status"><x-ui.status :value="$leave->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <x-ui.empty icon="bi-airplane" title="No leave history"
                                                text="This employee has never filed a leave request." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="erp-card-head">
                <h2 class="erp-card-title">Documents on file</h2>
                <span class="erp-chip erp-chip-outline">{{ $documents->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Purpose</th>
                            <th>Attached</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $document)
                            <tr>
                                <td data-label="File" class="erp-cell-strong">{{ $document->original_name }}</td>
                                <td data-label="Purpose" class="erp-td-muted">{{ $document->purpose ?? '—' }}</td>
                                <td data-label="Attached" class="erp-td-muted">{{ \Illuminate\Support\Carbon::parse($document->created_at)->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">
                                    <x-ui.empty icon="bi-paperclip" title="No documents attached"
                                                text="Contracts, NID copies and certificates uploaded against this employee will be listed here." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
