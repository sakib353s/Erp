<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * COD remittance reconciliation (02-97): what riders recorded as
 * collected (cash_total) vs what the office actually handed over
 * (remitted_amount). The variance rides on the row; GL posts once via
 * the cod_remittance rule at finalize (immediately, or after approval
 * when a workflow definition applies).
 */
class CodReconciliation extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_RECONCILED = 'reconciled';

    protected $fillable = [
        'company_id', 'branch_id', 'rider_employee_id',
        'remitted_amount', 'cash_total', 'variance',
        'status', 'remitted_at', 'reference', 'notes',
        'journal_entry_id', 'approval_request_id',
        'reconciled_by', 'reconciled_at', 'idempotency_key',
    ];

    protected $casts = [
        'remitted_amount' => 'decimal:2',
        'cash_total' => 'decimal:2',
        'variance' => 'decimal:2',
        'remitted_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rider_employee_id');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(RiderCodCollection::class, 'cod_reconciliation_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function isReconciled(): bool
    {
        return $this->status === self::STATUS_RECONCILED;
    }
}
