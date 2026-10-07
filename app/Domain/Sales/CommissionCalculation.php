<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionCalculation extends Model
{
    use Auditable;

    public const STATUSES = ['pending', 'accrued', 'pending_approval', 'paid', 'void'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'commission_rule_id',
        'period_type', 'period_start', 'period_end',
        'base_amount', 'rate', 'commission_amount', 'status',
        'journal_entry_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'base_amount' => 'decimal:4',
        'rate' => 'decimal:4',
        'commission_amount' => 'decimal:4',
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

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
