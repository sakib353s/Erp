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

    /** Goods leave sellable stock and are held in the damaged compartment. */
    public const TYPE_DAMAGE_IN = 'DAMAGE_IN';

    /** Goods leave the damaged compartment and are sellable again. */
    public const TYPE_DAMAGE_RELEASE = 'DAMAGE_RELEASE';

    /** Damaged goods leave the company (disposal) — consumes valuation layers. */
    public const TYPE_DAMAGE_OUT = 'DAMAGE_OUT';

    /** Stock leaves the company (loss, direct write-off) — consumes layers. */
    public const TYPE_WRITE_OFF = 'WRITE_OFF';

    public const TYPE_PURCHASE_RECEIPT = 'PURCHASE_RECEIPT';

    public const TYPE_PURCHASE_RETURN_OUT = 'PURCHASE_RETURN_OUT';

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
        self::TYPE_PURCHASE_RECEIPT,
        self::TYPE_DAMAGE_RELEASE,
    ];

    /**
     * Movements that only change WHICH COMPARTMENT goods sit in — no value
     * enters or leaves the company, so no valuation layer is created or
     * consumed. `on_hand` here means sellable stock: damaged and quarantined
     * goods are held outside it and are valued all the same.
     */
    public const COMPARTMENT_MOVE_TYPES = [
        self::TYPE_DAMAGE_IN,
        self::TYPE_DAMAGE_RELEASE,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'product_id',
        'movement_type', 'state', 'qty_signed', 'unit_cost',
        'valuation_method', 'layer_id', 'stock_batch_id', 'source_type', 'source_id',
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

    /** The batch a movement carried in, when it named one (§04-37). */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
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

    /**
     * How one movement changes the derived balance row. THE single state
     * machine: the live post path, the balance rebuild and the ledger's
     * running on-hand column all call this, so a movement can never mean one
     * thing in the ledger and another on a screen.
     *
     * on_hand is SELLABLE stock. `damaged` and `quarantined` are separate
     * compartments held outside it, so goods flagged as damaged leave on_hand
     * and their disposal never touches it a second time.
     *
     * @param  array<string, float>  $row
     */
    public static function applyDelta(array &$row, string $type, string $state, float $qty, bool $isInbound): void
    {
        if ($type === self::TYPE_DAMAGE_IN) {
            $row['on_hand'] -= $qty;
            $row['damaged'] += $qty;

            return;
        }

        if ($type === self::TYPE_DAMAGE_RELEASE) {
            $row['damaged'] -= $qty;
            $row['on_hand'] += $qty;

            return;
        }

        if ($state === self::STATE_ON_HAND) {
            $row['on_hand'] += $isInbound ? $qty : -$qty;

            return;
        }

        if ($state === self::STATE_IN_TRANSIT) {
            if ($type === self::TYPE_TRANSIT_OUT) {
                $row['on_hand'] -= $qty;
                $row['in_transit'] += $qty;
            } elseif ($type === self::TYPE_TRANSIT_IN) {
                $row['on_hand'] += $qty;
                $row['in_transit'] -= $qty;
            } elseif ($type === self::TYPE_TRANSIT_CLEAR) {
                $row['in_transit'] -= $qty;
            } else {
                $row['in_transit'] += $isInbound ? $qty : -$qty;
            }

            return;
        }

        if ($state === self::STATE_DAMAGED) {
            $row['damaged'] += $isInbound ? $qty : -$qty;

            return;
        }

        if ($state === self::STATE_QUARANTINED) {
            $row['quarantined'] += $isInbound ? $qty : -$qty;

            return;
        }

        if ($state === self::STATE_RESERVED) {
            $row['reserved'] += $isInbound ? $qty : -$qty;
        }
    }

    /** A zeroed balance row, in the shape applyDelta() expects. */
    public static function emptyRow(): array
    {
        return [
            'on_hand' => 0.0,
            'reserved' => 0.0,
            'in_transit' => 0.0,
            'damaged' => 0.0,
            'quarantined' => 0.0,
        ];
    }

    /**
     * The net change a movement makes to on-hand stock, from the same state
     * machine the ledger uses — never a second interpretation.
     */
    public static function onHandDelta(string $type, string $state, float $qty, bool $isInbound): float
    {
        $row = self::emptyRow();
        self::applyDelta($row, $type, $state, $qty, $isInbound);

        return $row['on_hand'];
    }
}
