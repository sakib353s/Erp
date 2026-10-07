<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldVisit extends Model
{
    use Auditable;

    public const STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'customer_id',
        'visit_date', 'started_at', 'ended_at', 'status', 'purpose',
        'location_note', 'notes', 'gps_consent', 'latitude', 'longitude',
        'created_by',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'gps_consent' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function gpsPoints(): HasMany
    {
        return $this->hasMany(GpsPoint::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
