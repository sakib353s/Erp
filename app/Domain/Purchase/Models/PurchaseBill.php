<?php

namespace App\Domain\Purchase\Models;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use App\Domain\Masters\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Purchase bill (§03.6) — the supplier's invoice against us.
 *
 * This is where a delivery turns into money owed: the GRN moved stock, the
 * bill posts the value to the ledger (Dr Inventory / Dr input VAT / Cr AP) and
 * creates the payable that ageing, statements and payments all read. A bill is
 * never edited once posted — a correction is a credit note, not a rewrite.
 */
class PurchaseBill extends Model
{
    use Auditable;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'cancelled'];

    /** Statuses that constitute a live payable. */
    public const PAYABLE = ['approved', 'partially_paid'];

    public const POSTING_STATES = ['draft', 'posted'];

    public const MATCH_STATES = ['matched', 'qty_mismatch', 'price_mismatch', 'unmatched', 'not_applicable'];

    protected $fillable = [
        'company_id', 'branch_id', 'supplier_id', 'purchase_order_id', 'goods_receipt_id',
        'code', 'supplier_bill_no', 'bill_date', 'due_date', 'status', 'posting_state',
        'match_state', 'match_summary', 'subtotal', 'discount_total', 'tax_total', 'total',
        'paid_amount', 'credited_amount', 'due_amount', 'notes', 'journal_entry_id', 'created_by',
        'approved_by', 'approved_at', 'posted_at', 'cancel_reason',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'discount_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
        'paid_amount' => 'decimal:4',
        'credited_amount' => 'decimal:4',
        'due_amount' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
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

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('sort_order');
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

    /** Is this bill part of what the company currently owes? */
    /**
     * The single definition of what is still owed on this bill:
     * total − money paid − credit taken back through purchase returns.
     * Cash and credit both reduce it, and neither rewrites the other.
     */
    public function balanceAgainst(?float $total = null, ?float $paid = null, ?float $credited = null): float
    {
        $total ??= (float) $this->total;
        $paid ??= (float) $this->paid_amount;
        $credited ??= (float) $this->credited_amount;

        return round(max(0, $total - $paid - $credited), 4);
    }

    /** How much of the bill has been closed, cash or credit. */
    public function settledAmount(): float
    {
        return round((float) $this->paid_amount + (float) $this->credited_amount, 4);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, self::PAYABLE, true) && (float) $this->due_amount > 0;
    }

    /** Has the supplier's paper been checked against what we ordered/received? */
    public function isMatched(): bool
    {
        return in_array($this->match_state, ['matched', 'not_applicable'], true);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::PAYABLE)->where('due_amount', '>', 0);
    }

    public function scopeOverdue($query)
    {
        return $query->open()->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('supplier_bill_no', 'like', "%{$term}%")
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
        });
    }

    /** Days past due (0 when not late or when there is no due date). */
    public function daysOverdue(): int
    {
        if ($this->due_date === null || (float) $this->due_amount <= 0) {
            return 0;
        }

        $days = (int) $this->due_date->diffInDays(Carbon::today(), false);

        return $days > 0 ? $days : 0;
    }

    /** Ageing bucket used by the payable screens and the supplier profile. */
    public function ageingBucket(): string
    {
        $days = $this->daysOverdue();

        return match (true) {
            $days === 0 => 'current',
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
