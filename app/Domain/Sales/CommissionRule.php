<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    use Auditable;

    public const TYPES = ['percent_of_revenue', 'fixed_per_period'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'name', 'rule_type',
        'rate', 'fixed_amount', 'period_type', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'fixed_amount' => 'decimal:4',
        'is_active' => 'boolean',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
