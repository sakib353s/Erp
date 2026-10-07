<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPayment extends Model
{
    use Auditable;

    public const STATUSES = ['pending_approval', 'paid', 'rejected', 'void'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'commission_calculation_id',
        'payment_no', 'status', 'method', 'amount', 'payment_date',
        'reference', 'narration', 'idempotency_key',
        'journal_entry_id', 'approval_request_id', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'payment_date' => 'date',
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

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(CommissionCalculation::class, 'commission_calculation_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
