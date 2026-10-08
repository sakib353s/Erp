<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'opened_by', 'closed_by',
        'session_no', 'display_code', 'display_state', 'display_updated_at',
        'status', 'opened_at', 'closed_at', 'opening_float',
        'closing_counted', 'expected_cash', 'variance', 'cash_sales',
        'non_cash_sales', 'cash_in', 'cash_out',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'display_state' => 'array',
        'display_updated_at' => 'datetime',
        'opening_float' => 'decimal:4',
        'closing_counted' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'variance' => 'decimal:4',
        'cash_sales' => 'decimal:4',
        'non_cash_sales' => 'decimal:4',
        'cash_in' => 'decimal:4',
        'cash_out' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
