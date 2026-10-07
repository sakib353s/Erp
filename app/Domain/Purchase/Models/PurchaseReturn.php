<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase return (§03.9). Goods going back to the supplier, with the debit
 * note the supplier owes us for it — the only correction path for a posted
 * receipt or a posted bill.
 */
class PurchaseReturn extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'cancelled'];

    public const OPEN_STATUSES = ['draft', 'pending_approval'];

    public const REASONS = [
        'damaged' => 'Damaged on arrival',
        'wrong_item' => 'Wrong item supplied',
        'short_supply' => 'Short supply',
        'quality' => 'Quality rejected',
        'excess' => 'Excess quantity',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'supplier_id', 'purchase_order_id', 'goods_receipt_id',
        'purchase_bill_id', 'warehouse_id', 'code', 'return_date', 'reason', 'reason_code',
        'goods_dispatched', 'status', 'posting_state', 'subtotal', 'discount_total', 'tax_total', 'total',
        'journal_entry_id', 'created_by', 'approved_by', 'approved_at', 'posted_at', 'cancel_reason',
    ];

    protected $casts = [
        'return_date' => 'date',
        'goods_dispatched' => 'boolean',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->posting_state === 'posted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code ?? ''] ?? 'Other';
    }

    /** @param  Builder<PurchaseReturn>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}
