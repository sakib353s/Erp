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
 * A statement of fact about goods we hold (§04-46/04-47): these units are
 * broken, or these units are missing. Recording it does not invent a figure —
 * the value on the document comes from the valuation layers at that moment.
 *
 * `kind = damage` moves goods out of sellable stock into the damaged
 * compartment (reversible, no value change). `kind = loss` removes them from
 * the company and books the cost (`JournalPostingService`, posting rule
 * `stock_loss_posted`).
 */
class StockDamageEntry extends Model
{
    public const KIND_DAMAGE = 'damage';

    public const KIND_LOSS = 'loss';

    public const KINDS = [self::KIND_DAMAGE, self::KIND_LOSS];

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_RELEASED = 'released';

    /** Why goods were damaged — the vocabulary damage analytics groups by. */
    public const DAMAGE_REASONS = [
        'handling' => 'Handling damage',
        'transit' => 'Damaged in transit',
        'storage' => 'Damaged in storage',
        'water' => 'Water / moisture damage',
        'expiry' => 'Expired',
        'manufacturing' => 'Manufacturing defect',
        'other' => 'Other',
    ];

    /** Why stock was missing. */
    public const LOSS_REASONS = [
        'theft' => 'Theft',
        'miscount' => 'Counting error',
        'spillage' => 'Spillage / breakage',
        'shrinkage' => 'Shrinkage',
        'unaccounted' => 'Unaccounted',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'code', 'kind', 'entry_date',
        'reason_code', 'reason', 'status', 'total_value', 'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'total_value' => 'decimal:4',
    ];

    public function reasons(): array
    {
        return $this->kind === self::KIND_LOSS ? self::LOSS_REASONS : self::DAMAGE_REASONS;
    }

    public function reasonLabel(): string
    {
        return $this->reasons()[$this->reason_code] ?? ($this->reason ?: 'Not stated');
    }

    public function isDamage(): bool
    {
        return $this->kind === self::KIND_DAMAGE;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_RECORDED;
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
        return $this->hasMany(StockDamageEntryLine::class)->orderBy('line_no');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Accounting\JournalEntry::class);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}
