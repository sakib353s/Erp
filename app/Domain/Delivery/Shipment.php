<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Courier handoff for an order (02-06). `pending_dispatch` is the
 * truthful local-assignment state: the courier is not configured, so no
 * dispatch or external reference exists. `assigned` means the courier
 * adapter accepted the handoff and `external_ref`/`dispatched_at` are
 * set — never fabricated when the integration is off.
 */
class Shipment extends Model
{
    use Auditable;

    public const STATUS_PENDING_DISPATCH = 'pending_dispatch';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_DELIVERY_FAILED = 'delivery_failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'sales_order_id', 'courier_id', 'status',
        'external_ref', 'dispatched_at', 'assigned_by',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function riderAssignments(): HasMany
    {
        return $this->hasMany(RiderAssignment::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class);
    }
}
