<?php

namespace App\Domain\Hr\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Hr\Models\Attendance;
use App\Domain\Hr\Models\LeaveBalance;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Masters\LeaveType;
use App\Domain\People\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leave chain (§10.2): request → approval → balance → attendance.
 *
 * Invariants:
 *   · days are computed from the calendar minus the employee's weekly off and
 *     public holidays — the requester never types a day count;
 *   · a request may not exceed what is available for the year (pending counts
 *     against availability, so two overlapping requests cannot both pass);
 *   · approving writes `leave` attendance rows for every covered working day;
 *   · approving a request that covers an already-recorded absence reconciles
 *     those rows (retroactive approval, spec rule) — the absence becomes leave;
 *   · rejecting or cancelling releases the pending days and never touches
 *     attendance.
 */
class LeaveService
{
    public function __construct(
        protected AuditRecorder $audit,
        protected AttendanceService $attendance,
    ) {}

    /** Requested leave days: calendar days minus weekly off and public holidays. */
    public function workingDaysBetween(Employee $employee, string $from, string $to): float
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException('The end date cannot be before the start date.');
        }

        $off = strtolower($employee->weekly_off ?: 'friday');
        $days = 0;

        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            if (strtolower($day->englishDayOfWeek) === $off) {
                continue;
            }

            if ($this->attendance->isHoliday($day->toDateString())) {
                continue;
            }

            $days++;
        }

        return (float) $days;
    }

    public function balanceFor(Employee $employee, LeaveType $type, ?int $year = null): LeaveBalance
    {
        $year ??= (int) now()->year;

        $balance = LeaveBalance::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
            [
                'company_id' => $employee->company_id,
                'opening' => 0,
                'accrued' => $this->defaultEntitlement($employee, $type),
                'taken' => 0,
                'pending' => 0,
                'encashed' => 0,
            ],
        );

        return $balance;
    }

    /**
     * Entitlement for the year: the employee's own allowance when set,
     * otherwise the leave type default (annual_leave_days is the paid-leave
     * allowance, other types fall back to their own default).
     */
    public function defaultEntitlement(Employee $employee, LeaveType $type): float
    {
        if ($type->code === 'annual' || str_contains(strtolower($type->name), 'annual')) {
            return (float) max((int) $employee->annual_leave_days, (int) $type->default_days);
        }

        return (float) $type->default_days;
    }

    /**
     * @param array{employee_id:int,leave_type_id:int,from_date:string,to_date:string,reason:string,is_half_day?:bool} $data
     */
    public function request(array $data, ?int $actorId = null): LeaveRequest
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        if (in_array($employee->employment_status, ['inactive', 'exited'], true)) {
            throw new RuntimeException("{$employee->full_name} has left the company — leave cannot be requested.");
        }

        if (($employee->status ?? 'active') !== 'active') {
            throw new RuntimeException("{$employee->full_name}'s employee record is inactive.");
        }

        $days = $this->workingDaysBetween($employee, $data['from_date'], $data['to_date']);

        if (! empty($data['is_half_day'])) {
            $days = 0.5;
        }

        if ($days <= 0) {
            throw new RuntimeException('That range contains no working day — nothing to request.');
        }

        $balance = $this->balanceFor($employee, $type);

        if ($balance->available() < $days) {
            throw new RuntimeException(sprintf(
                'Not enough %s leave: %.2f day(s) requested, %.2f available.',
                $type->name,
                $days,
                $balance->available(),
            ));
        }

        $overlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $data['to_date'])
            ->whereDate('to_date', '>=', $data['from_date'])
            ->exists();

        if ($overlap) {
            throw new RuntimeException('This employee already has leave covering those dates.');
        }

        return DB::transaction(function () use ($employee, $type, $data, $days, $balance, $actorId) {
            $request = LeaveRequest::create([
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'days' => $days,
                'is_half_day' => (bool) ($data['is_half_day'] ?? false),
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);

            $balance->forceFill(['pending' => (float) $balance->pending + $days])->save();

            $this->audit->record([
                'action' => 'hr.leave_requested',
                'entity_type' => 'leave_request',
                'entity_id' => $request->id,
                'actor_id' => $actorId,
                'after' => [
                    'employee' => $employee->full_name,
                    'type' => $type->code,
                    'from' => $data['from_date'],
                    'to' => $data['to_date'],
                    'days' => $days,
                ],
            ]);

            return $request;
        });
    }

    /** Approve: pending → taken, attendance rows written/reconciled. */
    public function approve(LeaveRequest $request, ?int $actorId = null, ?string $note = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException("Only a pending request can be approved (this one is {$request->status}).");
        }

        return DB::transaction(function () use ($request, $actorId, $note) {
            $request->forceFill([
                'status' => 'approved',
                'approved_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $balance = $this->balanceFor($request->employee, $request->leaveType);

            $balance->forceFill([
                'pending' => max(0, (float) $balance->pending - (float) $request->days),
                'taken' => (float) $balance->taken + (float) $request->days,
            ])->save();

            $this->writeAttendance($request, $actorId);

            $this->audit->record([
                'action' => 'hr.leave_approved',
                'entity_type' => 'leave_request',
                'entity_id' => $request->id,
                'actor_id' => $actorId,
                'after' => ['days' => (float) $request->days, 'note' => $note],
            ]);

            return $request;
        });
    }

    public function reject(LeaveRequest $request, ?int $actorId = null, ?string $note = null): LeaveRequest
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException("Only a pending request can be rejected (this one is {$request->status}).");
        }

        return DB::transaction(function () use ($request, $actorId, $note) {
            $request->forceFill([
                'status' => 'rejected',
                'approved_by' => $actorId,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $balance = $this->balanceFor($request->employee, $request->leaveType);
            $balance->forceFill(['pending' => max(0, (float) $balance->pending - (float) $request->days)])->save();

            $this->audit->record([
                'action' => 'hr.leave_rejected',
                'entity_type' => 'leave_request',
                'entity_id' => $request->id,
                'actor_id' => $actorId,
                'after' => ['days' => (float) $request->days, 'note' => $note],
            ]);

            return $request;
        });
    }

    /** Cancel an approved request: takes the days back and clears the rows. */
    public function cancel(LeaveRequest $request, ?int $actorId = null, ?string $note = null): LeaveRequest
    {
        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only a pending or approved request can be cancelled.');
        }

        return DB::transaction(function () use ($request, $actorId, $note) {
            $wasApproved = $request->status === 'approved';

            $request->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'decision_note' => $note])->save();

            $balance = $this->balanceFor($request->employee, $request->leaveType);

            $balance->forceFill($wasApproved
                ? ['taken' => max(0, (float) $balance->taken - (float) $request->days)]
                : ['pending' => max(0, (float) $balance->pending - (float) $request->days)])->save();

            if ($wasApproved) {
                Attendance::query()
                    ->where('leave_request_id', $request->id)
                    ->where('source', 'system')
                    ->update(['status' => 'absent', 'leave_request_id' => null, 'reason' => 'Leave cancelled']);
            }

            $this->audit->record([
                'action' => 'hr.leave_cancelled',
                'entity_type' => 'leave_request',
                'entity_id' => $request->id,
                'actor_id' => $actorId,
                'after' => ['note' => $note],
            ]);

            return $request;
        });
    }

    /* ------------------------------------------------------------------ */

    /**
     * Write the leave rows for the covered working days. Existing rows are
     * reconciled (this is the retroactive-approval path): an absence earlier
     * recorded becomes leave and keeps the audit trail of that change.
     */
    protected function writeAttendance(LeaveRequest $request, ?int $actorId): void
    {
        $employee = $request->employee;
        $type = $request->leaveType;
        $off = strtolower($employee->weekly_off ?: 'friday');

        for ($day = $request->from_date->copy(); $day <= $request->to_date; $day->addDay()) {
            $date = $day->toDateString();

            if (strtolower($day->englishDayOfWeek) === $off || $this->attendance->isHoliday($date)) {
                continue;
            }

            $status = $request->is_half_day ? 'half_day' : 'leave';

            $existing = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->first();

            if ($existing !== null && $existing->status !== 'absent' && $existing->source !== 'system') {
                // Already worked or already marked — never silently overwritten.
                continue;
            }

            $this->attendance->mark($employee, $date, [
                'status' => $status,
                'source' => 'system',
                'reason' => "Approved {$type->name} leave (#{$request->id})",
                'leave_request_id' => $request->id,
            ], $actorId);
        }
    }
}
