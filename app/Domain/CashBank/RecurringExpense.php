<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\User;
use App\Domain\Purchase\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * §08-19 — an expense that comes round again: the schedule, never the posting.
 *
 * A recurring expense is one sentence a month — "office rent, 35,000, on the
 * 3rd" — and everything else follows from it. The amount may be the same figure
 * as last period's; it may also have changed, which is why the generated expense
 * copies the schedule rather than the previous expense.
 *
 * Frequency and the day are kept together because they answer one question:
 * which date is next? A monthly schedule keeps its day of the month and clamps
 * to the end of a short month — the 31st means the 28th in February and the 31st
 * again in March — because a rent day is a day in a contract, not a rounding
 * artefact.
 */
class RecurringExpense extends Model
{
    public const MONTHLY = 'monthly';

    public const WEEKLY = 'weekly';

    public const QUARTERLY = 'quarterly';

    public const YEARLY = 'yearly';

    public const FREQUENCIES = [
        self::MONTHLY => 'Every month',
        self::WEEKLY => 'Every week',
        self::QUARTERLY => 'Every quarter',
        self::YEARLY => 'Every year',
    ];

    /** Frequencies that repeat on a day of the month rather than a fixed weekday. */
    public const MONTH_DRIVEN = [self::MONTHLY, self::QUARTERLY, self::YEARLY];

    protected $fillable = [
        'company_id', 'branch_id', 'category_id', 'payee', 'supplier_id', 'narration',
        'amount', 'currency', 'settled_with', 'money_account_id',
        'frequency', 'day_of_month', 'starts_on', 'ends_on',
        'next_due_on', 'last_generated_on', 'generated_count', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'day_of_month' => 'integer',
            'generated_count' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_due_on' => 'date',
            'last_generated_on' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'recurring_expense_id');
    }

    public function frequencyLabel(): string
    {
        return self::FREQUENCIES[$this->frequency] ?? (string) $this->frequency;
    }

    /** "every month on the 3rd" — the sentence an operator checks against the contract. */
    public function rhythm(): string
    {
        $label = strtolower($this->frequencyLabel());

        if ($this->day_of_month === null || ! in_array($this->frequency, self::MONTH_DRIVEN, true)) {
            return $label;
        }

        return $label.' on the '.$this->ordinal((int) $this->day_of_month);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /** Due today or already overdue — the number the desk has to answer for. */
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

    /** Days late, or 0 when it is not due yet. */
    public function daysLate(?Carbon $asOf = null): int
    {
        if ($this->next_due_on === null) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfDay();

        return max(0, (int) $this->next_due_on->diffInDays($asOf, false));
    }

    /**
     * The date this schedule falls on after the given one. Month-end is clamped
     * (the 31st is the 28th in February) and short months never roll the day into
     * the next month, which is the difference between a rent schedule and a bug
     * that pays rent twice in March.
     */
    public function nextDueAfter(Carbon $from): Carbon
    {
        $next = match ($this->frequency) {
            self::WEEKLY => $from->copy()->addWeek(),
            self::QUARTERLY => $from->copy()->addMonthsNoOverflow(3),
            self::YEARLY => $from->copy()->addYearNoOverflow(),
            default => $from->copy()->addMonthNoOverflow(),
        };

        if ($this->day_of_month !== null && in_array($this->frequency, self::MONTH_DRIVEN, true)) {
            // setDate() rather than any day-of-week setter: "the 3rd" is a day of
            // the month, and a month too short for it takes its last day.
            $next = $next->setDate(
                (int) $next->year,
                (int) $next->month,
                min((int) $this->day_of_month, (int) $next->daysInMonth),
            );
        }

        return $next->startOfDay();
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
