<?php

namespace App\Domain\Sales;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryChallan extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'ready', 'dispatched', 'delivered', 'cancelled'];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'sales_order_id',
        'document_type_id', 'challan_no', 'status', 'printed_title',
        'challan_date', 'dispatched_at', 'delivered_at', 'courier_name',
        'tracking_no', 'notes', 'created_by',
    ];

    protected $casts = [
        'challan_date' => 'date',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryChallanLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

class DeliveryChallanLine extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'delivery_challan_id', 'line_no', 'product_id',
        'description', 'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
