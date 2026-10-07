<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash collected by a rider against an accepted assignment (02-93).
 * A record of what the rider says was collected — no accounting
 * posting happens at collection time; the cod_remittance posting
 * lands when 02-97 reconciles the cash against the remittance.
 */
class RiderCodCollection extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'rider_assignment_id', 'rider_employee_id',
        'amount', 'collected_at', 'notes', 'recorded_by',
        'cod_reconciliation_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'collected_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RiderAssignment::class, 'rider_assignment_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_employee_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CodReconciliation::class, 'cod_reconciliation_id');
    }
}
