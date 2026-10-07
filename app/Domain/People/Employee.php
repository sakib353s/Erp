<?php

namespace App\Domain\People;

use App\Domain\Delivery\RiderProfile;
use App\Domain\Hr\Models\Attendance;
use App\Domain\Hr\Models\Department;
use App\Domain\Hr\Models\Designation;
use App\Domain\Hr\Models\LeaveBalance;
use App\Domain\Hr\Models\LeaveRequest;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Employee master. A user account is optional (not every employee logs
 * in) and an employee link is optional on the user side.
 */
class Employee extends Model
{
    use Auditable;

    public const EMPLOYMENT_TYPES = ['permanent', 'contract', 'probation', 'part_time', 'daily'];
    public const EMPLOYMENT_STATUSES = ['active', 'probation', 'inactive', 'exited'];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'first_name', 'last_name', 'full_name',
        'email', 'phone', 'designation', 'department', 'department_id', 'designation_id',
        'joining_date', 'confirmation_date', 'employment_status', 'employment_type',
        'date_of_birth', 'gender', 'blood_group', 'national_id',
        'present_address', 'permanent_address', 'emergency_contact_name', 'emergency_contact_phone',
        'bank_name', 'bank_account_no', 'mobile_wallet',
        'weekly_off', 'annual_leave_days', 'shift_start', 'shift_end', 'late_grace_minutes',
        'is_technician', 'is_salesperson', 'user_id', 'manager_id', 'status',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'confirmation_date' => 'date',
        'date_of_birth' => 'date',
        'is_technician' => 'boolean',
        'is_salesperson' => 'boolean',
        'annual_leave_days' => 'integer',
        'late_grace_minutes' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function riderProfile(): HasOne
    {
        return $this->hasOne(RiderProfile::class, 'employee_id');
    }

    public function departmentRecord(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function designationRecord(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /** Display name used in lists and documents. */
    public function displayDepartment(): ?string
    {
        return $this->departmentRecord?->name ?? $this->department;
    }

    public function displayDesignation(): ?string
    {
        return $this->designationRecord?->name ?? $this->designation;
    }

    public function scopeDepartment($query, ?int $departmentId)
    {
        return $departmentId ? $query->where('department_id', $departmentId) : $query;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeTechnicians($query)
    {
        return $query->where('is_technician', true);
    }

    public function scopeSalespersons($query)
    {
        return $query->where('is_salesperson', true);
    }
}
