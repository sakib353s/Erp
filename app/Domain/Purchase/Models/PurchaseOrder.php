<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order (§03). Status is the document's own lifecycle, never a
 * free-text note:
 *
 *   draft → pending_approval → approved → partially_received → received
 *                                 ↘ cancelled (from draft/approved only)
 *
 * `qty_received` per line is what makes "partially received" arithmetic
 * instead of a guess — see GoodsReceiptService.
 */
class PurchaseOrder extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'partially_received', 'received', 'cancelled'];

    /** Statuses that permit a goods receipt against this order. */
    public const RECEIVABLE = ['approved', 'partially_received'];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'supplier_id', 'code', 'reference',
        'order_date', 'expected_date', 'status', 'subtotal', 'discount_total', 'tax_total',
        'total', 'payment_terms', 'notes', 'created_by', 'approved_by', 'approved_at',
        'cancel_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

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

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::RECEIVABLE, true);
    }

    /** Total ordered quantity minus everything already received. */
    public function outstandingQty(): float
    {
        return round((float) $this->lines->sum(fn (PurchaseOrderLine $l) => max(0, (float) $l->qty_ordered - (float) $l->qty_received)), 4);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::RECEIVABLE);
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%"));
        });
    }
}
