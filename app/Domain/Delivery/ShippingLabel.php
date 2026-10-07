<?php

namespace App\Domain\Delivery;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated shipping label (02-09): receiver snapshot at print time,
 * sequential label_no from the shipping_label numbering rule, courier +
 * tracking carried over from the shipment when one exists. Null courier
 * or tracking is honest — the label still prints for hand-carried or
 * unconfigured-courier parcels. A missing numbering rule fails the
 * order instead of fabricating a number.
 */
class ShippingLabel extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'sales_order_id', 'shipment_id', 'courier_id',
        'label_no', 'receiver_name', 'receiver_phone', 'receiver_address',
        'district', 'parcel_description', 'weight_kg', 'tracking_code',
        'created_by',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }
}
