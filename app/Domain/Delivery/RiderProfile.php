<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Own-delivery rider roster entry (02-93). A rider is always an
 * employee — the employee master stays the source of truth for who
 * the person is; this row carries delivery-specific state (vehicle,
 * availability, GPS sharing consent). One profile per employee per
 * company.
 */
class RiderProfile extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'employee_id', 'vehicle_type', 'vehicle_plate',
        'is_available', 'is_active', 'gps_consent',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'is_active' => 'boolean',
        'gps_consent' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RiderAssignment::class, 'rider_employee_id', 'employee_id');
    }

    public function codCollections(): HasMany
    {
        return $this->hasMany(RiderCodCollection::class, 'rider_employee_id', 'employee_id');
    }
}
