<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods received note (§03). Posting is the moment stock becomes real:
 * stock movements are written with source_type = goods_receipt so every
 * unit on hand can be traced to the paper that brought it in.
 *
 * Posted notes are immutable — a mistake is corrected by a return/credit
 * (next change), never by editing history.
 */
class GoodsReceipt extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'purchase_order_id',
        'code', 'challan_no', 'received_date', 'status', 'subtotal', 'total', 'notes',
        'received_by', 'posted_at', 'posted_by', 'cancel_reason',
    ];

    protected $casts = [
        'received_date' => 'date',
        'posted_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('sort_order');
    }

    /** Bills raised against this delivery (a delivery is normally billed once). */
    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
