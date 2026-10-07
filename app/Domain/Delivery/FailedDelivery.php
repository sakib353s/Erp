<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 02-96 Failed delivery: one row per failed delivery attempt for a
 * shipment. Opened by TrackShipmentEvent when the shipment actually
 * moves to delivery_failed (the courier's own observation), resolved
 * as retried/returned/reshipped by the recovery actions — the screen
 * lists open rows only.
 */
class FailedDelivery extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_RETRIED = 'retried';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_RESHIPPED = 'reshipped';

    protected $fillable = [
        'company_id', 'branch_id', 'shipment_id', 'sales_order_id', 'courier_id',
        'attempt_no', 'status', 'reason', 'failed_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'failed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
