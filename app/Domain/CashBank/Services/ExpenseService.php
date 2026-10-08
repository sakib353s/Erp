<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The expense desk (§08-15…§08-18).
 *
 * An expense answers two questions — what was it for, and where did the money
 * go — and this service refuses to guess either. The debit comes from the
 * category, because a category *is* a ledger account (§08-17) and a desk that
 * picks an account by itself is a desk whose reports are wrong in a way nobody
 * can see. The credit is either the account the money left, chosen by the person
 * who paid it, or payables, resolved through the posting rules the company has
 * configured — never a code that happens to be written here.
 *
 * The approval gate is a setting and, more importantly, a fact stored on the
 * document: `approval_threshold` records the number the expense was judged
 * against, so "why did this need a signature?" stays answerable after somebody
 * raises or drops the limit. A document waiting for approval carries no journal
 * entry at all — waiting must never look like posted — and the person who
 * recorded an expense cannot be the person who approves it, because a check
 * signed by its own author is not a check.
 *
 * Undoing a posted expense is a reversal, not an edit. The money left the
 * company; a screen that could quietly rewrite that would make the ledger's
 * history a fiction, so the original entry stays exactly where it is and its
 * reversal is filed next to it.
 */
class ExpenseService
{
    /** 0 (or less) means every expense posts immediately, as it always has. */
    public const SETTING_GROUP = 'cash';

    public const SETTING_KEY = 'expense_approval_above';

    /** The posting-rule event an unpaid expense resolves its payable side through. */
    public const PAYABLE_EVENT = 'expense_posted';

    public function __construct(
        protected JournalPostingService $journals,
        protected NumberingService $numbering,
        protected PostingRuleResolver $rules,
        protected MoneyAccountService $money,
        protected SettingService $settings,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    // --------------------------------------------------------- configuration

    /**
     * The amount at or above which an expense must be approved before it posts.
     * Read as a decimal string so money is never judged in floating point.
     */
    public function threshold(): string
    {
        $value = $this->settings->get(self::SETTING_GROUP, self::SETTING_KEY, '0');

        return is_numeric($value) ? (string) $value : '0';
    }

    /** Would an expense of this size have to wait for a signature? */
    public function mustApprove(float $amount): bool
    {
        $threshold = (float) $this->threshold();

        return $threshold > 0.0 && round($amount, 4) >= round($threshold, 4);
    }

    /** @return Collection<int, ExpenseCategory> */
    public function categories(bool $activeOnly = false): Collection
    {
        return ExpenseCategory::query()
            ->where('company_id', $this->companyId())
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->with('account')
            ->withCount('expenses')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create or update a category. The account it books to has to be a real,
     * postable expense account of this company: a category that points at a
     * group, a receivable or another company's account is a trap for whoever
     * records the next expense, so it is refused here rather than discovered
     * during an audit.
     *
     * @param  array{code:string, name:string, account_id:int, description?:string|null,
     *               is_active?:bool, sort_order?:int|null}  $data
     */
    public function saveCategory(array $data, ?ExpenseCategory $category = null, ?User $actor = null): ExpenseCategory
    {
        $companyId = $this->companyId();
        $account = $this->expenseAccount((int) $data['account_id']);

        $attributes = [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'account_id' => $account->id,
            'description' => $this->nullIfBlank($data['description'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        return DB::transaction(function () use ($category, $attributes, $companyId, $actor, $account) {
            if ($category === null) {
                $category = ExpenseCategory::query()->create($attributes + [
                    'company_id' => $companyId,
                    'created_by' => $actor?->id,
                ]);
            } else {
                $category->fill($attributes)->save();
            }

            $this->audit->record([
                'action' => 'cash.expense_category_saved',
                'entity_type' => 'expense_category',
                'entity_id' => $category->id,
                'branch_id' => $this->context->branchId(),
                'actor_id' => $actor?->id,
                'after' => [
                    'code' => $category->code,
                    'name' => $category->name,
                    'account' => $account->code.' — '.$account->name,
                    'is_active' => $category->is_active,
                ],
            ]);

            return $category->load('account');
        });
    }

    // ------------------------------------------------------------- recording

    /**
     * Record an expense. Under the approval threshold it posts now; at or above
     * it, it waits — and while it waits it touches nothing, so a queue of
     * unapproved expenses can never be mistaken for booked money.
     *
     * @param  array{category_id:int, expense_date:string, payee:string, amount:string|float,
     *               settled_with:string, money_account_id?:int|null, supplier_id?:int|null,
     *               narration?:string|null, branch_id?:int|null, currency?:string|null,
     *               recurring_expense_id?:int|null}  $data
     */
    public function create(array $data, User $actor): Expense
    {
        $amount = round((float) $data['amount'], 4);

        if ($amount <= 0) {
            throw new RuntimeException('An expense has to be more than nothing.');
        }

        $settledWith = (string) $data['settled_with'];

        if (! array_key_exists($settledWith, Expense::SETTLED_WITH)) {
            throw new RuntimeException('An expense is either paid from an account or owed to a supplier — pick one.');
        }

        $category = $this->categoryOrFail((int) $data['category_id']);

        if (! $category->isActive()) {
            throw new RuntimeException("The category [{$category->name}] is switched off. Recording against it would file the money where nobody is looking any more.");
        }

        $money = $settledWith === Expense::SETTLED_MONEY
            ? $this->moneyAccount((int) ($data['money_account_id'] ?? 0))
            : null;

        $gate = $this->mustApprove($amount);

        return DB::transaction(function () use ($data, $actor, $amount, $settledWith, $category, $money, $gate) {
            $expense = Expense::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $data['branch_id'] ?? $this->context->branchId(),
                'expense_no' => $this->allocateNumber($data['branch_id'] ?? null),
                'category_id' => $category->id,
                'expense_date' => $data['expense_date'],
                'payee' => trim((string) $data['payee']),
                'supplier_id' => $data['supplier_id'] ?? null,
                'narration' => $this->nullIfBlank($data['narration'] ?? null),
                'amount' => $amount,
                'currency' => strtoupper((string) ($data['currency'] ?? 'BDT')),
                'settled_with' => $settledWith,
                'money_account_id' => $money?->id,
                'status' => $gate ? Expense::STATUS_PENDING : Expense::STATUS_POSTED,
                'approval_gate' => $gate,
                'approval_threshold' => $gate ? $this->threshold() : null,
                'recurring_expense_id' => $data['recurring_expense_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record([
                'action' => $gate ? 'cash.expense_submitted' : 'cash.expense_recorded',
                'entity_type' => 'expense',
                'entity_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'expense_no' => $expense->expense_no,
                    'category' => $category->name,
                    'amount' => (string) $expense->amount,
                    'settled_with' => $settledWith,
                    'threshold' => $gate ? $this->threshold() : null,
                ],
            ]);

            return $gate ? $expense->load(['category.account', 'moneyAccount']) : $this->post($expense, $actor);
        });
    }

    /**
     * Approve a waiting expense: it posts now, and only now. Maker ≠ checker.
     */
    public function approve(Expense $expense, User $actor, ?string $note = null): Expense
    {
        $this->assertPending($expense, 'approve');
        $this->assertNotSelf($expense, $actor, 'approve');

        return DB::transaction(function () use ($expense, $actor, $note) {
            $expense->forceFill([
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $this->nullIfBlank($note),
            ])->save();

            $expense = $this->post($expense, $actor);

            $this->audit->record([
                'action' => 'cash.expense_approved',
                'entity_type' => 'expense',
                'entity_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'expense_no' => $expense->expense_no,
                    'entry_no' => $expense->journalEntry?->entry_no,
                    'note' => $expense->decision_note,
                ],
            ]);

            return $expense;
        });
    }

    /** Refuse a waiting expense. Nothing posted, and nothing ever will. */
    public function reject(Expense $expense, User $actor, ?string $note = null): Expense
    {
        $this->assertPending($expense, 'reject');
        $this->assertNotSelf($expense, $actor, 'reject');

        $expense->forceFill([
            'status' => Expense::STATUS_REJECTED,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => $this->nullIfBlank($note),
        ])->save();

        $this->audit->record([
            'action' => 'cash.expense_rejected',
            'entity_type' => 'expense',
            'entity_id' => $expense->id,
            'branch_id' => $expense->branch_id,
            'actor_id' => $actor->id,
            'after' => ['expense_no' => $expense->expense_no, 'note' => $expense->decision_note],
        ]);

        return $expense->load(['category.account', 'moneyAccount', 'decisionMaker']);
    }

    /**
     * Undo a posted expense. The original entry stays in the ledger and a
     * reversal answers it, dated today and naming the reason — because the money
     * really did move, and a history that can be edited is not a history.
     */
    public function reverse(Expense $expense, User $actor, string $reason): Expense
    {
        $reason = trim($reason);

        if (! $expense->isReversible()) {
            throw new RuntimeException($expense->isReversed()
                ? 'This expense has already been reversed. Reversing it twice would take the money out of the books twice.'
                : 'Only a posted expense can be reversed — this one is '.strtolower($expense->label()).'.');
        }

        if ($reason === '') {
            throw new RuntimeException('Say why the expense is being reversed. A reversal without a reason is an entry nobody can explain next year.');
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            $entry = $expense->journalEntry;

            if ($entry === null) {
                throw new RuntimeException("{$expense->expense_no} says it posted but carries no entry — the ledger and the document disagree, so nothing will be written.");
            }

            $reversal = $this->journals->reverse($entry, "Expense {$expense->expense_no} reversed: {$reason}", $actor);

            $expense->forceFill([
                'status' => Expense::STATUS_REVERSED,
                'reversal_entry_id' => $reversal->id,
                'decision_note' => $reason,
            ])->save();

            $this->audit->record([
                'action' => 'cash.expense_reversed',
                'entity_type' => 'expense',
                'entity_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'expense_no' => $expense->expense_no,
                    'reversed_entry' => $entry->entry_no,
                    'reversal_entry' => $reversal->entry_no,
                    'reason' => $reason,
                ],
            ]);

            return $expense->load(['category.account', 'moneyAccount', 'journalEntry', 'reversalEntry']);
        });
    }

    // --------------------------------------------------------------- the desk

    /**
     * The figures the desk opens with: what posted in the window, what is
     * waiting on a signature, and what was refused or given back.
     *
     * @return array{posted:string, posted_count:int, pending:string, pending_count:int,
     *               rejected_count:int, reversed:string, threshold:string, biggest:?Expense}
     */
    public function summary(?string $from = null, ?string $to = null, ?int $categoryId = null): array
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();

        $rows = Expense::query()
            ->where('company_id', $this->companyId())
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->selectRaw('status, COUNT(*) as rows, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $posted = $rows->get(Expense::STATUS_POSTED);
        $pending = $rows->get(Expense::STATUS_PENDING);
        $reversed = $rows->get(Expense::STATUS_REVERSED);
        $rejected = $rows->get(Expense::STATUS_REJECTED);

        $biggest = Expense::query()
            ->where('company_id', $this->companyId())
            ->where('status', Expense::STATUS_POSTED)
            ->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->orderByDesc('amount')
            ->with('category')
            ->first();

        return [
            'posted' => number_format((float) ($posted->total ?? 0), 2, '.', ''),
            'posted_count' => (int) ($posted->rows ?? 0),
            'pending' => number_format((float) ($pending->total ?? 0), 2, '.', ''),
            'pending_count' => (int) ($pending->rows ?? 0),
            'rejected_count' => (int) ($rejected->rows ?? 0),
            'reversed' => number_format((float) ($reversed->total ?? 0), 2, '.', ''),
            'threshold' => number_format((float) $this->threshold(), 2, '.', ''),
            'biggest' => $biggest,
        ];
    }

    /** What each category cost in the window — the screen's answer to "where does it all go?". */
    public function byCategory(?string $from = null, ?string $to = null): Collection
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();

        return Expense::query()
            ->where('expenses.company_id', $this->companyId())
            ->where('expenses.status', Expense::STATUS_POSTED)
            ->whereDate('expenses.expense_date', '>=', $from)->whereDate('expenses.expense_date', '<=', $to)
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->join('accounts', 'accounts.id', '=', 'expense_categories.account_id')
            ->groupBy('expense_categories.id', 'expense_categories.name', 'accounts.code', 'accounts.name')
            ->orderByDesc(DB::raw('SUM(expenses.amount)'))
            ->selectRaw('expense_categories.id as category_id, expense_categories.name as category, accounts.code as account_code, accounts.name as account_name, COUNT(*) as rows, SUM(expenses.amount) as total')
            ->limit(50)
            ->get();
    }

    /** @return Collection<int, Expense> */
    public function recent(array $filters = [], int $limit = 100): Collection
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        return Expense::query()
            ->where('company_id', $this->companyId())
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when(($filters['category'] ?? null) !== null, fn ($query) => $query->where('category_id', (int) $filters['category']))
            ->when(($filters['settled_with'] ?? null) !== null, fn ($query) => $query->where('settled_with', $filters['settled_with']))
            ->when($from !== null, fn ($query) => $query->whereDate('expense_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('expense_date', '<=', $to))
            ->when(($filters['q'] ?? null) !== null, function ($query) use ($filters) {
                $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';

                $query->where(fn ($inner) => $inner
                    ->where('expense_no', 'like', $needle)
                    ->orWhere('payee', 'like', $needle)
                    ->orWhere('narration', 'like', $needle));
            })
            ->with(['category.account', 'moneyAccount', 'decisionMaker'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Nothing unpaid ever leaves the desk unlisted: what is owed, to whom, and how old. */
    public function unpaid(int $limit = 50): Collection
    {
        return Expense::query()
            ->where('company_id', $this->companyId())
            ->where('settled_with', Expense::SETTLED_PAYABLE)
            ->whereIn('status', [Expense::STATUS_POSTED, Expense::STATUS_PENDING])
            ->with(['category.account', 'supplier'])
            ->orderBy('expense_date')
            ->limit($limit)
            ->get();
    }

    // ------------------------------------------------------------- internals

    /** The one place an expense reaches the ledger. */
    protected function post(Expense $expense, User $actor): Expense
    {
        $expense->loadMissing(['category.account', 'moneyAccount', 'supplier']);

        $debit = $expense->categoryAccount();

        if ($debit === null) {
            throw new RuntimeException("The category on {$expense->expense_no} has no ledger account, so there is nowhere to book it. Fix the category first.");
        }

        if ((bool) $debit->is_group) {
            throw new RuntimeException("The account behind [{$expense->category?->name}] is a group account, and a group cannot be posted to. Point the category at the ledger account underneath it.");
        }

        $amount = round((float) $expense->amount, 4);

        $legs = [[
            'account_id' => $debit->id,
            'dc' => 'debit',
            'amount' => $amount,
            'narration' => $expense->expense_no.($expense->payee ? ' · '.$expense->payee : ''),
        ]];

        if ($expense->settled_with === Expense::SETTLED_MONEY) {
            $credit = $expense->moneyAccount;

            if ($credit === null) {
                throw new RuntimeException("{$expense->expense_no} says it was paid from an account but names none, so the credit side cannot be written.");
            }

            $legs[] = ['account_id' => $credit->id, 'dc' => 'credit', 'amount' => $amount];
        } else {
            // Never a hardcoded payables code: the company's own posting rules
            // say which account owes the money.
            $payable = $this->rules->accountFor(self::PAYABLE_EVENT, 'ap');

            $legs[] = [
                'account_id' => $payable->id,
                'dc' => 'credit',
                'amount' => $amount,
                'party_type' => $expense->supplier_id !== null ? 'supplier' : null,
                'party_id' => $expense->supplier_id,
                'narration' => 'Owed on '.$expense->expense_no,
            ];
        }

        $entry = $this->journals->post([
            'entry_date' => $expense->expense_date instanceof \DateTimeInterface
                ? $expense->expense_date->format('Y-m-d')
                : (string) $expense->expense_date,
            'description' => "Expense {$expense->expense_no} — {$expense->category?->name}".($expense->payee !== '' ? " · {$expense->payee}" : ''),
            'narration' => $expense->narration,
            'journal_type' => 'expense',
            'source_type' => 'expense',
            'source_id' => $expense->id,
            'source_event' => $expense->settled_with === Expense::SETTLED_MONEY ? 'expense_paid' : 'expense_payable',
            'branch_id' => $expense->branch_id,
            'lines' => $legs,
        ], $actor);

        $expense->forceFill([
            'status' => Expense::STATUS_POSTED,
            'journal_entry_id' => $entry->id,
        ])->save();

        return $expense->load(['category.account', 'moneyAccount', 'journalEntry.lines.account', 'decisionMaker']);
    }

    protected function allocateNumber(?int $branchId): string
    {
        $type = DocumentType::query()->where('code', 'expense')->first();

        if ($type === null) {
            throw new RuntimeException('The expense document type is not seeded, so no expense can be numbered.');
        }

        return $this->numbering->allocate((int) $type->id, $branchId ?? $this->context->branchId());
    }

    protected function categoryOrFail(int $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()
            ->where('company_id', $this->companyId())
            ->find($id);

        if ($category === null) {
            throw new RuntimeException('Pick an expense category. The category is what tells the ledger which account the money came out of.');
        }

        return $category;
    }

    /** The account a category may book to: an expense account of this company, postable. */
    protected function expenseAccount(int $id): Account
    {
        $account = Account::query()
            ->where('company_id', $this->companyId())
            ->find($id);

        if ($account === null) {
            throw new RuntimeException('That account does not belong to this company.');
        }

        if (trim((string) $account->type) !== 'expense') {
            throw new RuntimeException("An expense category has to book to an expense account — {$account->code} is a {$account->type} account, so money filed under it would land in the wrong part of the statement.");
        }

        if ((bool) $account->is_group) {
            throw new RuntimeException("{$account->code} — {$account->name} is a group account. Pick the ledger account underneath it, so the expense is posted somewhere real.");
        }

        if (! (bool) $account->is_active) {
            throw new RuntimeException("{$account->code} — {$account->name} is switched off, so nothing can be posted to it any more.");
        }

        return $account;
    }

    /** The account the money actually left from, or the reason it cannot. */
    protected function moneyAccount(int $id): Account
    {
        $account = Account::query()
            ->where('company_id', $this->companyId())
            ->find($id);

        if ($account === null || ! $this->money->isMoney($account)) {
            throw new RuntimeException('A paid expense is paid from cash, a bank account or a wallet — pick one of the money accounts.');
        }

        return $account;
    }

    protected function assertPending(Expense $expense, string $verb): void
    {
        if (! $expense->isPending()) {
            throw new RuntimeException("Only an expense waiting for approval can be ".$verb.'ed — this one is '.strtolower($expense->label()).'.');
        }
    }

    protected function assertNotSelf(Expense $expense, User $actor, string $verb): void
    {
        if ((int) $expense->created_by === (int) $actor->id) {
            throw new RuntimeException("You recorded this expense, so you cannot {$verb} it. An approval given to oneself is not an approval — ask somebody else to look at it.");
        }
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
