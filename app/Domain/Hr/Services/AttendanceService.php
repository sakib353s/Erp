<?php

namespace App\Domain\Hr\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Hr\Models\Attendance;
use App\Domain\Masters\Holiday;
use App\Domain\People\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Attendance truth (10-10…10-18).
 *
 * Rules enforced here, and nowhere else:
 *   · one row per employee per day (unique index; corrections update in place);
 *   · a manual row or correction MUST carry a reason — attendance feeds payroll
 *     deductions, so an unexplained edit is not acceptable;
 *   · lateness is computed against the employee's shift + grace minutes, never
 *     supplied by the caller;
 *   · public holidays and the weekly off are recorded deliberately, so an
 *     "absent" row always means the employee was expected to work;
 *   · absences without approved leave are flagged (`leave_request_id` null) and
 *     a later approved leave request reconciles them retroactively.
 */
class AttendanceService
{
    public function __construct(protected AuditRecorder $audit) {}

    /**
     * Mark (or correct) one day for one employee.
     *
     * @param array{status:string,check_in?:?string,check_out?:?string,reason?:?string,
     *              overtime_minutes?:int,source?:string,leave_request_id?:?int} $data
     */
    public function mark(Employee $employee, string $date, array $data, ?int $actorId = null): Attendance
    {
        $status = $data['status'];

        if (! in_array($status, Attendance::STATUSES, true)) {
            throw new RuntimeException("Unknown attendance status: {$status}");
        }

        $existing = Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        $source = $data['source'] ?? 'manual';
        $reason = trim((string) ($data['reason'] ?? ''));

        // A manual edit of an existing device/system row is a correction and is
        // only acceptable with a stated reason (10-12/10-14).
        if ($existing !== null && $source === 'manual' && $reason === '') {
            throw new RuntimeException('Correcting an existing attendance record requires a reason.');
        }

        if ($source === 'manual' && $reason === '' && $existing === null && $status === 'absent') {
            throw new RuntimeException('Marking someone absent requires a reason.');
        }

        [$lateMinutes, $normalised] = $this->normalise($employee, $status, $data);

        return DB::transaction(function () use ($employee, $date, $data, $status, $normalised, $lateMinutes, $source, $reason, $actorId, $existing) {
            $payload = [
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'date' => $date,
                'status' => $status,
                'check_in' => $normalised['check_in'],
                'check_out' => $normalised['check_out'],
                'late_minutes' => $lateMinutes,
                'is_overtime' => (int) ($data['overtime_minutes'] ?? 0) > 0,
                'overtime_minutes' => (int) ($data['overtime_minutes'] ?? 0),
                'source' => $source,
                'reason' => $reason ?: null,
                'leave_request_id' => $data['leave_request_id'] ?? null,
                'recorded_by' => $actorId,
            ];

            if ($existing !== null) {
                $before = $existing->only(['status', 'check_in', 'check_out', 'late_minutes', 'reason']);
                $existing->fill($payload)->save();
                $record = $existing;

                $this->audit->record([
                    'action' => 'hr.attendance_corrected',
                    'entity_type' => 'attendance',
                    'entity_id' => $record->id,
                    'actor_id' => $actorId,
                    'before' => $before,
                    'after' => $record->only(['status', 'check_in', 'check_out', 'late_minutes', 'reason']),
                ]);

                return $record;
            }

            $record = Attendance::create($payload);

            $this->audit->record([
                'action' => 'hr.attendance_marked',
                'entity_type' => 'attendance',
                'entity_id' => $record->id,
                'actor_id' => $actorId,
                'after' => $record->only(['status', 'date', 'check_in', 'check_out', 'late_minutes']),
            ]);

            return $record;
        });
    }

    /**
     * Build the day's sheet for a branch/date: everyone active gets a row,
     * existing records win. Used by the attendance screen so the clerk sees
     * the whole team at once instead of a blank table.
     *
     * @return Collection<int, array{employee:Employee, attendance:?Attendance}>
     */
    public function sheet(?int $branchId, string $date): Collection
    {
        $employees = Employee::query()
            ->with(['departmentRecord:id,name', 'designationRecord:id,name'])
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('full_name')
            ->get();

        $records = Attendance::query()
            ->whereDate('date', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        return $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'attendance' => $records->get($employee->id),
        ]);
    }

    /**
     * Month summary per employee: counts by status, late minutes, overtime and
     * *unapplied* absences (10-16/10-18) — the number HR must chase.
     *
     * @return array{rows:array<int, array<string, mixed>>, totals:array<string, int>}
     */
    public function monthSummary(?int $branchId, int $year, int $month, ?int $departmentId = null): array
    {
        $employees = Employee::query()
            ->with('departmentRecord:id,name')
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->department($departmentId)
            ->orderBy('full_name')
            ->get();

        $records = Attendance::query()
            ->forMonth($year, $month)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        $totals = array_fill_keys(Attendance::STATUSES, 0);
        $totals['unapplied_absence'] = 0;
        $totals['late_minutes'] = 0;
        $totals['overtime_minutes'] = 0;

        foreach ($employees as $employee) {
            $days = $records->get($employee->id) ?? collect();

            $counts = array_fill_keys(Attendance::STATUSES, 0);

            foreach ($days as $day) {
                $counts[$day->status] = ($counts[$day->status] ?? 0) + 1;
                $totals[$day->status] = ($totals[$day->status] ?? 0) + 1;
            }

            $unapplied = $days->filter(fn (Attendance $a) => $a->status === 'absent' && $a->leave_request_id === null)->count();
            $lateMinutes = (int) $days->sum('late_minutes');
            $overtime = (int) $days->sum('overtime_minutes');

            $totals['unapplied_absence'] += $unapplied;
            $totals['late_minutes'] += $lateMinutes;
            $totals['overtime_minutes'] += $overtime;

            $rows[] = [
                'employee' => $employee,
                'present' => $counts['present'] + $counts['late'],
                'late' => $counts['late'],
                'absent' => $counts['absent'],
                'leave' => $counts['leave'],
                'half_day' => $counts['half_day'],
                'holiday' => $counts['holiday'] + $counts['weekend'],
                'unapplied_absence' => $unapplied,
                'late_minutes' => $lateMinutes,
                'overtime_minutes' => $overtime,
                'recorded_days' => $days->count(),
                'working_days' => $this->workingDaysInMonth($employee, $year, $month),
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Record the weekly off / public holiday for every active employee on a
     * date, so absence reporting can tell "did not come" from "was not due".
     */
    public function markNonWorkingDay(string $date, string $status = 'holiday', ?int $branchId = null): int
    {
        if (! in_array($status, ['holiday', 'weekend'], true)) {
            throw new RuntimeException('Only holiday or weekend rows can be mass-recorded.');
        }

        $employees = Employee::query()->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        $count = 0;

        foreach ($employees as $employee) {
            $exists = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            Attendance::create([
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'date' => $date,
                'status' => $status,
                'source' => 'system',
                'reason' => $status === 'holiday' ? 'Public holiday' : 'Weekly off',
            ]);

            $count++;
        }

        return $count;
    }

    /** Active headcount for the branch — the denominator of any % shown. */
    public function weekOffCount(?int $branchId): int
    {
        return Employee::query()
            ->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
    }

    public function isHoliday(string $date): bool
    {
        return Holiday::query()->whereDate('date', $date)->where('is_active', true)->exists();
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:int,1:array{check_in:?string,check_out:?string}}
     */
    protected function normalise(Employee $employee, string $status, array $data): array
    {
        $checkIn = $data['check_in'] ?? null;
        $checkOut = $data['check_out'] ?? null;

        if (in_array($status, ['absent', 'leave', 'holiday', 'weekend'], true)) {
            return [0, ['check_in' => null, 'check_out' => null]];
        }

        $shiftStart = $employee->shift_start ?: '09:00';
        $grace = (int) ($employee->late_grace_minutes ?? 0);

        if ($checkIn === null) {
            // Nobody marked a time: a "present" row without times is only
            // meaningful as a full day, so record the shift itself.
            $checkIn = $shiftStart;
        }

        $lateMinutes = $this->minutesLate($checkIn, $shiftStart, $grace);

        if ($lateMinutes > 0 && $status === 'present') {
            $status = 'late';
        }

        if ($status === 'half_day' && $checkOut === null) {
            $checkOut = $this->addMinutes($shiftStart, 240);
        }

        return [$lateMinutes, ['check_in' => $checkIn, 'check_out' => $checkOut]];
    }

    protected function minutesLate(string $checkIn, string $shiftStart, int $grace): int
    {
        $in = $this->toMinutes($checkIn);
        $start = $this->toMinutes($shiftStart) + $grace;

        return max(0, $in - $start);
    }

    protected function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }

    protected function addMinutes(string $time, int $minutes): string
    {
        $total = $this->toMinutes($time) + $minutes;

        return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
    }

    /** Weekdays in the month minus the employee's weekly off. */
    protected function workingDaysInMonth(Employee $employee, int $year, int $month): int
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $off = strtolower($employee->weekly_off ?: 'friday');

        $days = 0;

        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            if (strtolower($day->englishDayOfWeek) !== $off) {
                $days++;
            }
        }

        return $days;
    }
}
