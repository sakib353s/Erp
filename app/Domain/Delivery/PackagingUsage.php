<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Packaging consumed against an order (02-98). qty/unit_cost are the
 * ledger's truth: unit_cost comes back from the PACK_CONSUME movement
 * (layer valuation), total_cost lands on sales_orders.packaging_cost.
 */
class PackagingUsage extends Model
{
    use Auditable;

    /** Spec table name — the pluralizer would derive packaging_usages. */
    protected $table = 'packaging_usage';

    protected $fillable = [
        'company_id', 'branch_id', 'sales_order_id', 'packaging_type_id',
        'product_id', 'warehouse_id', 'qty', 'unit_cost', 'total_cost',
        'stock_movement_id', 'consumed_at', 'consumed_by', 'notes',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'consumed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }
}
