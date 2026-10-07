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
 * A stock count sheet (§04-31): one warehouse, one date, and the difference
 * between what the ledger said and what the counter found.
 *
 * The sheet is a *snapshot*, not a live view. Every line remembers the balance
 * at the moment the sheet was opened, because a count that silently followed
 * the warehouse would always agree with itself and never find anything.
 */
class StockCount extends Model
{
    public const SCOPE_FULL = 'full';

    public const SCOPE_CYCLE = 'cycle';

    public const SCOPES = [
        self::SCOPE_FULL => 'Full count — every stocked product in the warehouse',
        self::SCOPE_CYCLE => 'Cycle count — the products chosen for this round',
    ];

    public const STATUS_COUNTING = 'counting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_COUNTING => 'Counting',
        self::STATUS_POSTED => 'Posted',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'code', 'scope', 'count_date',
        'status', 'notes', 'line_count', 'counted_lines', 'variance_lines',
        'variance_value', 'stock_adjustment_id', 'created_by', 'posted_by',
        'posted_at', 'cancel_note',
    ];

    protected $casts = [
        'count_date' => 'date',
        'posted_at' => 'datetime',
        'variance_value' => 'decimal:4',
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

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('line_no');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_COUNTING);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
