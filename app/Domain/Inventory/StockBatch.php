<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of one product in one warehouse (§04-37/04-38), with the dates that
 * decide whether it can still be sold.
 *
 * The batch owns no quantity of its own: how much is left is the sum of the
 * valuation layers that point at it, which is the same number the ledger
 * consumes. A second quantity would be a second truth (the rule §04-43 keeps
 * for bins).
 */
class StockBatch extends Model
{
    public const STATE_EXPIRED = 'expired';

    public const STATE_EXPIRING = 'expiring';

    public const STATE_OK = 'ok';

    public const STATE_UNDATED = 'undated';

    public const STATE_LABELS = [
        self::STATE_EXPIRED => 'Expired',
        self::STATE_EXPIRING => 'Expiring soon',
        self::STATE_OK => 'In date',
        self::STATE_UNDATED => 'No expiry date',
    ];

    protected $fillable = [
        'company_id', 'warehouse_id', 'product_id', 'batch_no',
        'manufactured_on', 'expires_on', 'source_type', 'source_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'manufactured_on' => 'date',
        'expires_on' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function layers(): HasMany
    {
        return $this->hasMany(StockLayer::class, 'stock_batch_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_batch_id');
    }

    public function expiryChanges(): HasMany
    {
        return $this->hasMany(StockBatchExpiryChange::class, 'stock_batch_id')->orderByDesc('id');
    }

    /** How much of this batch is still physically here — read from the layers. */
    public function remainingQty(): float
    {
        return round((float) $this->layers()->where('qty_remaining', '>', 0)->sum('qty_remaining'), 4);
    }

    /** What that quantity is worth, at the cost the layers were received at. */
    public function remainingValue(): float
    {
        return round((float) $this->layers()
            ->where('qty_remaining', '>', 0)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) as value')
            ->value('value'), 4);
    }

    public function label(): string
    {
        return $this->batch_no;
    }

    /** Days until the date passes; negative once it has. Null when undated. */
    public function daysToExpiry(): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    public function state(int $withinDays = 30): string
    {
        $days = $this->daysToExpiry();

        return match (true) {
            $days === null => self::STATE_UNDATED,
            $days < 0 => self::STATE_EXPIRED,
            $days <= $withinDays => self::STATE_EXPIRING,
            default => self::STATE_OK,
        };
    }

    public function stateLabel(int $withinDays = 30): string
    {
        return self::STATE_LABELS[$this->state($withinDays)] ?? $this->state($withinDays);
    }

    /** @param  Builder<StockBatch>  $query */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->toDateString());
    }

    /** @param  Builder<StockBatch>  $query */
    public function scopeExpiring(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString());
    }

    /** @param  Builder<StockBatch>  $query */
    public function scopeUndated(Builder $query): Builder
    {
        return $query->whereNull('expires_on');
    }

    /** @param  Builder<StockBatch>  $query */
    public function scopeWithStock(Builder $query): Builder
    {
        return $query->whereHas('layers', fn ($q) => $q->where('qty_remaining', '>', 0));
    }
}
