<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\BankCharge;
use App\Domain\CashBank\BankChargeRule;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §08-10 — the money a bank takes without asking.
 *
 * A bank charge is the one posting in this module nobody performed: the bank took
 * the money, told nobody, and the first the company hears of it is a line on a
 * statement. Two things follow from that, and they are the whole design:
 *
 *  · **The desk must be able to say what a charge was.** A maintenance fee is a
 *    fixed figure, but a commission is a percentage of the money that *left* the
 *    account since the last charge — a credit to an asset in ledger terms — so it
 *    is read off the account's own posted lines rather than typed. The turnover
 *    and the rate travel on the charge row, which is what makes the figure
 *    checkable against the bank's own arithmetic instead of merely plausible.
 *  · **A charge is never a payment to a party.** The entry is two lines, Dr the
 *    expense the bank charged and Cr the account it came out of, and it goes
 *    through the same `JournalPostingService` as everything else. Nothing is
 *    invented to make it look like a supplier was paid.
 *
 * Above those two rules sit the same refusals the rest of the module keeps: a
 * charge cannot be posted from an account that is not money, cannot be booked to
 * an account that is not a postable expense, cannot post twice for the same day
 * from the same rule, and is undone by a reversal rather than an edit.
 */
class BankChargeService
{
    /** The standard chart's Bank Charges account — the desk's default, not a rule. */
    public const DEFAULT_EXPENSE_CODE = '5280';

    public function __construct(
        protected JournalPostingService $journals,
        protected NumberingService $numbering,
        protected MoneyAccountService $money,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    // ----------------------------------------------------------------- rules

    /** @return Collection<int, BankChargeRule> */
    public function rules(bool $activeOnly = false): Collection
    {
        return BankChargeRule::query()
            ->where('company_id', $this->companyId())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with(['account', 'expenseAccount', 'creator'])
            ->withCount('charges')
            ->orderBy('is_active', 'desc')
            ->orderBy('next_due_on')
            ->get();
    }

    /**
     * Write a rule. Nothing is charged by writing one: a rule is a standing
     * statement about a tariff, and the money moves on the day it falls due.
     *
     * @param  array{account_id:int, expense_account_id:int, name:string, basis:string,
     *               frequency:string, starts_on:string, amount?:string|float|null,
     *               rate_percent?:string|float|null, min_amount?:string|float|null,
     *               narration?:string|null, day_of_month?:int|null, ends_on?:string|null,
     *               branch_id?:int|null, is_active?:bool}  $data
     */
    public function saveRule(array $data, ?BankChargeRule $rule = null, ?User $actor = null): BankChargeRule
    {
        $companyId = $this->companyId();

        $name = trim((string) $data['name']);

        if ($name === '') {
            throw new RuntimeException('Name the charge — "account maintenance" and "SMS alert fee" are different lines on the same statement.');
        }

        $account = $this->moneyAccountOrFail((int) $data['account_id'], 'A bank charge comes out of one of the company\'s own accounts.');
        $expense = $this->expenseAccountOrFail((int) $data['expense_account_id']);

        $basis = (string) $data['basis'];

        if (! array_key_exists($basis, BankChargeRule::BASES)) {
            throw new RuntimeException('A charge is either a fixed amount or a percentage of what left the account — pick one.');
        }

        $frequency = (string) $data['frequency'];

        if (! array_key_exists($frequency, BankChargeRule::FREQUENCIES)) {
            throw new RuntimeException('A charge repeats monthly, quarterly, half-yearly or yearly — pick one.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 4);
        $rate = round((float) ($data['rate_percent'] ?? 0), 6);
        $minimum = round((float) ($data['min_amount'] ?? 0), 4);

        if ($basis === BankChargeRule::BASIS_FIXED && $amount <= 0) {
            throw new RuntimeException('A fixed charge has to be more than nothing — a rule that charges zero is a reminder, not a charge.');
        }

        if ($basis === BankChargeRule::BASIS_PERCENT && $rate <= 0) {
            throw new RuntimeException('A percentage charge needs a rate: 0.15% of withdrawals, for instance.');
        }

        $dayOfMonth = $data['day_of_month'] !== null ? (int) $data['day_of_month'] : null;

        if ($dayOfMonth !== null && ($dayOfMonth < 1 || $dayOfMonth > 31)) {
            throw new RuntimeException('A day of the month is between 1 and 31. A short month takes its last day instead of spilling into the next one.');
        }

        $startsOn = Carbon::parse((string) $data['starts_on'])->startOfDay();
        $endsOn = isset($data['ends_on']) && $data['ends_on'] !== null && $data['ends_on'] !== ''
            ? Carbon::parse((string) $data['ends_on'])->startOfDay()
            : null;

        if ($endsOn !== null && $endsOn->lt($startsOn)) {
            throw new RuntimeException('This rule ends before it starts, so it would never charge anything.');
        }

        $attributes = [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'] ?? $this->context->branchId(),
            'account_id' => $account->id,
            'expense_account_id' => $expense->id,
            'name' => $name,
            'narration' => $this->nullIfBlank($data['narration'] ?? null),
            'basis' => $basis,
            'amount' => $amount,
            'rate_percent' => $basis === BankChargeRule::BASIS_PERCENT ? $rate : 0,
            'min_amount' => $basis === BankChargeRule::BASIS_PERCENT ? $minimum : 0,
            'frequency' => $frequency,
            'day_of_month' => $dayOfMonth,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn?->toDateString(),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($rule === null) {
            // The first date is the start date with this rhythm's day applied, so
            // a rule written on the 20th for "every quarter on the 5th" does not
            // charge on the 20th by accident.
            $attributes['next_due_on'] = $this->firstDueDate($startsOn, $dayOfMonth)->toDateString();
            $attributes['created_by'] = $actor?->id;

            $rule = BankChargeRule::query()->create($attributes);

            $this->audit->record([
                'action' => 'cash.bank_charge_rule_saved',
                'entity_type' => 'bank_charge_rule',
                'entity_id' => $rule->id,
                'branch_id' => $rule->branch_id,
                'actor_id' => $actor?->id,
                'after' => [
                    'name' => $rule->name,
                    'account' => $account->name,
                    'expense_account' => $expense->name,
                    'terms' => $rule->termsLabel(),
                    'rhythm' => $rule->rhythm(),
                    'next_due_on' => $rule->next_due_on?->toDateString(),
                ],
            ]);

            return $rule->load(['account', 'expenseAccount']);
        }

        $this->assertOwned($rule);

        $existing = $rule->isActive();
        unset($attributes['company_id'], $attributes['created_by']);

        // A rule whose rhythm changed needs its next date recomputed from
        // tomorrow, otherwise the new rhythm would only take effect after the
        // old date had already fired.
        $attributes['next_due_on'] = $this->firstDueDate(
            $existing && $rule->next_due_on !== null && $rule->next_due_on->gt(now())
                ? $rule->next_due_on
                : now()->copy()->startOfDay(),
            $dayOfMonth,
        )->toDateString();

        $rule->fill($attributes)->save();

        $this->audit->record([
            'action' => 'cash.bank_charge_rule_updated',
            'entity_type' => 'bank_charge_rule',
            'entity_id' => $rule->id,
            'branch_id' => $rule->branch_id,
            'actor_id' => $actor?->id,
            'after' => ['name' => $rule->name, 'terms' => $rule->termsLabel(), 'rhythm' => $rule->rhythm()],
        ]);

        return $rule->load(['account', 'expenseAccount']);
    }

    /** Stop or resume a rule. Money already charged is untouched either way. */
    public function toggleRule(BankChargeRule $rule, User $actor): BankChargeRule
    {
        $this->assertOwned($rule);

        $resuming = ! $rule->isActive();

        $attributes = ['is_active' => $resuming];

        // A rule resumed months later must not fire for every period it missed:
        // the desk starts it from the next occurrence, and anything the bank
        // really did take in the meantime is recorded by hand.
        if ($resuming && ($rule->next_due_on === null || $rule->next_due_on->lt(now()))) {
            $attributes['next_due_on'] = $this->firstDueDate(now()->copy()->startOfDay(), $rule->day_of_month)->toDateString();
        }

        $rule->forceFill($attributes)->save();

        $this->audit->record([
            'action' => $resuming ? 'cash.bank_charge_rule_resumed' : 'cash.bank_charge_rule_paused',
            'entity_type' => 'bank_charge_rule',
            'entity_id' => $rule->id,
            'branch_id' => $rule->branch_id,
            'actor_id' => $actor->id,
            'after' => ['name' => $rule->name, 'next_due_on' => $rule->next_due_on?->toDateString()],
        ]);

        return $rule->refresh();
    }

    // --------------------------------------------------------------- charging

    /**
     * What a rule would charge if it ran today. The percentage is measured on the
     * money that left the account since the last charge — a credit to an asset —
     * which is how a bank's commission on withdrawals is actually computed.
     *
     * @return array{amount:string, turnover:string, rate:float, basis:string, from:string|null, to:string}
     */
    public function quote(BankChargeRule $rule, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $from = $rule->turnoverFrom();

        if ($rule->basis !== BankChargeRule::BASIS_PERCENT) {
            return [
                'amount' => number_format((float) $rule->amount, 4, '.', ''),
                'turnover' => '0.0000',
                'rate' => 0.0,
                'basis' => BankChargeRule::BASIS_FIXED,
                'from' => null,
                'to' => $asOf->toDateString(),
            ];
        }

        $turnover = 0.0;

        if ($from !== null) {
            // Money *leaving* an asset is a credit line: the commission a bank
            // charges on withdrawals is a percentage of exactly that.
            $turnover = (float) JournalLine::query()
                ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.company_id', $this->companyId())
                ->where('journal_lines.account_id', $rule->account_id)
                ->where('journal_lines.dc', JournalLine::CREDIT)
                ->where('e.posting_state', JournalEntry::STATE_POSTED)
                ->whereDate('e.entry_date', '>', $from->toDateString())
                ->whereDate('e.entry_date', '<=', $asOf->toDateString())
                ->sum('journal_lines.amount');
        }

        // No turnover, no commission — and no minimum either. A floor under a
        // commission is not a licence to charge for a quarter in which nothing
        // happened; the bank that takes a fee regardless is charging something
        // else, and that something else belongs in its own rule.
        $amount = $turnover > 0 ? round($turnover * ((float) $rule->rate_percent / 100), 4) : 0.0;

        if ($amount > 0 && (float) $rule->min_amount > 0) {
            $amount = max($amount, (float) $rule->min_amount);
        }

        return [
            'amount' => number_format($amount, 4, '.', ''),
            'turnover' => number_format($turnover, 4, '.', ''),
            'rate' => (float) $rule->rate_percent,
            'basis' => BankChargeRule::BASIS_PERCENT,
            'from' => $from->toDateString(),
            'to' => $asOf->toDateString(),
        ];
    }

    /**
     * Post a charge now — the button, and the way a charge that only ever
     * appeared on a statement gets into the books.
     *
     * @param  array{account_id:int, expense_account_id?:int|null, rule_id?:int|null,
     *               amount?:string|float|null, charged_on?:string|null,
     *               narration?:string|null, reference?:string|null, branch_id?:int|null}  $data
     */
    public function record(array $data, User $actor): BankCharge
    {
        $rule = null;

        if (($data['rule_id'] ?? null) !== null) {
            $rule = BankChargeRule::query()
                ->where('company_id', $this->companyId())
                ->with(['account', 'expenseAccount'])
                ->find((int) $data['rule_id']);

            if ($rule === null) {
                throw new RuntimeException('That bank charge rule does not exist in this company.');
            }
        }

        $account = $this->moneyAccountOrFail(
            (int) ($data['account_id'] ?? $rule?->account_id),
            'A bank charge comes out of one of the company\'s own accounts.',
        );

        $expense = $this->expenseAccountOrFail(
            (int) ($data['expense_account_id'] ?? $rule?->expense_account_id ?? $this->defaultExpenseAccount()?->id ?? 0),
        );

        $chargedOn = Carbon::parse((string) ($data['charged_on'] ?? now()->toDateString()))->startOfDay();

        $quote = $rule !== null
            ? $this->quote($rule, $chargedOn)
            : ['amount' => number_format(round((float) ($data['amount'] ?? 0), 4), 4, '.', ''), 'turnover' => '0.0000', 'rate' => 0.0, 'basis' => BankChargeRule::BASIS_FIXED];

        // A percentage charge on an account nothing left charges nothing. Posting
        // a zero would put a meaningless line in front of whoever reads the
        // register, so the desk says so instead.
        if ((float) $quote['amount'] <= 0) {
            throw new RuntimeException($rule !== null && $rule->basis === BankChargeRule::BASIS_PERCENT
                ? 'Nothing left this account since the last charge, so there is no commission to take. The next run will look again.'
                : 'A charge has to be more than nothing.');
        }

        $narration = $this->nullIfBlank($data['narration'] ?? null) ?? $rule?->narration;
        $reference = $this->nullIfBlank($data['reference'] ?? null);

        return DB::transaction(function () use ($account, $expense, $rule, $quote, $chargedOn, $narration, $reference, $data, $actor) {
            $charge = BankCharge::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $data['branch_id'] ?? $rule?->branch_id ?? $this->context->branchId(),
                'rule_id' => $rule?->id,
                'account_id' => $account->id,
                'expense_account_id' => $expense->id,
                'charge_no' => $this->nextChargeNo($data['branch_id'] ?? $rule?->branch_id ?? $this->context->branchId()),
                'charged_on' => $chargedOn->toDateString(),
                'amount' => $quote['amount'],
                'basis' => $quote['basis'],
                'turnover' => $quote['turnover'],
                'rate_percent' => $quote['rate'],
                'narration' => $narration ?? ($rule?->name),
                'reference' => $reference,
                'status' => BankCharge::STATUS_POSTED,
                'created_by' => $actor->id,
            ]);

            $entry = $this->post($charge, $account, $expense, $actor);

            $charge->forceFill(['journal_entry_id' => $entry->id])->save();

            $this->audit->record([
                'action' => 'cash.bank_charge_recorded',
                'entity_type' => 'bank_charge',
                'entity_id' => $charge->id,
                'branch_id' => $charge->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'charge_no' => $charge->charge_no,
                    'account' => $account->name,
                    'expense_account' => $expense->name,
                    'amount' => (string) $charge->amount,
                    'basis' => $charge->basisLabel(),
                    'rule' => $rule?->name,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $charge->refresh()->load(['account', 'expenseAccount', 'rule']);
        });
    }

    /**
     * Run the rules. Each one that has come due posts its own charge, and the
     * number a generated charge is made of comes from the rule rather than from
     * this method — a fixed fee and a commission are computed in `quote()`.
     *
     * @return array{generated:int, refused:int, skipped:int, due:int, details:array<int, array<string, mixed>>}
     */
    public function generateDue(?Carbon $asOf = null, ?User $fallbackActor = null, bool $dryRun = false): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $result = ['generated' => 0, 'refused' => 0, 'skipped' => 0, 'due' => 0, 'details' => []];

        $rules = BankChargeRule::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->whereNotNull('next_due_on')
            ->whereDate('next_due_on', '<=', $asOf->toDateString())
            ->with(['account', 'expenseAccount', 'creator'])
            ->orderBy('next_due_on')
            ->get();

        $result['due'] = $rules->count();

        foreach ($rules as $rule) {
            if ($rule->ends_on !== null && $rule->next_due_on->gt($rule->ends_on)) {
                // The tariff arrangement ended: stop the rule rather than charge
                // into a period nobody agreed to.
                $rule->forceFill(['is_active' => false])->save();
                $result['skipped']++;
                $result['details'][] = ['rule' => $rule->id, 'name' => $rule->name, 'skipped' => 'the arrangement ended on '.$rule->ends_on->toDateString()];

                continue;
            }

            $actor = $rule->creator ?? $fallbackActor;

            if ($actor === null) {
                $result['skipped']++;
                $result['details'][] = ['rule' => $rule->id, 'name' => $rule->name, 'skipped' => 'the person who wrote the rule no longer exists'];

                continue;
            }

            if ($dryRun) {
                $quote = $this->quote($rule, $asOf);
                $result['generated']++;
                $result['details'][] = [
                    'rule' => $rule->id,
                    'name' => $rule->name,
                    'due_on' => $rule->next_due_on->toDateString(),
                    'would_charge' => $quote['amount'],
                    'basis' => $rule->basis,
                    'dry_run' => true,
                ];

                continue;
            }

            try {
                $charge = $this->generateOne($rule, $actor, $asOf);
                $result['generated']++;
                $result['details'][] = [
                    'rule' => $rule->id,
                    'name' => $rule->name,
                    'charge_no' => $charge->charge_no,
                    'amount' => (string) $charge->amount,
                    'charged_on' => $charge->charged_on->toDateString(),
                ];
            } catch (RuntimeException $error) {
                $result['refused']++;
                $this->audit->record([
                    'action' => 'cash.bank_charge_refused',
                    'entity_type' => 'bank_charge_rule',
                    'entity_id' => $rule->id,
                    'branch_id' => $rule->branch_id,
                    'actor_id' => $actor->id,
                    'after' => ['name' => $rule->name, 'due_on' => $rule->next_due_on?->toDateString(), 'reason' => $error->getMessage()],
                ]);
                $result['details'][] = ['rule' => $rule->id, 'name' => $rule->name, 'refused' => $error->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Generate one rule's charge for the date it has come due, and move the date
     * forward in the same transaction: a rule whose charge exists but whose date
     * did not move would be charged twice, and one whose date moved without a
     * charge would be skipped entirely.
     */
    public function generateOne(BankChargeRule $rule, User $actor, ?Carbon $asOf = null): BankCharge
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        if (! $rule->isActive()) {
            throw new RuntimeException("{$rule->name} is paused, so nothing was charged. Resume the rule first.");
        }

        if ($rule->next_due_on === null || $rule->next_due_on->gt($asOf)) {
            throw new RuntimeException("{$rule->name} is not due until ".($rule->next_due_on?->toDateString() ?? 'never').'.');
        }

        $dueOn = $rule->next_due_on->copy()->startOfDay();

        // A run that overlapped another one: the charge for this date is already
        // there. It belongs to this rule, so the only work left is to put the date
        // where the completed run would have left it.
        $already = $this->chargeFor($rule, $dueOn);

        if ($already !== null) {
            $this->advance($rule, $dueOn, created: false);

            return $already->load(['account', 'expenseAccount']);
        }

        try {
            return DB::transaction(fn () => $this->generateInsideTransaction($rule, $actor, $dueOn));
        } catch (QueryException $error) {
            // The (rule, date) index refused a duplicate — raised outside the
            // transaction, so the rollback has already happened. A different
            // integrity problem is re-raised rather than swallowed.
            $already = $this->chargeFor($rule, $dueOn);

            if ($already === null) {
                throw $error;
            }

            $this->advance($rule, $dueOn, created: false);

            return $already->load(['account', 'expenseAccount']);
        }
    }

    /** Undo a charge with an answering entry. A posted charge really happened. */
    public function reverse(BankCharge $charge, User $actor, string $reason): BankCharge
    {
        $this->assertOwned($charge);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why the charge is being reversed — "keyed twice" and "the bank reversed it" are different facts.');
        }

        if ($charge->isReversed()) {
            throw new RuntimeException("Charge {$charge->charge_no} has already been reversed.");
        }

        if ($charge->journalEntry === null) {
            throw new RuntimeException("Charge {$charge->charge_no} has no journal entry, so there is nothing to reverse.");
        }

        return DB::transaction(function () use ($charge, $actor, $reason) {
            $reversal = $this->journals->reverse($charge->journalEntry, $reason, $actor);

            $charge->forceFill([
                'status' => BankCharge::STATUS_REVERSED,
                'reversal_entry_id' => $reversal->id,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            $this->audit->record([
                'action' => 'cash.bank_charge_reversed',
                'entity_type' => 'bank_charge',
                'entity_id' => $charge->id,
                'branch_id' => $charge->branch_id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'after' => [
                    'charge_no' => $charge->charge_no,
                    'amount' => (string) $charge->amount,
                    'reversal_entry' => $reversal->entry_no,
                ],
            ]);

            return $charge->refresh()->load(['account', 'expenseAccount', 'reversalEntry']);
        });
    }

    // ------------------------------------------------------------------ lists

    /**
     * The register, newest first.
     *
     * @param  array{account?:int|null, status?:string|null, from?:string|null,
     *               to?:string|null, origin?:string|null, q?:string|null}  $filters
     * @return Collection<int, BankCharge>
     */
    public function charges(array $filters = [], int $limit = 100): Collection
    {
        return BankCharge::query()
            ->where('company_id', $this->companyId())
            ->when(($filters['account'] ?? null) !== null, fn ($query) => $query->where('account_id', $filters['account']))
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['origin'] ?? null) === 'rule', fn ($query) => $query->whereNotNull('rule_id'))
            ->when(($filters['origin'] ?? null) === 'manual', fn ($query) => $query->whereNull('rule_id'))
            ->when(($filters['from'] ?? null) !== null, fn ($query) => $query->whereDate('charged_on', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($query) => $query->whereDate('charged_on', '<=', $filters['to']))
            ->when(($filters['q'] ?? null) !== null, function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(fn ($inner) => $inner->where('charge_no', 'like', $term)
                    ->orWhere('narration', 'like', $term)
                    ->orWhere('reference', 'like', $term));
            })
            ->with(['account', 'expenseAccount', 'rule', 'creator', 'journalEntry'])
            ->orderByDesc('charged_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The figures the desk opens with. Every number is a sum over the charges
     * themselves or a count of the rules — nothing here is estimated.
     *
     * @return array{month:string, year:string, count_year:int, charges_year:int,
     *               rules:int, active_rules:int, due:int, due_value:string, average:string}
     */
    public function summary(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $companyId = $this->companyId();

        $live = BankCharge::query()
            ->where('company_id', $companyId)
            ->where('status', BankCharge::STATUS_POSTED);

        $thisMonth = (clone $live)
            ->whereBetween('charged_on', [$asOf->copy()->startOfMonth()->toDateString(), $asOf->copy()->endOfMonth()->toDateString()]);

        $thisYear = (clone $live)
            ->whereBetween('charged_on', [$asOf->copy()->startOfYear()->toDateString(), $asOf->copy()->endOfYear()->toDateString()]);

        $yearCount = (clone $thisYear)->count();
        $yearTotal = (float) (clone $thisYear)->sum('amount');

        $rules = BankChargeRule::query()->where('company_id', $companyId);

        $due = (clone $rules)
            ->where('is_active', true)
            ->whereNotNull('next_due_on')
            ->whereDate('next_due_on', '<=', $asOf->toDateString())
            ->with('account');

        $dueValue = (float) (clone $due)->get()->sum(fn (BankChargeRule $rule) => (float) $this->quote($rule, $asOf)['amount']);

        return [
            'month' => number_format((float) (clone $thisMonth)->sum('amount'), 2, '.', ''),
            'month_count' => (clone $thisMonth)->count(),
            'year' => number_format($yearTotal, 2, '.', ''),
            'count_year' => $yearCount,
            'average' => $yearCount > 0 ? number_format($yearTotal / $yearCount, 2, '.', '') : '0.00',
            'rules' => (clone $rules)->count(),
            'active_rules' => (clone $rules)->where('is_active', true)->count(),
            'due' => (clone $due)->count(),
            'due_value' => number_format($dueValue, 2, '.', ''),
        ];
    }

    /** How many rules have come due — the figure the schedule reports. */
    public function dueCount(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        return BankChargeRule::query()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->whereNotNull('next_due_on')
            ->whereDate('next_due_on', '<=', $asOf->toDateString())
            ->count();
    }

    /** The account the desk defaults to for a charge — the standard chart's own. */
    public function defaultExpenseAccount(): ?Account
    {
        return Account::query()
            ->where('company_id', $this->companyId())
            ->where('code', self::DEFAULT_EXPENSE_CODE)
            ->where('is_active', true)
            ->first();
    }

    // -------------------------------------------------------------- internals

    /** Dr the charge, Cr the account the bank took it from. Two lines, no party. */
    protected function post(BankCharge $charge, Account $account, Account $expense, User $actor): JournalEntry
    {
        return $this->journals->post([
            'entry_date' => $charge->charged_on->toDateString(),
            'description' => "Bank charge {$charge->charge_no}: {$charge->narration}",
            'narration' => $charge->narration,
            'journal_type' => 'bank_charge',
            'source_type' => 'bank_charge',
            'source_id' => $charge->id,
            'source_event' => 'bank_charge_posted',
            'branch_id' => $charge->branch_id,
            'lines' => [
                ['account_id' => $expense->id, 'dc' => JournalLine::DEBIT, 'amount' => (string) $charge->amount],
                ['account_id' => $account->id, 'dc' => JournalLine::CREDIT, 'amount' => (string) $charge->amount],
            ],
        ], $actor);
    }

    protected function generateInsideTransaction(BankChargeRule $rule, User $actor, Carbon $dueOn): BankCharge
    {
        $quote = $this->quote($rule, $dueOn);

        if ((float) $quote['amount'] <= 0) {
            throw new RuntimeException($rule->basis === BankChargeRule::BASIS_PERCENT
                ? 'Nothing left this account since the last charge, so there is no commission to take.'
                : 'The rule charges nothing.');
        }

        $charge = BankCharge::query()->create([
            'company_id' => $rule->company_id,
            'branch_id' => $rule->branch_id,
            'rule_id' => $rule->id,
            'account_id' => $rule->account_id,
            'expense_account_id' => $rule->expense_account_id,
            'charge_no' => $this->nextChargeNo($rule->branch_id),
            'charged_on' => $dueOn->toDateString(),
            'amount' => $quote['amount'],
            'basis' => $quote['basis'],
            'turnover' => $quote['turnover'],
            'rate_percent' => $quote['rate'],
            'narration' => $rule->narration ?? $rule->name,
            'status' => BankCharge::STATUS_POSTED,
            'created_by' => $actor->id,
        ]);

        $entry = $this->post($charge, $rule->account()->firstOrFail(), $rule->expenseAccount()->firstOrFail(), $actor);

        $charge->forceFill(['journal_entry_id' => $entry->id])->save();

        $this->advance($rule, $dueOn, created: true);

        $this->audit->record([
            'action' => 'cash.bank_charge_generated',
            'entity_type' => 'bank_charge',
            'entity_id' => $charge->id,
            'branch_id' => $charge->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'charge_no' => $charge->charge_no,
                'rule' => $rule->name,
                'charged_on' => $dueOn->toDateString(),
                'amount' => (string) $charge->amount,
                'next_due_on' => $rule->next_due_on?->toDateString(),
            ],
        ]);

        return $charge->load(['account', 'expenseAccount', 'rule']);
    }

    /**
     * Move the rule to its next date. `last_charged_on` only ever moves for a
     * real charge, so the percentage basis cannot shrink by being looked at.
     */
    protected function advance(BankChargeRule $rule, Carbon $dueOn, bool $created): void
    {
        $next = $rule->nextDueAfter($dueOn);

        $attributes = ['next_due_on' => $next->toDateString()];

        if ($created) {
            $attributes['last_charged_on'] = $dueOn->toDateString();
            $attributes['charged_count'] = ((int) $rule->charged_count) + 1;
        }

        if ($rule->ends_on !== null && $next->gt($rule->ends_on)) {
            $attributes['is_active'] = false;
        }

        $rule->forceFill($attributes)->save();
    }

    /** The charge this rule already produced for a date, if any. */
    protected function chargeFor(BankChargeRule $rule, Carbon $dueOn): ?BankCharge
    {
        return BankCharge::query()
            ->where('rule_id', $rule->id)
            ->whereDate('charged_on', $dueOn->toDateString())
            ->first();
    }

    /**
     * The first date a rule falls on: the start date itself if it matches the
     * rhythm's day, otherwise the next occurrence of that day.
     */
    protected function firstDueDate(Carbon $from, ?int $dayOfMonth): Carbon
    {
        $from = $from->copy()->startOfDay();

        if ($dayOfMonth === null) {
            return $from;
        }

        if ((int) $from->day === $dayOfMonth) {
            return $from;
        }

        $thisMonth = $from->copy()->setDate(
            (int) $from->year,
            (int) $from->month,
            min($dayOfMonth, (int) $from->daysInMonth),
        );

        if ($thisMonth->gte($from)) {
            return $thisMonth;
        }

        $nextMonth = $from->copy()->addMonthNoOverflow();

        return $nextMonth->setDate(
            (int) $nextMonth->year,
            (int) $nextMonth->month,
            min($dayOfMonth, (int) $nextMonth->daysInMonth),
        );
    }

    protected function nextChargeNo(?int $branchId): string
    {
        $type = DocumentType::query()->where('code', 'bank_charge')->first();

        if ($type === null) {
            throw new RuntimeException('The bank charge document type is not seeded, so no charge can be numbered.');
        }

        return $this->numbering->allocate((int) $type->id, $branchId ?? $this->context->branchId());
    }

    /** A money account of this company — cash, bank or wallet, never a ledger leaf. */
    protected function moneyAccountOrFail(int $id, string $message): Account
    {
        $account = Account::query()->where('company_id', $this->companyId())->find($id);

        if ($account === null) {
            throw new RuntimeException($message);
        }

        $this->money->assertOwned($account);

        if (! $this->money->isMoney($account)) {
            throw new RuntimeException("A bank charge cannot come out of [{$account->name}] — that is not a cash, bank or wallet account.");
        }

        return $account;
    }

    /** Where the charge is booked: a postable expense leaf of this company. */
    protected function expenseAccountOrFail(int $id): Account
    {
        $account = Account::query()->where('company_id', $this->companyId())->find($id);

        if ($account === null) {
            throw new RuntimeException('Choose the account this charge is booked to — the bank charge expense, or the account your accountant keeps for it.');
        }

        if ($account->is_group) {
            throw new RuntimeException("[{$account->name}] is a group of accounts. A charge has to be booked to a single account, not to a heading.");
        }

        if ($account->type !== 'expense') {
            throw new RuntimeException("[{$account->name}] is not an expense account, and a bank charge is a cost of the account rather than anything else.");
        }

        if (! $account->is_active) {
            throw new RuntimeException("[{$account->name}] is switched off, so nothing can be booked to it any more.");
        }

        return $account;
    }

    protected function assertOwned(BankChargeRule|BankCharge $row): void
    {
        if ((int) $row->company_id !== (int) $this->companyId()) {
            throw new RuntimeException('That belongs to another company.');
        }
    }

    protected function companyId(): int
    {
        $companyId = $this->context->companyId();

        if ($companyId === null) {
            throw new RuntimeException('No company context.');
        }

        return (int) $companyId;
    }

    protected function nullIfBlank(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : $value;
    }
}
