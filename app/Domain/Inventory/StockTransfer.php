<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Two-leg stock transfer: dispatch (TRANSIT_OUT origin) → receive
 * (TRANSIT_IN dest). Short receive opens discrepancy — never silent loss.
 *
 * §04-28: a transfer is the second document that can take stock out of a
 * warehouse with no customer attached, so above the configured value it waits in
 * `pending_approval` — which is *not* a draft: dispatch refuses it until somebody
 * else approves, and "in transit" therefore always means it was approved.
 */
class StockTransfer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_DISCREPANCY = 'discrepancy';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Waiting for approval',
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_DISPATCHED => 'In transit',
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_DISCREPANCY => 'Received short',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'from_warehouse_id', 'to_warehouse_id',
        'transfer_no', 'transfer_date', 'status', 'narration', 'total_value',
        'created_by', 'approved_by', 'dispatched_at', 'received_at', 'approved_at', 'approval_note',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
        'total_value' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Waiting to be dispatched: a fresh draft, or one approval has cleared. */
    public function isDispatchable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
