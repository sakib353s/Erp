<?php

namespace App\Domain\Hr\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance row per employee per day (10-10…10-18).
 *
 * The unique (employee_id, date) index is the guarantee: a day has exactly one
 * truth. Corrections update this row and must carry a reason — an attendance
 * record is evidence used for payroll deductions, so silent edits are not
 * allowed (see AttendanceService::mark()).
 */
class Attendance extends Model
{
    use Auditable;

    public const STATUSES = ['present', 'late', 'absent', 'leave', 'half_day', 'holiday', 'weekend'];
    public const SOURCES = ['manual', 'device', 'import', 'system'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'date', 'status',
        'check_in', 'check_out', 'late_minutes', 'is_overtime', 'overtime_minutes',
        'source', 'reason', 'leave_request_id', 'recorded_by',
    ];

    protected $casts = [
        'date' => 'date',
        'is_overtime' => 'boolean',
        'late_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('date', $year)->whereMonth('date', $month);
    }

    /** Statuses that count as a worked day for payroll purposes. */
    public function scopeWorked($query)
    {
        return $query->whereIn('status', ['present', 'late', 'half_day']);
    }

    /** A day where the employee was away and no leave covers it (10-16). */
    public function scopeUnappliedAbsence($query)
    {
        return $query->where('status', 'absent')->whereNull('leave_request_id');
    }

    public function workedMinutes(): int
    {
        if ($this->check_in === null || $this->check_out === null) {
            return 0;
        }

        [$h1, $m1] = array_map('intval', explode(':', $this->check_in));
        [$h2, $m2] = array_map('intval', explode(':', $this->check_out));

        return max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
    }
}
