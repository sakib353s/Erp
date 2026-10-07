<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\People\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    use Auditable;

    public const PERIOD_TYPES = ['daily', 'monthly', 'yearly'];

    protected $fillable = [
        'company_id', 'branch_id', 'employee_id', 'period_type',
        'period_start', 'period_end', 'target_amount', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'target_amount' => 'decimal:4',
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

    public function scopeCompany($query)
    {
        return $query->where('company_id', auth()->user()?->company_id ?? $this->company_id);
    }

    /**
     * Resolve period bounds for daily|monthly|yearly from a reference date.
     *
     * @return array{period_start: string, period_end: string}
     */
    public static function boundsFor(string $periodType, ?string $at = null): array
    {
        $date = Carbon::parse($at ?? now());

        return match ($periodType) {
            'daily' => [
                'period_start' => $date->toDateString(),
                'period_end' => $date->toDateString(),
            ],
            'monthly' => [
                'period_start' => $date->copy()->startOfMonth()->toDateString(),
                'period_end' => $date->copy()->endOfMonth()->toDateString(),
            ],
            'yearly' => [
                'period_start' => $date->copy()->startOfYear()->toDateString(),
                'period_end' => $date->copy()->endOfYear()->toDateString(),
            ],
            default => throw new \RuntimeException("Unknown period type [{$periodType}]."),
        };
    }
}
