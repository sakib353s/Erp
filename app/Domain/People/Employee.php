<?php

namespace App\Domain\People;

use App\Domain\Delivery\RiderProfile;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Employee master. A user account is optional (not every employee logs
 * in) and an employee link is optional on the user side.
 */
class Employee extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'first_name', 'last_name', 'full_name',
        'email', 'phone', 'designation', 'department', 'joining_date',
        'employment_status', 'is_technician', 'is_salesperson', 'user_id', 'manager_id', 'status',
    ];

    protected $casts = [
        'joining_date' => 'date',
        'is_technician' => 'boolean',
        'is_salesperson' => 'boolean',
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
