<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeatPlan extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'active', 'completed', 'cancelled'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'name',
        'plan_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'plan_date' => 'date',
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

    public function stops(): HasMany
    {
        return $this->hasMany(BeatPlanStop::class)->orderBy('sequence_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
