<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the comparison a reconciliation is made of (§08-08).
 *
 * A book line, or a statement line, with the amount and the date it was
 * considered at, and whether it found a counterpart. Kept as rows rather than
 * recomputed because these are the lines the proof was made of: the unmatched
 * ones are the answer to "why is the bank's balance not ours", and that answer
 * has to keep standing after somebody imports next month's statement.
 */
class ReconciliationLine extends Model
{
    public const SIDE_BOOK = 'book';

    public const SIDE_STATEMENT = 'statement';

    public const STATE_MATCHED = 'matched';

    public const STATE_UNMATCHED = 'unmatched';

    protected $fillable = [
        'company_id', 'reconciliation_id', 'side', 'journal_line_id', 'statement_line_id',
        'movement_date', 'reference', 'description', 'amount', 'state', 'match_type',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount' => 'decimal:4',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class);
    }

    public function isBookSide(): bool
    {
        return $this->side === self::SIDE_BOOK;
    }

    public function isMatched(): bool
    {
        return $this->state === self::STATE_MATCHED;
    }

    /** Money in or out, said the way a statement reader expects to read it. */
    public function direction(): string
    {
        return bccomp((string) $this->amount, '0.0000', 4) < 0 ? 'out' : 'in';
    }
}
