<?php

namespace App\Domain\Sales;

use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    public const STATUSES = [
        'pending', 'confirmed', 'processing', 'ready_to_ship', 'picked_up',
        'in_transit', 'out_for_delivery', 'delivered', 'completed', 'cancelled',
        'return_requested', 'return_approved', 'returned', 'refunded',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'customer_id', 'sales_person_id',
        'source_quotation_id', 'order_no', 'status', 'workflow_state',
        'order_date', 'currency', 'subtotal', 'discount', 'coupon_code', 'coupon_discount',
        'tax', 'shipping', 'grand_total', 'stock_reserved', 'cancel_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'subtotal' => 'decimal:4',
        'discount' => 'decimal:4',
        'coupon_discount' => 'decimal:4',
        'tax' => 'decimal:4',
        'shipping' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'packaging_cost' => 'decimal:4',
        'stock_reserved' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sourceQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'source_quotation_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function suspiciousFlag(): HasOne
    {
        return $this->hasOne(SuspiciousOrderFlag::class, 'sales_order_id');
    }
}
