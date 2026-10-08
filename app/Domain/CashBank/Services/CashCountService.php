<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\CashCount;
use App\Domain\CashBank\CashCountLine;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §08-05 — the drawer, counted by a person and answered for by the books.
 *
 * Every other desk in this module takes the ledger's word for it. This one asks
 * what is actually in the tin, and then does the only two things that can
 * honestly be done with the answer:
 *
 *  · **The gap is corrected, not explained away.** A shortage debits Cash Over &
 *    Short and credits the drawer; an overage debits the drawer and credits
 *    Other Income — the same two accounts the COD remittance desk already uses,
 *    so the two cannot disagree about what a short drawer means. An exact count
 *    posts nothing, because there is nothing to correct.
 *  · **Above the tolerance the correction waits for somebody else.** Money that
 *    is missing must not be written off by the person who was holding it, so a
 *    variance at or above the company's tolerance is recorded, left unposted,
 *    and the counter cannot be the one who approves it.
 *
 * The expected figure is the ledger's own balance for the account on the day of
 * the count, and it is frozen onto the row — a count reads the same next year as
 * it did then. A count that does not match the books has to say why, on the
 * record, where the next person to count that drawer will find it.
 */
class CashCountService
{
    /** 0 (or less) means the counter posts the difference themselves. */
    public const SETTING_GROUP = 'cash';

    public const SETTING_KEY = 'cash_count_tolerance';

    /** The posting event a variance resolves its contra account through. */
    public const EVENT = 'cash_count_variance';

    public const SHORT_ROLE = 'cash_short';

    public const OVER_ROLE = 'cash_over';

    public function __construct(
        protected MoneyAccountService $accounts,
        protected JournalPostingService $journals,
        protected PostingRuleResolver $rules,
        protected SettingService $settings,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    // --------------------------------------------------------- configuration

    /**
     * The variance at or above which counted money waits for a signature. Read as
     * a decimal string so money is never judged in floating point.
     */
    public function tolerance(): string
    {
        $value = $this->settings->get(self::SETTING_GROUP, self::SETTING_KEY, '0');

        return is_numeric($value) ? (string) $value : '0';
    }

    /** Does a gap of this size need somebody other than the counter? */
    public function mustApprove(float $variance): bool
    {
        $tolerance = (float) $this->tolerance();

        return $tolerance > 0.0 && round(abs($variance), 4) >= round($tolerance, 4);
    }

    // --------------------------------------------------------------- drawers

    /**
     * The drawers a person can count: this company's cash accounts, each with the
     * ledger's figure for it, the last time it was counted and whether a count is
     * still waiting on somebody.
     *
     * @return Collection<int, array{account: Account, balance: string, last: ?CashCount, pending: ?CashCount}>
     */
    public function drawers(): Collection
    {
        $accounts = $this->accounts->accounts()
            ->where('is_active', true)
            ->where('is_cash', true)
            ->values();

        if ($accounts->isEmpty()) {
            return collect();
        }

        $last = CashCount::query()
            ->where('company_id', $this->companyId())
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->orderByDesc('counted_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy('account_id');

        return $accounts->map(function (Account $account) use ($last) {
            $rows = $last->get($account->id, collect());

            return [
                'account' => $account,
                'balance' => $this->accounts->balanceOf($account),
                'last' => $rows->first(),
                'pending' => $rows->firstWhere('status', CashCount::STATUS_PENDING),
            ];
        });
    }

    /** @param array{account?:int|null, status?:string|null, from?:string|null, to?:string|null} $filters */
    public function counts(array $filters = [], int $limit = 60): Collection
    {
        return CashCount::query()
            ->where('company_id', $this->companyId())
            ->when(($filters['account'] ?? null) !== null, fn ($query) => $query->where('account_id', $filters['account']))
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['from'] ?? null) !== null, fn ($query) => $query->whereDate('counted_on', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($query) => $query->whereDate('counted_on', '<=', $filters['to']))
            ->with(['account', 'branch', 'counter', 'decider', 'journalEntry'])
            ->orderByDesc('counted_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    // ---------------------------------------------------------------- the act

    /**
     * Count a drawer. The expected figure is read from the ledger, the gap is
     * worked out, and the correction is posted — unless the gap is big enough
     * that somebody else has to answer for it first.
     *
     * @param  array{counted_on:string, counted_amount:string|float, branch_id?:int|null,
     *               difference_reason?:string|null, notes?:string|null,
     *               denominations?:array<int, array{kind?:string, face_value:string|float, quantity:int|string}>}  $data
     */
    public function countCash(Account $account, array $data, User $actor): CashCount
    {
        $this->accounts->assertOwned($account);

        if (! $this->accounts->isMoney($account) || $this->accounts->instrumentOf($account) !== 'cash') {
            throw new RuntimeException("Only a cash drawer can be counted: [{$account->name}] is not a cash account, so what it holds is a statement's claim rather than a pile of notes.");
        }

        $companyId = $this->companyId();
        $countedOn = (string) ($data['counted_on'] ?? now()->toDateString());
        $branchId = $this->branchOrFail($data['branch_id'] ?? null);

        if (CashCount::query()
            ->where('company_id', $companyId)
            ->where('account_id', $account->id)
            ->where('status', CashCount::STATUS_PENDING)
            ->exists()) {
            throw new RuntimeException("A count of [{$account->name}] is already waiting for approval. Decide that one first — counting the same drawer twice would ask somebody to approve the same difference twice.");
        }

        $counted = $this->amount($data['counted_amount'] ?? 0);
        $expected = $this->accounts->balanceOf($account, $branchId, $countedOn);
        $variance = round($counted - (float) $expected, 4);
        $reason = $this->nullIfBlank($data['difference_reason'] ?? null);

        if (abs($variance) > 0.0001 && $reason === null) {
            throw new RuntimeException('A count that does not match the books has to say why — the reason is read by whoever counts this drawer next.');
        }

        $lines = $this->linesFrom($data['denominations'] ?? [], $counted, $companyId);

        return DB::transaction(function () use (
            $companyId, $account, $countedOn, $branchId, $counted, $expected, $variance, $reason, $data, $lines, $actor
        ) {
            $count = CashCount::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'account_id' => $account->id,
                'counted_on' => $countedOn,
                'expected_amount' => number_format((float) $expected, 4, '.', ''),
                'counted_amount' => number_format($counted, 4, '.', ''),
                'variance' => number_format($variance, 4, '.', ''),
                'tolerance' => $this->tolerance(),
                'status' => $this->mustApprove($variance) ? CashCount::STATUS_PENDING : CashCount::STATUS_POSTED,
                'difference_reason' => $reason,
                'notes' => $this->nullIfBlank($data['notes'] ?? null),
                'counted_by' => $actor->id,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $position => $line) {
                $count->lines()->create($line + ['position' => $position]);
            }

            if ($count->isPending()) {
                $this->audit->record([
                    'action' => 'cash_bank.cash_count_pending',
                    'entity_type' => 'cash_count',
                    'entity_id' => $count->id,
                    'branch_id' => $count->branch_id,
                    'actor_id' => $actor->id,
                    'after' => [
                        'account' => $account->name,
                        'expected' => (string) $expected,
                        'counted' => number_format($counted, 4, '.', ''),
                        'variance' => number_format($variance, 4, '.', ''),
                        'tolerance' => $this->tolerance(),
                        'reason' => $reason,
                    ],
                ]);

                return $count->load(['account', 'branch', 'counter', 'lines']);
            }

            $this->post($count, $actor);

            $this->audit->record([
                'action' => 'cash_bank.cash_count_posted',
                'entity_type' => 'cash_count',
                'entity_id' => $count->id,
                'branch_id' => $count->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'account' => $account->name,
                    'expected' => (string) $expected,
                    'counted' => number_format($counted, 4, '.', ''),
                    'variance' => number_format($variance, 4, '.', ''),
                    'journal_entry_id' => $count->journal_entry_id,
                    'reason' => $reason,
                ],
            ]);

            return $count->load(['account', 'branch', 'counter', 'lines', 'journalEntry']);
        });
    }

    /**
     * Approve a counted variance: now, and only now, the gap reaches the ledger.
     * The counter is never the approver — that rule is the whole point of the
     * count waiting in the first place.
     */
    public function approve(CashCount $count, User $actor, ?string $note = null): CashCount
    {
        $this->assertPending($count);
        $this->assertNotSelf($count, $actor);

        return DB::transaction(function () use ($count, $actor, $note) {
            $this->post($count, $actor);

            $count->forceFill([
                'status' => CashCount::STATUS_POSTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $this->nullIfBlank($note),
            ])->save();

            $this->audit->record([
                'action' => 'cash_bank.cash_count_approved',
                'entity_type' => 'cash_count',
                'entity_id' => $count->id,
                'branch_id' => $count->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'counted_by' => $count->counter?->name,
                    'variance' => (string) $count->variance,
                    'journal_entry_id' => $count->journal_entry_id,
                ],
            ]);

            return $count->refresh()->load(['account', 'branch', 'counter', 'decider', 'lines', 'journalEntry']);
        });
    }

    /**
     * Refuse a count — the drawer stays as the books say it is, and the refusal
     * says why. Nothing posts, and the money stays visibly unexplained rather
     * than being written off by a signature nobody wanted to give.
     */
    public function reject(CashCount $count, User $actor, ?string $note = null): CashCount
    {
        $this->assertPending($count);
        $this->assertNotSelf($count, $actor);

        $note = $this->nullIfBlank($note);

        if ($note === null) {
            throw new RuntimeException('Refusing a counted difference has to say why — the next count of this drawer will be read against that answer.');
        }

        $count->forceFill([
            'status' => CashCount::STATUS_REJECTED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record([
            'action' => 'cash_bank.cash_count_rejected',
            'entity_type' => 'cash_count',
            'entity_id' => $count->id,
            'branch_id' => $count->branch_id,
            'actor_id' => $actor->id,
            'reason' => $note,
            'after' => ['variance' => (string) $count->variance, 'counted_by' => $count->counter?->name],
        ]);

        return $count->refresh()->load(['account', 'branch', 'counter', 'decider', 'lines']);
    }

    // --------------------------------------------------------------- figures

    /**
     * What the desk opens with. Every figure is either the ledger's own or a sum
     * over the counts themselves.
     *
     * @return array{drawers:int, uncounted:int, pending:int, pending_value:string,
     *               counted_month:int, short_month:string, over_month:string,
     *               last_on:?string, tolerance:string}
     */
    public function summary(): array
    {
        $companyId = $this->companyId();
        $month = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];

        $drawers = $this->drawers();

        $pending = CashCount::query()
            ->where('company_id', $companyId)
            ->where('status', CashCount::STATUS_PENDING);

        $posted = CashCount::query()
            ->where('company_id', $companyId)
            ->where('status', CashCount::STATUS_POSTED)
            ->whereBetween('counted_on', $month);

        $short = (clone $posted)->where('variance', '<', 0);
        $over = (clone $posted)->where('variance', '>', 0);

        return [
            'drawers' => $drawers->count(),
            'uncounted' => $drawers->whereNull('last')->count(),
            'pending' => (clone $pending)->count(),
            'pending_value' => number_format((float) (clone $pending)->sum('variance'), 2, '.', ''),
            'counted_month' => (clone $posted)->count(),
            'short_month' => number_format(abs((float) (clone $short)->sum('variance')), 2, '.', ''),
            'over_month' => number_format((float) (clone $over)->sum('variance'), 2, '.', ''),
            'last_on' => CashCount::query()
                ->where('company_id', $companyId)
                ->max('counted_on'),
            'tolerance' => $this->tolerance(),
        ];
    }

    // ------------------------------------------------------------- internals

    /**
     * The one place a counted difference reaches the ledger. A shortage debits
     * cash over & short; an overage credits other income — the accounts the
     * company's own posting rules name, so nothing here hardcodes a code.
     */
    protected function post(CashCount $count, ?User $actor): CashCount
    {
        $variance = (float) $count->variance;

        if (abs($variance) <= 0.0001) {
            return $count; // an exact count has nothing to correct
        }

        $cash = $count->account;

        if ($cash === null) {
            throw new RuntimeException('This count has lost the drawer it was made against.');
        }

        $amount = number_format(abs($variance), 4, '.', '');

        if ($variance < 0) {
            $contra = $this->rules->accountFor(self::EVENT, self::SHORT_ROLE);

            $lines = [
                ['account_id' => $contra->id, 'dc' => 'debit', 'amount' => $amount],
                ['account_id' => $cash->id, 'dc' => 'credit', 'amount' => $amount],
            ];
        } else {
            $contra = $this->rules->accountFor(self::EVENT, self::OVER_ROLE);

            $lines = [
                ['account_id' => $cash->id, 'dc' => 'debit', 'amount' => $amount],
                ['account_id' => $contra->id, 'dc' => 'credit', 'amount' => $amount],
            ];
        }

        $entry = $this->journals->post([
            'entry_date' => $count->counted_on->toDateString(),
            'description' => ($variance < 0 ? 'Cash shortage' : 'Cash overage')." counted in {$cash->name}",
            'narration' => $count->difference_reason,
            'journal_type' => 'cash_count',
            'source_type' => 'cash_count',
            'source_id' => $count->id,
            'source_event' => self::EVENT,
            'branch_id' => $count->branch_id,
            'lines' => $lines,
        ], $actor);

        $count->forceFill(['journal_entry_id' => $entry->id])->save();

        return $count;
    }

    /**
     * The denominations, checked against the total the counter typed. A breakdown
     * that does not add up is refused rather than silently corrected — the whole
     * point of writing the notes down is that somebody can add them up again.
     *
     * @param  array<int, array{kind?:string, face_value:string|float, quantity:int|string}>  $denominations
     * @return array<int, array<string, mixed>>
     */
    protected function linesFrom(array $denominations, float $counted, int $companyId): array
    {
        $lines = [];

        foreach ($denominations as $row) {
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $face = round((float) ($row['face_value'] ?? 0), 4);

            if ($face <= 0) {
                continue;
            }

            $kind = in_array(($row['kind'] ?? null), [CashCount::KIND_NOTE, CashCount::KIND_COIN], true)
                ? $row['kind']
                : CashCount::KIND_NOTE;

            $lines[] = [
                'company_id' => $companyId,
                'kind' => $kind,
                'face_value' => number_format($face, 4, '.', ''),
                'quantity' => $quantity,
                'amount' => number_format($face * $quantity, 4, '.', ''),
            ];
        }

        if ($lines === []) {
            return [];
        }

        $sum = round(array_sum(array_map(fn ($line) => (float) $line['amount'], $lines)), 4);

        if (abs($sum - $counted) > 0.0001) {
            throw new RuntimeException(sprintf(
                'The denominations add up to %s, which is not the %s you said was in the tin. Correct one of the two — a count that cannot be added up again is only a claim.',
                number_format($sum, 2),
                number_format($counted, 2),
            ));
        }

        return $lines;
    }

    protected function branchOrFail(?int $branchId): ?int
    {
        if ($branchId === null) {
            return $this->context->branchId();
        }

        $branch = Branch::query()
            ->where('company_id', $this->companyId())
            ->find($branchId);

        if ($branch === null) {
            throw new RuntimeException('That branch is not this company\'s.');
        }

        return $branch->id;
    }

    protected function assertPending(CashCount $count): void
    {
        if (! $count->isPending()) {
            throw new RuntimeException("This count was already {$count->label()}. A decided count is history — count the drawer again if the tin is still open.");
        }
    }

    protected function assertNotSelf(CashCount $count, User $actor): void
    {
        if ((int) $count->counted_by === (int) $actor->id) {
            throw new RuntimeException('The person who counted the drawer cannot approve its difference. Ask somebody else to decide it.');
        }
    }

    /** A drawer can be empty, so only a negative count is nonsense. */
    protected function amount(mixed $value): float
    {
        $amount = round((float) $value, 4);

        if ($amount < 0) {
            throw new RuntimeException('A drawer cannot hold less than nothing — count what is in it.');
        }

        return $amount;
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
