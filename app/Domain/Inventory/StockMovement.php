<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMMUTABLE stock ledger row. Created ONLY by StockLedgerService.
 * Never updated or deleted — corrections are compensating movements.
 */
class StockMovement extends Model
{
    public const TYPE_OPENING = 'OPENING';

    public const TYPE_ADJUST_IN = 'ADJUST_IN';

    public const TYPE_ADJUST_OUT = 'ADJUST_OUT';

    public const TYPE_TRANSIT_OUT = 'TRANSIT_OUT';

    public const TYPE_TRANSIT_IN = 'TRANSIT_IN';

    public const TYPE_TRANSIT_CLEAR = 'TRANSIT_CLEAR';

    public const TYPE_DAMAGE_OUT = 'DAMAGE_OUT';

    public const TYPE_WRITE_OFF = 'WRITE_OFF';

    public const TYPE_SALES_OUT = 'SALES_OUT';

    public const TYPE_SALES_RETURN = 'SALES_RETURN';

    public const TYPE_PACK_CONSUME = 'PACK_CONSUME';

    public const STATE_ON_HAND = 'on_hand';

    public const STATE_IN_TRANSIT = 'in_transit';

    public const STATE_DAMAGED = 'damaged';

    public const STATE_QUARANTINED = 'quarantined';

    public const STATE_RESERVED = 'reserved';

    public const INBOUND_TYPES = [
        self::TYPE_OPENING,
        self::TYPE_ADJUST_IN,
        self::TYPE_TRANSIT_IN,
        self::TYPE_SALES_RETURN,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'product_id',
        'movement_type', 'state', 'qty_signed', 'unit_cost',
        'valuation_method', 'layer_id', 'source_type', 'source_id',
        'source_event', 'idempotency_key', 'occurred_at', 'actor_id',
        'narration',
    ];

    protected $casts = [
        'qty_signed' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'occurred_at' => 'datetime',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isInbound(): bool
    {
        return in_array($this->movement_type, self::INBOUND_TYPES, true);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
