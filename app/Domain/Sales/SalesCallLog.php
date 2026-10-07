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

class SalesCallLog extends Model
{
    use Auditable;

    public const DIRECTIONS = ['inbound', 'outbound'];

    public const OUTCOMES = [
        'connected', 'no_answer', 'voicemail', 'callback',
        'not_interested', 'qualified', 'appointment', 'wrong_number',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'customer_id',
        'call_date', 'direction', 'outcome', 'subject', 'notes',
        'duration_minutes', 'created_by',
    ];

    protected $casts = [
        'call_date' => 'date',
        'duration_minutes' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
