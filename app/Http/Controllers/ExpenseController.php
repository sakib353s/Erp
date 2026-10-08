<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\Expense;
use App\Domain\CashBank\ExpenseCategory;
use App\Domain\CashBank\Services\ExpenseService;
use App\Domain\CashBank\Services\MoneyAccountService;
use App\Domain\CashBank\Support\AmountInWords;
use App\Domain\Documents\Services\FileUploadService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Purchase\Supplier;
use App\Http\Requests\ExpenseDecisionRequest;
use App\Http\Requests\StoreExpenseCategoryRequest;
use App\Http\Requests\StoreExpenseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The expense desk (§08-15…§08-18).
 *
 * One register with filters, one form, one decision panel and one configuration
 * screen — because "All Expenses" and "Pending Approval" are the same list asked
 * two questions, not two applications. Every figure on these pages comes from
 * the ledger or from the expenses themselves; nothing is estimated here.
 */
class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseService $expenses,
        protected MoneyAccountService $money,
        protected FileUploadService $uploads,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /** The register: what posted, what is waiting, and where it all went. */
    public function index(Request $request): View
    {
        $filters = [
            'status' => $this->statusFilter($request->query('status')),
            'category' => $request->query('category') !== null ? (int) $request->query('category') : null,
            'settled_with' => $this->settledFilter($request->query('settled_with')),
            'from' => $this->dateOrNull($request->query('from')),
            'to' => $this->dateOrNull($request->query('to')),
            'q' => $this->stringOrNull($request->query('q')),
        ];

        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return view('cash-bank.expenses', [
            'filters' => $filters,
            'from' => $from,
            'to' => $to,
            'summary' => $this->expenses->summary($from, $to, $filters['category']),
            'byCategory' => $this->expenses->byCategory($from, $to),
            'expenses' => $this->expenses->recent($filters, 200),
            'categories' => $this->expenses->categories(),
            'unpaid' => $this->expenses->unpaid(),
            'statuses' => Expense::STATUSES,
            'settledWith' => Expense::SETTLED_WITH,
        ]);
    }

    /** The form that records one expense, with the gate it will meet written on it. */
    public function create(Request $request): View
    {
        return view('cash-bank.expense-create', [
            'categories' => $this->expenses->categories(true),
            'moneyAccounts' => $this->money->accounts()->where('is_active', true)->values(),
            'suppliers' => Supplier::query()
                ->where('company_id', $request->user()->company_id)
                ->orderBy('name')
                ->limit(300)
                ->get(['id', 'name', 'code']),
            'threshold' => $this->expenses->threshold(),
            'settledWith' => Expense::SETTLED_WITH,
            'preset' => $this->stringOrNull($request->query('category')),
        ]);
    }

    /** Record it. Under the threshold it posts; at or above it, it waits. */
    public function store(StoreExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $expense = $this->expenses->create($data, $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['expense' => $error->getMessage()])->withInput();
        }

        if ($request->hasFile('receipt')) {
            $document = $this->uploads->store($request->file('receipt'), $request->user(), [
                'document_type' => 'expense_voucher',
                'owner_type' => Expense::class,
                'owner_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'purpose' => 'expense_receipt',
            ]);

            $expense->forceFill(['receipt_document_id' => $document->id])->save();

            $this->audit->record([
                'action' => 'cash.expense_receipt_attached',
                'entity_type' => 'expense',
                'entity_id' => $expense->id,
                'branch_id' => $expense->branch_id,
                'actor_id' => $request->user()->id,
                'after' => ['document_id' => $document->id, 'original_name' => $document->original_name],
            ]);
        }

        return redirect()
            ->route('cash-bank.expenses.show', ['expense' => $expense->id])
            ->with('status', $expense->isPending()
                ? $expense->expense_no.' is waiting for approval: it is worth at least '.number_format((float) $this->expenses->threshold(), 2).', and nothing reaches the ledger until somebody signs it off.'
                : $expense->expense_no.' posted: '.number_format((float) $expense->amount, 2).' out of the books, exactly once.');
    }

    /** One expense: what it was, what it posted, and what may still happen to it. */
    public function show(Request $request, Expense $expense): View
    {
        $this->assertOwned($request, $expense);

        $expense->load([
            'category.account', 'moneyAccount', 'supplier', 'receipt',
            'decisionMaker', 'creator', 'journalEntry.lines.account', 'reversalEntry',
        ]);

        return view('cash-bank.expense', [
            'expense' => $expense,
            'wordsEn' => AmountInWords::en((string) $expense->amount),
            'wordsBn' => AmountInWords::bn((string) $expense->amount),
            'figuresBn' => AmountInWords::bnFigures((string) $expense->amount),
            'mayDecide' => (bool) $request->user()->can('expenses.approve'),
            'isOwnRecord' => (int) $expense->created_by === (int) $request->user()->id,
        ]);
    }

    /** Approve it, refuse it, or give the money back. */
    public function decide(ExpenseDecisionRequest $request, Expense $expense): RedirectResponse
    {
        $this->assertOwned($request, $expense);

        $data = $request->validated();
        $note = $this->stringOrNull($data['note'] ?? null);

        try {
            match ($data['action']) {
                'approve' => $this->expenses->approve($expense, $request->user(), $note),
                'reject' => $this->expenses->reject($expense, $request->user(), $note),
                'reverse' => $this->expenses->reverse($expense, $request->user(), (string) $note),
            };
        } catch (RuntimeException $error) {
            return back()->withErrors(['expense' => $error->getMessage()]);
        }

        return redirect()
            ->route('cash-bank.expenses.show', ['expense' => $expense->id])
            ->with('status', $this->narrate($expense, $data['action']));
    }

    /** §08-17 — what each category books to, and what it has cost so far. */
    public function categories(Request $request): View
    {
        return view('cash-bank.expense-categories', [
            'categories' => $this->expenses->categories(),
            'expenseAccounts' => Account::query()
                ->where('company_id', $request->user()->company_id)
                ->where('type', 'expense')
                ->where('is_group', false)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function storeCategory(StoreExpenseCategoryRequest $request): RedirectResponse
    {
        try {
            $category = $this->expenses->saveCategory($request->validated(), null, $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['category' => $error->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.expense-categories')
            ->with('status', $category->name.' books to '.$category->accountLabel().' now.');
    }

    public function updateCategory(StoreExpenseCategoryRequest $request, ExpenseCategory $category): RedirectResponse
    {
        abort_unless((int) $category->company_id === (int) $request->user()->company_id, 404);

        try {
            $category = $this->expenses->saveCategory($request->validated(), $category, $request->user());
        } catch (RuntimeException $error) {
            return back()->withErrors(['category' => $error->getMessage()])->withInput();
        }

        return redirect()
            ->route('cash-bank.expense-categories')
            ->with('status', $category->name.' updated — expenses recorded from now on book to '.$category->accountLabel().'. Anything already posted stays where it was posted.');
    }

    // ------------------------------------------------------------- internals

    protected function narrate(Expense $expense, string $action): string
    {
        return match ($action) {
            'approve' => $expense->expense_no.' approved and posted — entry '.($expense->journalEntry?->entry_no ?? '').'. It reached the ledger once, now that somebody with the authority said yes.',
            'reject' => $expense->expense_no.' refused. Nothing posted, and nothing ever will.',
            'reverse' => $expense->expense_no.' reversed: entry '.($expense->journalEntry?->entry_no ?? '').' stands, and '.($expense->reversalEntry?->entry_no ?? 'its reversal').' answers it. The history of both is kept.',
        };
    }

    protected function assertOwned(Request $request, Expense $expense): void
    {
        abort_unless((int) $expense->company_id === (int) $request->user()->company_id, 404);
    }

    /** A filter that is not one of the states is not a filter — it is a typo. */
    protected function statusFilter(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value !== null && array_key_exists($value, Expense::STATUSES) ? $value : null;
    }

    protected function settledFilter(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value !== null && array_key_exists($value, Expense::SETTLED_WITH) ? $value : null;
    }

    protected function dateOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);

        return $value !== null && strtotime($value) !== false ? date('Y-m-d', strtotime($value)) : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
