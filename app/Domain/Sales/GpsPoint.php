<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Company;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpsPoint extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'field_visit_id', 'source',
        'latitude', 'longitude', 'captured_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'captured_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fieldVisit(): BelongsTo
    {
        return $this->belongsTo(FieldVisit::class);
    }
}
