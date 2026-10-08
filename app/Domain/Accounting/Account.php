<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chart of accounts leaf/header. System accounts cannot be deleted or
 * silently reparented (history-breaking). Controllers never hardcode
 * account codes — resolution is via posting_rules + this table.
 */
class Account extends Model
{
    use Auditable;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    protected $fillable = [
        'company_id', 'account_group_id', 'parent_id', 'code', 'name',
        'type', 'sub_type', 'is_group', 'is_system', 'is_active',
        'is_control_account', 'is_cash', 'is_bank', 'instrument',
        'bank_name', 'account_number', 'wallet_provider',
        'currency', 'description', 'sort',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'is_control_account' => 'boolean',
        'is_cash' => 'boolean',
        'is_bank' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Which instrument the money sitting in this account is held in — the
     * vocabulary CashBank owns (cash|bank|wallet). Kept on the row rather than
     * derived on read so a query can filter on it, but never left to disagree
     * with the is_cash / is_bank flags the chart of accounts has always carried:
     * a flagged account filled in here is the same account the money desk means,
     * whether it was seeded, imported or typed by hand. Only a blank is filled —
     * an instrument a screen set explicitly (a wallet, or a bank account on a
     * till's code) is a decision, not a default.
     */
    protected static function booted(): void
    {
        static::saving(function (Account $account): void {
            if (filled($account->instrument) || $account->is_group) {
                return;
            }

            $account->instrument = $account->is_cash
                ? 'cash'
                : ($account->is_bank ? 'bank' : null);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_group', false);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    /** Natural balance: assets/expenses debit-normal; others credit-normal. */
    public function naturalBalance(string $debitTotal, string $creditTotal): string
    {
        $debit = $debitTotal + 0;
        $credit = $creditTotal + 0;

        return $this->isDebitNormal()
            ? (string) ($debit - $credit)
            : (string) ($credit - $debit);
    }
}
