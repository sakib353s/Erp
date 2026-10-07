<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock adjustment document (line-level +/−, reason). Posting creates
 * ADJUST_IN/ADJUST_OUT movements via StockLedgerService only.
 */
class StockAdjustment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'adjustment_no',
        'adjustment_date', 'reason', 'status', 'created_by', 'posted_at',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'posted_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('line_no');
    }
}
