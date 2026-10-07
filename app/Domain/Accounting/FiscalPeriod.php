<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\FiscalYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Posting gate: journal entries land in an OPEN period only.
 * Closing a period rejects all further postings into it (§7.1).
 */
class FiscalPeriod extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'fiscal_year_id', 'code', 'name', 'period_no',
        'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'closed_at' => 'datetime',
        'period_no' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function contains(Carbon $date): bool
    {
        return $date->between($this->starts_on, $this->ends_on);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
