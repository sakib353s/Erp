<?php

namespace App\Domain\Masters;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bulk price update batch. Status is honest: queued (job picked up),
 * pending_approval (threshold exceeded, workflow open), applied, failed.
 */
class PriceBulkUpdate extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id', 'price_list_id', 'change_type', 'percent', 'set_price',
        'payload', 'note', 'status', 'threshold_pct', 'row_count',
        'applied_at', 'error', 'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'percent' => 'decimal:4',
        'set_price' => 'decimal:4',
        'threshold_pct' => 'decimal:4',
        'row_count' => 'integer',
        'applied_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }
}
