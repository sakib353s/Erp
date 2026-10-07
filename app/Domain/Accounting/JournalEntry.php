<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\Concerns\ScopedByBranch;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable double-entry header. Created ONLY by JournalPostingService.
 * Once posting_state = posted, no edit/delete path exists — corrections
 * are reversal / credit-debit notes (§7.3).
 */
class JournalEntry extends Model
{
    use Auditable;
    use ScopedByBranch;

    public const STATE_DRAFT = 'draft';

    public const STATE_POSTED = 'posted';

    public const STATE_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'branch_id', 'fiscal_period_id', 'entry_no', 'entry_date',
        'journal_type', 'source_type', 'source_id', 'source_event',
        'reversal_of_id', 'description', 'narration',
        'total_debit', 'total_credit', 'checksum', 'posting_state',
        'posted_by', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'total_debit' => 'decimal:4',
        'total_credit' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->posting_state === self::STATE_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->posting_state === self::STATE_REVERSED;
    }

    public function isDraft(): bool
    {
        return $this->posting_state === self::STATE_DRAFT;
    }

    public function isManual(): bool
    {
        return $this->journal_type === 'manual';
    }

    public function scopePosted($query)
    {
        return $query->where('posting_state', self::STATE_POSTED);
    }
}
