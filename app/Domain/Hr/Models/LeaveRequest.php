<?php

namespace App\Domain\Hr\Models;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\LeaveType;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave request (10-19). The chain is request → approval → balance →
 * attendance: approving rewrites the covered days to `leave` and moves the
 * days from pending to taken on the balance; rejecting only releases pending.
 */
class LeaveRequest extends Model
{
    use Auditable;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'leave_type_id', 'from_date', 'to_date',
        'days', 'is_half_day', 'reason', 'status', 'approved_by', 'decided_at', 'decision_note',
        'attachment_path',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'decided_at' => 'datetime',
        'days' => 'decimal:2',
        'is_half_day' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Does this request cover the given date? */
    public function covers(string $date): bool
    {
        return $date >= $this->from_date->toDateString() && $date <= $this->to_date->toDateString();
    }
}
