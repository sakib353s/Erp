<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or credit leg of a journal entry. Amount is always positive;
 * direction lives on `dc`. No balance column — ledgers are projections.
 */
class JournalLine extends Model
{
    use Auditable;

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    protected $fillable = [
        'journal_entry_id', 'company_id', 'account_id', 'dc', 'amount',
        'currency', 'party_type', 'party_id', 'cost_center_id',
        'narration', 'line_no',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'line_no' => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function isDebit(): bool
    {
        return $this->dc === self::DEBIT;
    }

    public function isCredit(): bool
    {
        return $this->dc === self::CREDIT;
    }

    /** Signed amount for debit-normal aggregation (+debit, −credit). */
    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return $this->isDebit() ? $amount : -$amount;
    }
}
