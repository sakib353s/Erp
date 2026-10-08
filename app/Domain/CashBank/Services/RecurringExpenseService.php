<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\RecurringExpense;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §08-19 — the expenses that come round again.
 *
 * The generator is deliberately thin, because the interesting rules already live
 * somewhere else: it does not build a journal entry (ExpenseService does, and
 * therefore the approval gate applies to a generated rent bill exactly as it
 * applies to one somebody typed), it does not decide who may spend (the schedule
 * is created under `expenses.recurring`), and it does not guess an account (the
 * category is an account, §08-17).
 *
 * What it owns is the calendar, plus the two rules that are worth stating
 * because both are ways this feature goes wrong in the wild:
 *
 *  · The actor is the person who created the schedule. A generated expense is
 *    therefore one they cannot approve themselves — automation must never be a
 *    way round a signature, and the most common way it becomes one is a system
 *    user nobody has to answer for.
 *  · A refusal does not advance the date. If the category has been switched off,
 *    the schedule stays overdue and visibly late until a person fixes it; moving
 *    the date anyway would silently skip a month, which is the one thing a
 *    recurring expense must never do.
 */
class RecurringExpenseService
{
    public function __construct(
        protected ExpenseService $expenses,
        protected MoneyAccountService $money,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /** @return Collection<int, RecurringExpense> */
    public function schedules(bool $activeOnly = false): Collection
    {
        return RecurringExpense::query()
            ->where('company_id', $this->companyId())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with(['category.account', 'moneyAccount', 'supplier', 'creator'])
            ->withCount('expenses')
            ->orderBy('is_active', 'desc')
            ->orderBy('next_due_on')
            ->get();
    }

    /**
     * Create or re-point a schedule. Stored as given: the desk shows the rhythm
     * back to the operator ("every month on the 3rd") so a wrong day is caught
     * against the contract on the screen, not discovered in the ledger.
     *
     * @param  array{category_id:int, payee:string, amount:string|float, settled_with:string,
     *               frequency:string, starts_on:string, money_account_id?:int|null,
     *               supplier_id?:int|null, narration?:string|null, day_of_month?:int|null,
     *               ends_on?:string|null, currency?:string|null, branch_id?:int|null,
     *               is_active?:bool}  $data
     */
    public function save(array $data, ?RecurringExpense $schedule = null, ?User $actor = null): RecurringExpense
    {
        $companyId = $this->companyId();

        $category = ExpenseCategory::query()
            ->where('company_id', $companyId)
            ->find((int) $data['category_id']);

        if ($category === null) {
            throw new RuntimeException('Pick an expense category — the category is what tells the ledger which account this money comes out of.');
        }

        $amount = round((float) $data['amount'], 4);

        if ($amount <= 0) {
            throw new RuntimeException('A recurring expense has to be more than nothing, or it is a reminder rather than an expense.');
        }

        $frequency = (string) $data['frequency'];

        if (! array_key_exists($frequency, RecurringExpense::FREQUENCIES)) {
            throw new RuntimeException('A schedule repeats weekly, monthly, quarterly or yearly — pick one.');
        }

        $settledWith = (string) $data['settled_with'];

        if (! array_key_exists($settledWith, Expense::SETTLED_WITH)) {
            throw new RuntimeException('A scheduled expense is either paid from an account or owed to a supplier — pick one.');
        }

        // Where the money leaves from, checked now rather than on the morning it
        // is due: a schedule that only fails on the day it fires is a bill nobody
        // paid until somebody notices.
        $money = null;

        if ($settledWith === Expense::SETTLED_MONEY) {
            $money = Account::query()
                ->where('company_id', $companyId)
                ->find((int) ($data['money_account_id'] ?? 0));

            if ($money === null) {
                throw new RuntimeException('Choose the account the money leaves from: cash, a bank account or a wallet.');
            }

            $this->money->assertOwned($money);

            if (! $this->money->isMoney($money)) {
                throw new RuntimeException("Money cannot leave from [{$money->name}] — that is not a cash, bank or wallet account.");
            }
        }

        $startsOn = Carbon::parse((string) $data['starts_on'])->startOfDay();
        $endsOn = isset($data['ends_on']) && $data['ends_on'] !== null && $data['ends_on'] !== ''
            ? Carbon::parse((string) $data['ends_on'])->startOfDay()
            : null;

        if ($endsOn !== null && $endsOn->lt($startsOn)) {
            throw new RuntimeException('A schedule cannot end before it starts.');
        }

        $dayOfMonth = $this->dayOfMonth($data['day_of_month'] ?? null, $startsOn, $frequency);

        $attributes = [
            'category_id' => $category->id,
            'payee' => trim((string) $data['payee']),
            'supplier_id' => $data['supplier_id'] ?? null,
            'narration' => $this->nullIfBlank($data['narration'] ?? null),
            'amount' => $amount,
            'currency' => strtoupper((string) ($data['currency'] ?? 'BDT')),
            'settled_with' => $settledWith,
            'money_account_id' => $money?->id,
            'frequency' => $frequency,
            'day_of_month' => $dayOfMonth,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($schedule, $attributes, $companyId, $actor, $startsOn) {
            if ($schedule === null) {
                $schedule = RecurringExpense::query()->create($attributes + [
                    'company_id' => $companyId,
                    'branch_id' => $this->context->branchId(),
                    // The first run is the start date itself, not one period later:
                    // a schedule entered today for rent due today owes rent today.
                    'next_due_on' => $startsOn->toDateString(),
                    'created_by' => $actor?->id,
                ]);
            } else {
                $schedule->fill($attributes)->save();

                // If the first date was moved forward, the next date cannot be
                // left behind it: the schedule would otherwise fire for a period
                // it no longer starts in.
                if ($schedule->isActive() && $schedule->next_due_on !== null && $schedule->starts_on !== null
                    && $schedule->next_due_on->lt($schedule->starts_on)) {
                    $schedule->forceFill(['next_due_on' => $schedule->starts_on->toDateString()])->save();
                }
            }

            $this->audit->record([
                'action' => 'cash.recurring_expense_saved',
                'entity_type' => 'recurring_expense',
                'entity_id' => $schedule->id,
                'branch_id' => $schedule->branch_id,
                'actor_id' => $actor?->id,
                'after' => [
                    'payee' => $schedule->payee,
                    'amount' => (string) $schedule->amount,
                    'frequency' => $schedule->frequency,
                    'next_due_on' => $schedule->next_due_on?->toDateString(),
                    'is_active' => $schedule->is_active,
                ],
            ]);

            return $schedule->load(['category.account', 'moneyAccount']);
        });
    }

    /** Pause or resume: paused schedules keep their history and their date. */
    public function setActive(RecurringExpense $schedule, bool $active, ?User $actor = null): RecurringExpense
    {
        $schedule->forceFill(['is_active' => $active])->save();

        $this->audit->record([
            'action' => $active ? 'cash.recurring_expense_resumed' : 'cash.recurring_expense_paused',
            'entity_type' => 'recurring_expense',
            'entity_id' => $schedule->id,
            'branch_id' => $schedule->branch_id,
            'actor_id' => $actor?->id,
            'after' => ['payee' => $schedule->payee, 'next_due_on' => $schedule->next_due_on?->toDateString()],
        ]);

        return $schedule;
    }

    /**
     * Generate every schedule that is due. A refusal on one schedule never stops
     * the others: rent and the internet line have nothing to do with each other.
     *
     * @return array{generated:int, refused:int, skipped:int, details:array<int, array<string, mixed>>}
     */
    public function generateDue(?int $limit = 200, ?Carbon $asOf = null, ?User $fallbackActor = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        $due = RecurringExpense::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->whereDate('next_due_on', '<=', $asOf->toDateString())
            ->with(['category', 'creator'])
            ->orderBy('next_due_on')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $result = ['generated' => 0, 'refused' => 0, 'skipped' => 0, 'details' => []];

        foreach ($due as $schedule) {
            if ($schedule->ends_on !== null && $schedule->next_due_on->gt($schedule->ends_on)) {
                // The contract ended. Stop the schedule rather than generating
                // into a period nobody agreed to.
                $schedule->forceFill(['is_active' => false])->save();
                $result['skipped']++;
                $result['details'][] = ['schedule' => $schedule->id, 'payee' => $schedule->payee, 'skipped' => 'the schedule ended on '.$schedule->ends_on->toDateString()];

                continue;
            }

            $actor = $schedule->creator ?? $fallbackActor;

            if ($actor === null) {
                $result['skipped']++;
                $result['details'][] = ['schedule' => $schedule->id, 'payee' => $schedule->payee, 'skipped' => 'the person who created the schedule no longer exists'];

                continue;
            }

            try {
                $expense = $this->generateOne($schedule, $actor, $asOf);
                $result['generated']++;
                $result['details'][] = [
                    'schedule' => $schedule->id,
                    'payee' => $schedule->payee,
                    'expense' => $expense->expense_no,
                    'amount' => (string) $expense->amount,
                    'state' => $expense->label(),
                ];
            } catch (RuntimeException $error) {
                $result['refused']++;
                $this->audit->record([
                    'action' => 'cash.recurring_expense_refused',
                    'entity_type' => 'recurring_expense',
                    'entity_id' => $schedule->id,
                    'branch_id' => $schedule->branch_id,
                    'actor_id' => $actor->id,
                    'after' => ['payee' => $schedule->payee, 'due_on' => $schedule->next_due_on?->toDateString(), 'reason' => $error->getMessage()],
                ]);
                $result['details'][] = ['schedule' => $schedule->id, 'payee' => $schedule->payee, 'refused' => $error->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Generate one schedule, on its due date, through the ordinary expense path.
     * The date only advances once the expense exists — exactly once. The unique
     * (schedule, date) index is the referee for two runs that overlap: whoever
     * arrives second finds the row the first one wrote and adopts it rather than
     * paying rent twice. The lookup happens before the insert, so the ordinary
     * path never relies on an exception to stay correct.
     */
    public function generateOne(RecurringExpense $schedule, User $actor, ?Carbon $asOf = null): Expense
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        if (! $schedule->isActive()) {
            throw new RuntimeException("{$schedule->payee} is paused, so nothing was generated. Resume the schedule first.");
        }

        if ($schedule->next_due_on === null || $schedule->next_due_on->gt($asOf)) {
            throw new RuntimeException("{$schedule->payee} is not due until ".($schedule->next_due_on?->toDateString() ?? 'never').'.');
        }

        $dueOn = $schedule->next_due_on->copy()->startOfDay();

        // A run that overlapped another one: the expense for this date is already
        // there. It is still this schedule's expense, so the only work left is to
        // put the date where the completed run would have left it.
        $already = $this->expenseFor($schedule, $dueOn);

        if ($already !== null) {
            $this->advance($schedule, $dueOn, created: false);

            return $already->load(['category.account', 'moneyAccount']);
        }

        try {
            return DB::transaction(fn () => $this->generateInsideTransaction($schedule, $actor, $dueOn));
        } catch (QueryException $error) {
            // The index refused a duplicate — raised outside the transaction so
            // the rollback has already happened. A different integrity problem
            // is re-raised rather than swallowed.
            $already = $this->expenseFor($schedule, $dueOn);

            if ($already === null) {
                throw $error;
            }

            $this->advance($schedule, $dueOn, created: false);

            return $already->load(['category.account', 'moneyAccount']);
        }
    }

    /** The expense this schedule already produced for a given date, if any. */
    protected function expenseFor(RecurringExpense $schedule, Carbon $dueOn): ?Expense
    {
        return Expense::query()
            ->where('recurring_expense_id', $schedule->id)
            ->whereDate('expense_date', $dueOn->toDateString())
            ->first();
    }

    /**
     * Write the expense and move the date forward in one transaction: a schedule
     * whose expense exists but whose date did not move would pay twice, and one
     * whose date moved without an expense would skip a month.
     */
    protected function generateInsideTransaction(RecurringExpense $schedule, User $actor, Carbon $dueOn): Expense
    {
        $expense = $this->expenses->create([
            'category_id' => $schedule->category_id,
            'expense_date' => $dueOn->toDateString(),
            'payee' => $schedule->payee,
            'supplier_id' => $schedule->supplier_id,
            'narration' => $schedule->narration,
            'amount' => (string) $schedule->amount,
            'currency' => $schedule->currency,
            'settled_with' => $schedule->settled_with,
            'money_account_id' => $schedule->money_account_id,
            'branch_id' => $schedule->branch_id,
            'recurring_expense_id' => $schedule->id,
        ], $actor);

        $this->advance($schedule, $dueOn, created: true);

        $this->audit->record([
            'action' => 'cash.recurring_expense_generated',
            'entity_type' => 'recurring_expense',
            'entity_id' => $schedule->id,
            'branch_id' => $schedule->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'payee' => $schedule->payee,
                'expense_no' => $expense->expense_no,
                'expense_date' => $dueOn->toDateString(),
                'next_due_on' => $schedule->next_due_on?->toDateString(),
                'state' => $expense->status,
            ],
        ]);

        return $expense->load(['category.account', 'moneyAccount']);
    }

    /**
     * Move the schedule to its next date. `last_generated_on` is only ever moved
     * forward by a real generation, so the desk can still say when it last paid.
     */
    protected function advance(RecurringExpense $schedule, Carbon $dueOn, bool $created): void
    {
        $next = $schedule->nextDueAfter($dueOn);

        $attributes = ['next_due_on' => $next->toDateString()];

        if ($created) {
            $attributes['last_generated_on'] = $dueOn->toDateString();
            $attributes['generated_count'] = ((int) $schedule->generated_count) + 1;
        }

        // A lease that has run out stops itself the moment its next date leaves
        // the contract, rather than waiting for a run to notice and generate
        // nothing. Whoever wants another period writes another schedule.
        if ($schedule->ends_on !== null && $next->gt($schedule->ends_on)) {
            $attributes['is_active'] = false;
        }

        $schedule->forceFill($attributes)->save();
    }

    /** How many schedules are waiting for today to be dealt with. */
    public function dueCount(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        return RecurringExpense::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->whereDate('next_due_on', '<=', $asOf->toDateString())
            ->count();
    }

    /**
     * The figures the screen opens with. `generated_this_month` is read from the
     * expenses themselves, so it says what actually reached the books rather than
     * what the schedules were supposed to produce.
     *
     * @return array{active:int, paused:int, due:int, generated_this_month:string, generated_count:int,
     *               next:?RecurringExpense, overdue:?RecurringExpense}
     */
    public function summary(): array
    {
        $schedules = $this->schedules();

        $generatedThisMonth = Expense::query()
            ->where('company_id', $this->companyId())
            ->whereNotNull('recurring_expense_id')
            ->whereDate('expense_date', '>=', now()->startOfMonth()->toDateString())->whereDate('expense_date', '<=', now()->endOfMonth()->toDateString());

        $generatedCount = (clone $generatedThisMonth)->count();
        $generatedTotal = (float) (clone $generatedThisMonth)->sum('amount');

        $active = $schedules->where('is_active', true);
        $due = $active->filter(fn (RecurringExpense $schedule) => $schedule->isDue());

        return [
            'active' => $active->count(),
            'paused' => $schedules->count() - $active->count(),
            'due' => $due->count(),
            'generated_this_month' => number_format($generatedTotal, 2, '.', ''),
            'generated_count' => $generatedCount,
            'next' => $active->sortBy(fn (RecurringExpense $schedule) => $schedule->next_due_on?->toDateString())->first(),
            'overdue' => $due->sortBy(fn (RecurringExpense $schedule) => $schedule->next_due_on?->toDateString())->first(),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * The day of the month a schedule repeats on: what the operator said, or the
     * day the schedule starts on. A weekly schedule has none — its weekday is
     * whatever the start date was.
     */
    protected function dayOfMonth(mixed $value, Carbon $startsOn, string $frequency): ?int
    {
        if (! in_array($frequency, RecurringExpense::MONTH_DRIVEN, true)) {
            return null;
        }

        if ($value === null || $value === '') {
            return (int) $startsOn->day;
        }

        $day = (int) $value;

        if ($day < 1 || $day > 31) {
            throw new RuntimeException('A day of the month is between 1 and 31. A month that is too short clamps to its last day.');
        }

        return $day;
    }

    protected function companyId(): int
    {
        return $this->context->companyId();
    }

    protected function nullIfBlank(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : $value;
    }
}
