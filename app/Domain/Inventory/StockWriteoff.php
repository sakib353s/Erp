<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The document that removes value from stock (§04-48): goods are taken out of
 * the compartment named in `source_state`, the valuation layers are consumed
 * and the cost is posted to the books. Nobody approves their own write-off.
 */
class StockWriteoff extends Model
{
    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** Compartments a write-off may take goods from. */
    public const SOURCE_STATES = [
        StockMovement::STATE_DAMAGED => 'Damaged stock',
        StockMovement::STATE_QUARANTINED => 'Quarantined stock',
        StockMovement::STATE_ON_HAND => 'Sellable stock (direct write-off)',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'code', 'writeoff_date',
        'source_state', 'reason', 'status', 'total_value', 'journal_entry_id',
        'created_by', 'approved_by', 'approved_at', 'posted_at', 'decision_note',
    ];

    protected $casts = [
        'writeoff_date' => 'date',
        'total_value' => 'decimal:4',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockWriteoffLine::class)->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_STATES[$this->source_state] ?? (string) $this->source_state;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
