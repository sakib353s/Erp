<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §08-17 — an expense category is a general-ledger account with a name a human
 * uses. That is the entire idea: the person recording an expense picks
 * "Office rent", and the ledger is told 5220 — Rent Expense. Nothing in the
 * expense desk ever guesses an account, which is the reason this table exists
 * rather than a free-text type on the expense.
 *
 * A category is deactivated, never deleted: last year's expenses still point
 * here, and a report that cannot name the category of a four-year-old rent
 * payment is a report that has lost a fact.
 */
class ExpenseCategory extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'account_id', 'description',
        'is_active', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "5220 — Rent Expense", the way an operator reads an account. */
    public function accountLabel(): string
    {
        $account = $this->account;

        return $account === null
            ? 'no account'
            : trim($account->code.' — '.$account->name);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }
}
