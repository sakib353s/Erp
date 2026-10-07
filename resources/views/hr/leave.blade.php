@extends('layouts.app')

@section('page_title', 'Leave')

@section('content')
    <x-ui.page-header
        eyebrow="People & payroll"
        title="Leave"
            :subtitle="$canOversee
            ? 'Requests are costed against a real balance — days are counted from the calendar minus the weekly off and public holidays, never typed by hand.'
            : 'Your own leave, costed against a real balance. Days are counted from the calendar minus the weekly off and public holidays — you never type a day count.'"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('hr.leave.calendar') }}">
                <i class="bi bi-calendar3" aria-hidden="true"></i> Calendar
            </a>
            @if ($perm('hr.leave_types.manage'))
                <a class="btn btn-outline-secondary" href="{{ route('hr.leave-types') }}">
                    <i class="bi bi-tags" aria-hidden="true"></i> Leave types
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Waiting for a decision" :value="number_format($pendingCount)" icon="bi-hourglass"
                  :hint="$canOversee ? 'Pending requests across all employees' : 'Your own pending requests'" />
        <x-ui.kpi label="Requests on this page" :value="number_format($requests->total())" icon="bi-list-check" :hint="'Status: '.str_replace('_', ' ', $status)" />
        <x-ui.kpi label="{{ $canOversee ? 'Balances tracked' : 'Your balances' }}" :value="number_format($balances->count())" icon="bi-wallet2" :hint="$year.' leave years in ledger'" />
    </div>

    <div class="erp-split mb-3">
        @if ($perm('leave.request'))
            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Raise a request</h2>
                </div>
                <form method="POST" action="{{ route('hr.leave.store') }}" class="px-3 pb-3">
                    @csrf
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="employee_id">Employee</label>
                            @if ($canOversee)
                                <select class="form-select @error('employee_id') is-invalid @enderror" id="employee_id" name="employee_id" required>
                                    <option value="">Choose…</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)>{{ $employee->full_name }} ({{ $employee->code }})</option>
                                    @endforeach
                                </select>
                            @else
                                <input class="form-control" type="text" id="employee_id"
                                       value="{{ $employees->first()?->full_name ?? 'No employee record linked' }}" disabled>
                                <input type="hidden" name="employee_id" value="{{ $selfId }}">
                                @if (! $selfId)
                                    <div class="erp-help">Your login is not linked to an employee record, so leave cannot be filed here. Ask HR to link it.</div>
                                @endif
                            @endif
                            @error('employee_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="leave_type_id">Leave type</label>
                            <select class="form-select @error('leave_type_id') is-invalid @enderror" id="leave_type_id" name="leave_type_id" required>
                                <option value="">Choose…</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type->id }}" @selected(old('leave_type_id') == $type->id)>
                                        {{ $type->name }}@if ($type->default_days > 0) · {{ $type->default_days }} days/yr @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('leave_type_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="from_date">From</label>
                            <input class="form-control @error('from_date') is-invalid @enderror" type="date" id="from_date" name="from_date" value="{{ old('from_date') }}" required>
                            @error('from_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="to_date">To</label>
                            <input class="form-control @error('to_date') is-invalid @enderror" type="date" id="to_date" name="to_date" value="{{ old('to_date') }}" required>
                            @error('to_date')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="is_half_day">Half day</label>
                            <select class="form-select" id="is_half_day" name="is_half_day">
                                <option value="0">Full day(s)</option>
                                <option value="1" @selected(old('is_half_day') == 1)>Half day (0.5)</option>
                            </select>
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="reason">Reason</label>
                            <textarea class="form-control @error('reason') is-invalid @enderror" id="reason" name="reason" rows="2" maxlength="1000" required>{{ old('reason') }}</textarea>
                            @error('reason')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="erp-help mb-2">
                        The weekly off and public holidays inside the range are not charged. A request that exceeds the
                        available balance, or overlaps an existing one, is refused with the arithmetic shown.
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Submit request</button>
                </form>
            </div>
        @endif

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">{{ $canOversee ? 'Balances' : 'My balances' }} · {{ $year }}</h2>
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th class="erp-th-num">Entitled</th>
                            <th class="erp-th-num">Taken</th>
                            <th class="erp-th-num">Pending</th>
                            <th class="erp-th-num">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($balances as $balance)
                            <tr>
                                <td data-label="Employee">{{ $balance->employee?->full_name ?? '—' }}</td>
                                <td data-label="Type" class="erp-td-muted">{{ $balance->leaveType?->name ?? '—' }}</td>
                                <td data-label="Entitled" class="erp-td-num">{{ number_format((float) $balance->opening + (float) $balance->accrued, 2) }}</td>
                                <td data-label="Taken" class="erp-td-num">{{ number_format((float) $balance->taken, 2) }}</td>
                                <td data-label="Pending" class="erp-td-num">{{ number_format((float) $balance->pending, 2) }}</td>
                                <td data-label="Available" class="erp-td-num">
                                    <span class="{{ $balance->available() <= 0 ? 'erp-amount erp-amount-danger' : 'erp-cell-strong' }}">
                                        {{ number_format($balance->available(), 2) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty icon="bi-wallet2" title="No balances for {{ $year }}"
                                                text="A balance row is created the first time an employee requests leave of that type." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @error('leave')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror
    @error('decision')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <ul class="nav erp-tabs mb-3">
        @foreach (['pending' => 'Awaiting decision', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link {{ $status === $tabKey ? 'active' : '' }}"
                   href="{{ route('hr.leave', ['status' => $tabKey]) }}">{{ $tabLabel }}</a>
            </li>
        @endforeach
    </ul>

    <x-ui.table-shell :count="$requests->total().' requests'">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Period</th>
                <th class="erp-th-num">Days</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Decided</th>
                <th class="erp-th-actions">Decision</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $leave)
                <tr>
                    <td data-label="Employee">
                        <span class="erp-cell-strong">{{ $leave->employee?->full_name ?? 'Removed employee' }}</span>
                        <span class="erp-td-muted d-block small">{{ $leave->employee?->code }}</span>
                    </td>
                    <td data-label="Type">
                        {{ $leave->leaveType?->name ?? '—' }}
                        @if ($leave->leaveType && ! $leave->leaveType->is_paid)
                            <span class="erp-chip erp-chip-outline">unpaid</span>
                        @endif
                    </td>
                    <td data-label="Period">
                        {{ $leave->from_date->format('d M Y') }} → {{ $leave->to_date->format('d M Y') }}
                        @if ($leave->is_half_day)<span class="erp-chip erp-chip-soft">half day</span>@endif
                    </td>
                    <td data-label="Days" class="erp-td-num">{{ number_format((float) $leave->days, 2) }}</td>
                    <td data-label="Reason" class="erp-td-muted">{{ \Illuminate\Support\Str::limit($leave->reason, 60) }}</td>
                    <td data-label="Status"><x-ui.status :value="$leave->status" /></td>
                    <td data-label="Decided" class="erp-td-muted">
                        @if ($leave->decided_at)
                            {{ $leave->decided_at->format('d M Y') }}
                            @if ($leave->approver)<span class="d-block small">{{ $leave->approver->name }}</span>@endif
                            @if ($leave->decision_note)<span class="d-block small">“{{ \Illuminate\Support\Str::limit($leave->decision_note, 40) }}”</span>@endif
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Decision" class="erp-td-actions">
                        @if ($perm('leave.approve') && in_array($leave->status, ['pending', 'approved'], true))
                            <form method="POST" action="{{ route('hr.leave.decide', $leave) }}" class="erp-inline-form">
                                @csrf
                                <input class="form-control form-control-sm" type="text" name="note" maxlength="500"
                                       placeholder="Note" aria-label="Decision note">
                                @if ($leave->status === 'pending')
                                    <button class="btn btn-sm btn-primary" name="decision" value="approve" type="submit">Approve</button>
                                    <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit">Reject</button>
                                @else
                                    <button class="btn btn-sm btn-outline-secondary" name="decision" value="cancel" type="submit">Cancel</button>
                                @endif
                            </form>
                        @else
                            <span class="erp-td-muted">{{ $canOversee ? '—' : 'Not yours to decide' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-airplane" title="Nothing {{ $status === 'all' ? 'here' : $status }}"
                                    text="{{ $status === 'pending' ? 'No request is waiting for a decision.' : 'Switch tabs to see other states.' }}" />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $requests->links() }}</div>

    <x-ui.related-pages />
@endsection
