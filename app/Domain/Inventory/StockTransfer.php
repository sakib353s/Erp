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
 */
class StockTransfer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_DISCREPANCY = 'discrepancy';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'branch_id', 'from_warehouse_id', 'to_warehouse_id',
        'transfer_no', 'transfer_date', 'status', 'narration',
        'created_by', 'dispatched_at', 'received_at',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }
}
