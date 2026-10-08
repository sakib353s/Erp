<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money moved between two of the company's own accounts (§08-04).
 *
 * It exists as a document rather than as two journal lines because the ledger
 * cannot answer the question this table answers: which two accounts, by whose
 * hand, on what reference. Both legs are posted in one transaction — a transfer
 * that leaves one side of the books standing is not a partially complete
 * transfer, it is a hole.
 */
class CashTransfer extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'branch_id', 'from_account_id', 'to_account_id', 'transfer_no',
        'transferred_on', 'amount', 'status', 'reference', 'narration',
        'journal_entry_id', 'idempotency_key', 'created_by',
    ];

    protected $casts = [
        'transferred_on' => 'date',
        'amount' => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
