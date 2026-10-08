<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * §08-10 — the standing instruction a bank account quietly keeps.
 *
 * "৳500 a quarter for account maintenance" and "0.15% of withdrawals, minimum
 * ৳100" are the two shapes a Bangladeshi bank's charges come in, and they are
 * different computations rather than different numbers: the first is known in
 * advance, the second is read off the account's own ledger. Both are stored here
 * with the account they hit and the account they are booked to, because a bank
 * charge is a policy decision twice over.
 *
 * The rhythm (frequency + day of the month) is the same machinery the recurring
 * expense desk uses, including month-end clamping: a charge that falls on the
 * 31st is the 28th in February and the 31st again in March.
 */
class BankChargeRule extends Model
{
    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const HALF_YEARLY = 'half_yearly';

    public const YEARLY = 'yearly';

    public const FREQUENCIES = [
        self::MONTHLY => 'Every month',
        self::QUARTERLY => 'Every quarter',
        self::HALF_YEARLY => 'Every six months',
        self::YEARLY => 'Every year',
    ];

    /** Months between two charges of a given rhythm. */
    public const MONTHS = [
        self::MONTHLY => 1,
        self::QUARTERLY => 3,
        self::HALF_YEARLY => 6,
        self::YEARLY => 12,
    ];

    public const BASIS_FIXED = 'fixed';

    public const BASIS_PERCENT = 'percent';

    public const BASES = [
        self::BASIS_FIXED => 'A fixed amount each time',
        self::BASIS_PERCENT => 'A percentage of what left the account',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'account_id', 'expense_account_id', 'name', 'narration',
        'basis', 'amount', 'rate_percent', 'min_amount',
        'frequency', 'day_of_month', 'starts_on', 'ends_on',
        'next_due_on', 'last_charged_on', 'charged_count', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'rate_percent' => 'decimal:6',
            'min_amount' => 'decimal:4',
            'day_of_month' => 'integer',
            'charged_count' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_due_on' => 'date',
            'last_charged_on' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(BankCharge::class, 'rule_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? (string) $this->frequency;
    }

    /** "every quarter on the 5th" — the sentence checked against the tariff sheet. */
    public function rhythm(): string
    {
        $label = strtolower($this->frequencyLabel());

        if ($this->day_of_month === null) {
            return $label;
        }

        return $label.' on the '.$this->ordinal((int) $this->day_of_month);
    }

    /** What the bank takes: "৳500" or "0.15% of withdrawals, min ৳100". */
    public function termsLabel(): string
    {
        if ($this->basis === self::BASIS_PERCENT) {
            $terms = rtrim(rtrim(number_format((float) $this->rate_percent, 4, '.', ''), '0'), '.').'% of what left the account';

            return (float) $this->min_amount > 0
                ? $terms.', minimum '.number_format((float) $this->min_amount, 2)
                : $terms;
        }

        return number_format((float) $this->amount, 2);
    }

    public function isDue(?Carbon $asOf = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $asOf = ($asOf ?? now())->copy()->startOfDay();

        if ($this->next_due_on === null || $this->next_due_on->gt($asOf)) {
            return false;
        }

        return $this->ends_on === null || $this->next_due_on->lte($this->ends_on);
    }

    public function daysLate(?Carbon $asOf = null): int
    {
        if ($this->next_due_on === null) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfDay();

        return max(0, (int) $this->next_due_on->diffInDays($asOf, false));
    }

    /**
     * The date this rule falls on after the given one. Month-end is clamped and
     * short months never carry the day into the next month.
     */
    public function nextDueAfter(Carbon $from): Carbon
    {
        $months = self::MONTHS[$this->frequency] ?? 1;
        $next = $from->copy()->addMonthsNoOverflow($months);

        if ($this->day_of_month !== null) {
            $next = $next->setDate(
                (int) $next->year,
                (int) $next->month,
                min((int) $this->day_of_month, (int) $next->daysInMonth),
            );
        }

        return $next->startOfDay();
    }

    /** The day the percentage is measured from: the last charge, or the start. */
    public function turnoverFrom(): ?Carbon
    {
        return ($this->last_charged_on ?? $this->starts_on)?->copy()->startOfDay();
    }

    protected function ordinal(int $day): string
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return $day.'th';
        }

        return $day.match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
