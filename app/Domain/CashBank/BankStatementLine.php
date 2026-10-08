<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a bank's statement, in the bank's own language (§08-08/09).
 *
 * A statement `debit` is money that left our account and a statement `credit` is
 * money that entered it — the opposite of how the ledger reads those two words,
 * because the bank is describing its own balance sheet and we are the liability.
 * The table keeps the bank's words so the import cannot mistranslate a document
 * somebody may later compare by eye, and the service translates once, out loud,
 * where the arithmetic happens.
 *
 * `matched_journal_line_id` is the one book line this statement line was paired
 * with; `match_type` says whether a person or the amount-and-date rule did it.
 */
class BankStatementLine extends Model
{
    public const MATCH_AUTO = 'auto';

    public const MATCH_MANUAL = 'manual';

    protected $fillable = [
        'company_id', 'account_id', 'import_id', 'value_date', 'description', 'reference',
        'debit', 'credit', 'balance_after', 'line_no',
        'matched_journal_line_id', 'matched_at', 'match_type',
    ];

    protected $casts = [
        'value_date' => 'date',
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'matched_at' => 'datetime',
        'line_no' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'import_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_journal_line_id');
    }

    /**
     * The line as a change in the money we hold: positive means the account grew.
     * This is the only place the bank's two words are turned around.
     */
    public function signedAmount(): string
    {
        return number_format((float) $this->credit - (float) $this->debit, 4, '.', '');
    }

    public function isMatched(): bool
    {
        return $this->matched_journal_line_id !== null;
    }

    /** What a person calls this line on a screen. */
    public function label(): string
    {
        $parts = array_filter([$this->description, $this->reference]);

        return $parts === [] ? 'Statement line #'.$this->id : implode(' · ', $parts);
    }
}
