<?php

namespace App\Domain\Hr\Models;

use App\Domain\Masters\LeaveType;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per employee / leave type / year ledger (10-21).
 *
 * available = opening + accrued − taken − pending − encashed.
 * Nothing else in the system may compute an available balance differently.
 */
class LeaveBalance extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'year',
        'opening', 'accrued', 'taken', 'pending', 'encashed',
    ];

    protected $casts = [
        'opening' => 'decimal:2',
        'accrued' => 'decimal:2',
        'taken' => 'decimal:2',
        'pending' => 'decimal:2',
        'encashed' => 'decimal:2',
        'year' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function available(): float
    {
        return round(
            (float) $this->opening + (float) $this->accrued
                - (float) $this->taken - (float) $this->pending - (float) $this->encashed,
            2,
        );
    }
}
