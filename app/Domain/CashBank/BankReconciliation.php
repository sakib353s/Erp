<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One period proved against a bank statement (§08-08, §08-12).
 *
 * The figures a reconciliation is made of are stored on the row rather than
 * recomputed, because the reconciliation is a statement about a day: what the
 * bank said the balance was, what the books said, which lines on each side had
 * no counterpart, and what was left unexplained. Recomputing those next month
 * would answer a different question, and a signed reconciliation that changes
 * its own numbers is not a signed reconciliation.
 *
 * `difference` is the part nothing explains. Zero means the two sides are
 * *proved*: the leftover lines on each side account for the whole gap between
 * the bank's closing balance and ours. That is the only condition on which this
 * document may be signed off.
 */
class BankReconciliation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_BALANCED = 'balanced';

    public const STATUS_SIGNED_OFF = 'signed_off';

    protected $fillable = [
        'company_id', 'branch_id', 'account_id', 'period_start', 'period_end',
        'statement_opening', 'statement_closing', 'book_opening', 'book_balance',
        'unmatched_statement_total', 'unmatched_book_total', 'difference',
        'matched_count', 'unmatched_statement_count', 'unmatched_book_count',
        'status', 'notes', 'prepared_by', 'signed_off_by', 'signed_off_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'statement_opening' => 'decimal:4',
        'statement_closing' => 'decimal:4',
        'book_opening' => 'decimal:4',
        'book_balance' => 'decimal:4',
        'unmatched_statement_total' => 'decimal:4',
        'unmatched_book_total' => 'decimal:4',
        'difference' => 'decimal:4',
        'matched_count' => 'integer',
        'unmatched_statement_count' => 'integer',
        'unmatched_book_count' => 'integer',
        'signed_off_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function signedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class, 'reconciliation_id');
    }

    /** The bank's closing figure, restated as money we hold. */
    public function expectedClosing(): string
    {
        return number_format(
            (float) $this->book_balance
            + (float) $this->unmatched_statement_total
            - (float) $this->unmatched_book_total,
            4,
            '.',
            '',
        );
    }

    public function isProved(): bool
    {
        return bccomp((string) $this->difference, '0.0000', 4) === 0;
    }

    public function isSignedOff(): bool
    {
        return $this->status === self::STATUS_SIGNED_OFF;
    }

    /** Signed reconciliations are history: nothing may match or re-match inside them. */
    public function isFrozen(): bool
    {
        return $this->isSignedOff();
    }

    public function label(): string
    {
        return $this->period_start?->toDateString().' → '.$this->period_end?->toDateString();
    }
}
