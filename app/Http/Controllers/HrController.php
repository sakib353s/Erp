<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\Attendance;
use App\Domain\Hr\Models\Department;
use App\Domain\Hr\Models\Designation;
use App\Domain\Hr\Models\LeaveBalance;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Hr\Services\AttendanceService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Masters\Holiday;
use App\Domain\Masters\LeaveType;
use App\Domain\People\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * HRM (§10): departments, designations, attendance and leave.
 *
 * Reads are plain queries; every write goes through AttendanceService or
 * LeaveService, which own the invariants (one row per day, reason on manual
 * edits, leave chain, balances). Payroll is a separate module and is not
 * reached from here.
 */
class HrController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    /* --------------------------------------------------- attendance sheet */

    public function attendance(Request $request): View
    {
        $date = (string) ($request->query('date') ?: now()->toDateString());
        $branchId = $this->branchScope($request);

        return view('hr.attendance', [
            'date' => $date,
            'branchId' => $branchId,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'sheet' => $this->attendance->sheet($branchId, $date),
            'isHoliday' => $this->attendance->isHoliday($date),
            'statuses' => Attendance::STATUSES,
            'weekOffCount' => $this->attendance->weekOffCount($branchId),
        ]);
    }

    /** Month-wise counts per employee (10-18) — the number payroll will read. */
    public function attendanceSummary(Request $request): View
    {
        $branchId = $this->branchScope($request);
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);

        return view('hr.attendance-summary', [
            'branchId' => $branchId,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'departmentId' => $request->filled('department') ? (int) $request->query('department') : null,
            'year' => $year,
            'month' => $month,
            'summary' => $this->attendance->monthSummary(
                $branchId,
                $year,
                $month,
                $request->filled('department') ? (int) $request->query('department') : null,
            ),
            'statuses' => Attendance::STATUSES,
        ]);
    }

    /** Branch the screen is scoped to: the request, else the user's default. */
    protected function branchScope(Request $request): ?int
    {
        $branch = $request->query('branch');

        if ($branch === 'all') {
            return null;
        }

        if ($branch !== null && $branch !== '') {
            return (int) $branch;
        }

        return $request->user()?->default_branch_id;
    }

    public function markAttendance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:'.implode(',', Attendance::STATUSES)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'overtime_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);

        try {
            $this->attendance->mark($employee, $data['date'], $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['attendance' => $e->getMessage()]);
        }

        return back()->with('status', "Attendance saved for {$employee->full_name} on {$data['date']}.");
    }

    public function markNonWorking(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'status' => ['required', 'in:holiday,weekend'],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $count = $this->attendance->markNonWorkingDay($data['date'], $data['status'], $data['branch'] ?? null);

        return back()->with('status', "{$count} employee record(s) marked as {$data['status']} on {$data['date']}.");
    }

    public function attendanceReport(Request $request, Employee $employee): View
    {
        $employee->load(['departmentRecord:id,name', 'designationRecord:id,name']);
        $year = (int) ($request->query('year') ?: now()->year);

        $records = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereYear('date', $year)
            ->orderByDesc('date')
            ->get();

        $byMonth = $records->groupBy(fn (Attendance $a) => $a->date->format('Y-m'));

        return view('hr.attendance-report', [
            'employee' => $employee,
            'year' => $year,
            'records' => $records,
            'byMonth' => $byMonth,
            'totals' => collect(Attendance::STATUSES)->mapWithKeys(fn ($s) => [$s => $records->where('status', $s)->count()])->all(),
            'unapplied' => $records->filter(fn (Attendance $a) => $a->status === 'absent' && $a->leave_request_id === null)->count(),
            'lateMinutes' => (int) $records->sum('late_minutes'),
            'overtimeMinutes' => (int) $records->sum('overtime_minutes'),
        ]);
    }

    /* ------------------------------------------------------------- leave */

    public function leave(Request $request): View
    {
        $status = (string) $request->query('status', 'pending');
        $actor = $request->user();

        /*
         * Who may look at whose leave: an approver sees the whole company, a
         * plain staff account sees only its own record — the employee portal
         * rule (§10.2). A user with no linked employee record sees nothing
         * rather than everybody's.
         */
        $canOversee = app(PermissionCatalog::class)->allows($actor, 'leave.approve')
            || app(PermissionCatalog::class)->allows($actor, 'leave.balance');

        $selfId = $actor !== null
            ? Employee::query()->where('user_id', $actor->id)->value('id')
            : null;

        $scope = fn ($query) => $canOversee ? $query : $query->where('employee_id', $selfId ?? 0);

        $requests = $scope(
            LeaveRequest::query()
                ->with(['employee:id,full_name,code,department_id', 'leaveType:id,name,code,is_paid', 'approver:id,name'])
        )
            ->when(in_array($status, LeaveRequest::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('from_date')
            ->paginate(20)
            ->withQueryString();

        $year = (int) ($request->query('year') ?: now()->year);

        return view('hr.leave', [
            'requests' => $requests,
            'status' => $status,
            'year' => $year,
            'canOversee' => $canOversee,
            'selfId' => $selfId,
            'employees' => $canOversee
                ? Employee::query()->active()->orderBy('full_name')->get(['id', 'full_name', 'code'])
                : Employee::query()->whereKey($selfId ?? 0)->get(['id', 'full_name', 'code']),
            'types' => LeaveType::query()->active()->orderBy('name')->get(['id', 'name', 'code', 'default_days', 'is_paid']),
            'balances' => $scope(
                LeaveBalance::query()->with(['employee:id,full_name', 'leaveType:id,name'])
            )
                ->where('year', $year)
                ->orderByDesc('accrued')
                ->limit(50)
                ->get(),
            'pendingCount' => $scope(LeaveRequest::query()->pending())->count(),
        ]);
    }

    public function storeLeave(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $catalog = app(PermissionCatalog::class);
        $canOversee = $catalog->allows($actor, 'leave.approve') || $catalog->allows($actor, 'leave.balance');

        // A staff account may only ever file its own leave (§10.2 employee scope).
        if (! $canOversee) {
            $selfId = Employee::query()->where('user_id', $actor?->id)->value('id');

            if ($selfId === null) {
                return back()->withInput()->withErrors([
                    'employee_id' => 'Your login is not linked to an employee record, so leave cannot be filed from it.',
                ]);
            }

            $request->merge(['employee_id' => $selfId]);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'is_half_day' => ['nullable', 'boolean'],
        ]);

        try {
            $this->leave->request($data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['leave' => $e->getMessage()]);
        }

        return back()->with('status', 'Leave requested — it now waits for approval.');
    }

    public function decideLeave(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject,cancel'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            match ($data['decision']) {
                'approve' => $this->leave->approve($leave, $request->user()?->id, $data['note'] ?? null),
                'reject' => $this->leave->reject($leave, $request->user()?->id, $data['note'] ?? null),
                'cancel' => $this->leave->cancel($leave, $request->user()?->id, $data['note'] ?? null),
            };
        } catch (RuntimeException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('status', "Leave request #{$leave->id} {$data['decision']}d.");
    }

    /** 10-20 — month calendar of leave and holidays for the branch. */
    public function leaveCalendar(Request $request): View
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $month = (int) ($request->query('month') ?: now()->month);

        $start = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $requests = LeaveRequest::query()
            ->with(['employee:id,full_name', 'leaveType:id,name'])
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->get();

        $holidays = Holiday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        return view('hr.calendar', [
            'year' => $year,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'requests' => $requests,
            'holidays' => $holidays,
            'leaveByDay' => $requests->flatMap(function (LeaveRequest $r) use ($start, $end) {
                $out = [];
                for ($day = $r->from_date->copy(); $day <= $r->to_date; $day->addDay()) {
                    if ($day < $start || $day > $end) {
                        continue;
                    }
                    $out[] = ['date' => $day->toDateString(), 'request' => $r];
                }

                return $out;
            })->groupBy('date'),
        ]);
    }

    /* -------------------------------------------- departments/designations */

    public function departments(): View
    {
        return view('hr.departments', [
            'departments' => Department::query()
                ->withCount('employees')
                ->with('parent:id,name')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:128'],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'cost_center' => ['nullable', 'string', 'max:64'],
        ]);

        $exists = Department::query()->where('code', $data['code'])->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'That department code already exists.']);
        }

        Department::create([...$data, 'is_active' => true]);

        return back()->with('status', "Department {$data['name']} created.");
    }

    public function designations(): View
    {
        return view('hr.designations', [
            'designations' => Designation::query()
                ->with('department:id,name')
                ->withCount('employees')
                ->orderBy('name')
                ->get(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeDesignation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:128'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'grade' => ['nullable', 'string', 'max:32'],
        ]);

        $exists = Designation::query()->where('code', $data['code'])->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'That designation code already exists.']);
        }

        Designation::create([...$data, 'is_active' => true]);

        return back()->with('status', "Designation {$data['name']} created.");
    }

    /* ------------------------------------------------------- leave types */

    public function leaveTypes(): View
    {
        return view('hr.leave-types', [
            'types' => LeaveType::query()
                ->withCount('leaveRequests')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeLeaveType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:64'],
            'default_days' => ['required', 'numeric', 'min:0', 'max:365'],
            'is_paid' => ['nullable', 'boolean'],
        ]);

        $exists = LeaveType::query()->where('code', $data['code'])->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'That leave type code already exists.']);
        }

        LeaveType::create([
            ...$data,
            'is_paid' => (bool) ($data['is_paid'] ?? true),
            'is_active' => true,
        ]);

        return back()->with('status', "Leave type {$data['name']} created.");
    }

    /** 10-06 — service book: the employee's real chronology, aggregated. */
    public function serviceBook(Employee $employee): View
    {
        $attendance = Attendance::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('date')
            ->get();

        return view('hr.service-book', [
            'employee' => $employee->load(['departmentRecord:id,name', 'designationRecord:id,name', 'branch:id,name', 'manager:id,full_name']),
            'leaves' => LeaveRequest::query()
                ->with('leaveType:id,name')
                ->where('employee_id', $employee->id)
                ->orderByDesc('from_date')
                ->get(),
            'summary' => [
                'present' => $attendance->whereIn('status', ['present', 'late'])->count(),
                'absent' => $attendance->where('status', 'absent')->count(),
                'leave_days' => (float) LeaveRequest::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', 'approved')
                    ->sum('days'),
                'late_minutes' => (int) $attendance->sum('late_minutes'),
            ],
            'documents' => DB::table('documents')
                ->where('owner_type', 'like', '%Employee')
                ->where('owner_id', $employee->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'original_name', 'purpose', 'created_at']),
        ]);
    }
}
