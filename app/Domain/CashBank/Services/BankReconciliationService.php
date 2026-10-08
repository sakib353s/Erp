<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\BankReconciliation;
use App\Domain\CashBank\BankStatementLine;
use App\Domain\CashBank\ReconciliationLine;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Sales\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Proving the bank book against the bank's own statement (§08-08, §08-12).
 *
 * A reconciliation is an argument, and this service is that argument written
 * down. Both sides start as lines — ours from the posted journal lines on the
 * account, the bank's from the statement file — and a line is either matched
 * with its counterpart or left over. The proof is arithmetic:
 *
 *     expected statement closing = book balance
 *                                + statement lines the books have not got   (money the bank moved)
 *                                − book lines the bank has not processed    (money we moved)
 *
 * When the bank's closing balance equals that, everything is explained; what
 * remains is `difference`, and it is zero only when the leftover lines on both
 * sides account for the whole gap. That number, not a person's confidence, is
 * what decides whether the reconciliation is proved and may be signed off.
 *
 * Two sign conventions meet here and are translated once, out loud: a statement
 * `credit` puts money in *our* account (positive), and a ledger `debit` does the
 * same. Every amount compared in this class is signed the books' way.
 */
class BankReconciliationService
{
    /**
     * How far apart a statement line and a book line may be and still be the
     * same movement. Cheques are the whole reason a reconciliation exists: the
     * money leaves the books on the day it is written and the bank on the day it
     * clears.
     */
    public const DATE_WINDOW_DAYS = 7;

    /** A reference match is stronger evidence than a date-and-amount match, so it is allowed a longer gap. */
    public const REFERENCE_WINDOW_DAYS = 14;

    /**
     * No provider adapter exists in this application, and the screen says so
     * rather than offering a button that would never work: a wallet is
     * reconciled from a statement downloaded from the provider's app.
     */
    public const WALLET_API_CONNECTED = false;

    public function __construct(
        protected MoneyAccountService $accounts,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /**
     * The book side: every posted line on the account in the period, signed the
     * way an asset moves (debit = in). Carries the document number the operator
     * would recognise — the money receipt or voucher number, not the journal
     * voucher that carries the posting.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bookLines(Account $account, string $from, string $to, ?int $branchId = null): Collection
    {
        $lines = JournalLine::query()
            ->where('journal_lines.company_id', $account->company_id)
            ->where('journal_lines.account_id', $account->id)
            ->whereHas('entry', function ($query) use ($from, $to, $branchId) {
                $query->where('posting_state', JournalEntry::STATE_POSTED)
                    ->whereDate('entry_date', '>=', $from)
                    ->whereDate('entry_date', '<=', $to)
                    ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
            })
            ->with('entry')
            ->orderBy('entry_date')
            ->orderBy('journal_lines.id')
            ->get();

        // The document numbers behind these postings, so a line can be matched by
        // the reference the operator wrote on the deposit slip.
        $documents = Payment::query()
            ->where('company_id', $account->company_id)
            ->whereIn('journal_entry_id', $lines->pluck('journal_entry_id')->unique()->all())
            ->get(['id', 'journal_entry_id', 'receipt_no', 'reference'])
            ->keyBy('journal_entry_id');

        return $lines->map(function (JournalLine $line) use ($documents) {
            $document = $documents->get($line->journal_entry_id);
            $isDebit = $line->isDebit();

            return [
                'journal_line_id' => $line->id,
                'journal_entry_id' => $line->journal_entry_id,
                'movement_date' => $line->entry?->entry_date?->toDateString() ?? '',
                'reference' => $document?->receipt_no ?: ($line->entry?->entry_no ?? ''),
                'entry_no' => $line->entry?->entry_no,
                'document_reference' => $document?->reference,
                'description' => $line->narration ?: ($line->entry?->description ?? ''),
                'amount' => ($isDebit ? '' : '-').number_format((float) $line->amount, 4, '.', ''),
                'dc' => $line->dc,
            ];
        });
    }

    /**
     * Open a reconciliation: snapshot both sides, match what matches, and write
     * down what is left over on each side.
     *
     * @param  array{period_start:string, period_end:string, statement_closing:string|float,
     *               statement_opening?:string|float|null, branch_id?:int|null, notes?:string|null}  $data
     */
    public function open(Account $account, User $actor, array $data): BankReconciliation
    {
        $this->assertMoneyAccount($account);

        $from = Carbon::parse($data['period_start'])->toDateString();
        $to = Carbon::parse($data['period_end'])->toDateString();

        if (Carbon::parse($from)->greaterThan(Carbon::parse($to))) {
            throw new RuntimeException('The period ends before it starts — a reconciliation cannot run backwards.');
        }

        $branchId = $data['branch_id'] ?? null;
        $statementClosing = number_format((float) $data['statement_closing'], 4, '.', '');
        $bookBalance = $this->accounts->balanceOf($account, $branchId, $to);
        $bookOpening = $this->accounts->balanceOf(
            $account,
            $branchId,
            Carbon::parse($from)->subDay()->toDateString(),
        );

        $book = $this->bookLines($account, $from, $to, $branchId);
        $statement = $this->statementLines($account, $from, $to);

        if ($book->isEmpty() && $statement->isEmpty()) {
            throw new RuntimeException(
                'There is nothing to reconcile: no posted movement on '.$account->name.' in this period and no statement line to compare it with.',
            );
        }

        [$pairs, $unmatchedBook, $unmatchedStatement] = $this->match($book, $statement);

        return $this->persist(
            $account,
            $actor,
            [
                'branch_id' => $branchId,
                'period_start' => $from,
                'period_end' => $to,
                'notes' => $data['notes'] ?? null,
            ],
            $bookBalance,
            $bookOpening,
            $statementClosing,
            $data['statement_opening'] ?? null,
            $statement,
            $pairs,
            $unmatchedBook,
            $unmatchedStatement,
        );
    }

    /**
     * Correct the closing figure the bank states, while the period is still open.
     *
     * The most common way a reconciliation fails to add up is not a missing entry
     * at all: it is a clerk reading the closing balance off the wrong row. Without
     * this, that mistake would leave a draft that can never be signed off and
     * never be corrected, which is a desk that cannot finish its own job. Only the
     * bank's *stated* figure moves here — the frozen book and statement lines stay
     * exactly as they were, because those are what was compared, not what was typed.
     */
    public function restate(
        BankReconciliation $reconciliation,
        User $actor,
        string|float $statementClosing,
        string|float|null $statementOpening = null,
    ): BankReconciliation {
        $this->assertOpen($reconciliation, 'restated');

        $closing = number_format((float) $statementClosing, 4, '.', '');

        // What the bank says it moved in this period, read off the frozen lines
        // the reconciliation was built from — so the opening figure is restated
        // from the same arithmetic the period was opened with.
        $statementTotal = $reconciliation->lines()
            ->where('side', ReconciliationLine::SIDE_STATEMENT)
            ->get()
            ->reduce(fn (string $carry, ReconciliationLine $line) => bcadd($carry, (string) $line->amount, 4), '0.0000');

        $before = [
            'statement_closing' => (string) $reconciliation->statement_closing,
            'statement_opening' => (string) $reconciliation->statement_opening,
        ];

        $reconciliation->forceFill([
            'statement_closing' => $closing,
            'statement_opening' => $statementOpening !== null
                ? number_format((float) $statementOpening, 4, '.', '')
                : bcsub($closing, $statementTotal, 4),
        ])->save();

        $this->audit->record([
            'action' => 'cash_bank.reconciliation_restated',
            'entity_type' => 'bank_reconciliation',
            'entity_id' => $reconciliation->id,
            'actor_id' => $actor->id,
            'branch_id' => $reconciliation->branch_id,
            'before' => $before,
            'after' => [
                'statement_closing' => $closing,
                'statement_opening' => (string) $reconciliation->statement_opening,
            ],
        ]);

        return $this->recompute($reconciliation);
    }

    /**
     * Statement lines inside a period, in the bank's own dates.
     *
     * Lines already signed off in an earlier reconciliation are left out: that
     * period is closed and its lines are accounted for, so counting them again
     * would reconcile the same money twice. Lines matched inside a *draft*
     * reconciliation stay available, which is what lets a period be re-opened
     * when the first attempt paired the wrong things.
     */
    public function statementLines(Account $account, string $from, string $to): Collection
    {
        $closed = ReconciliationLine::query()
            ->where('side', ReconciliationLine::SIDE_STATEMENT)
            ->whereNotNull('statement_line_id')
            ->whereHas('reconciliation', fn ($query) => $query
                ->where('company_id', $account->company_id)
                ->where('account_id', $account->id)
                ->where('status', BankReconciliation::STATUS_SIGNED_OFF))
            ->pluck('statement_line_id')
            ->all();

        return BankStatementLine::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->when($closed !== [], fn ($query) => $query->whereNotIn('id', $closed))
            ->whereDate('value_date', '>=', $from)
            ->whereDate('value_date', '<=', $to)
            ->orderBy('value_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Pair the lines. A reference that matches is evidence; the same amount a
     * few days apart is a strong suggestion. Nothing else is assumed — an
     * unmatched line is a finding, not something to be quietly paired off.
     *
     * @param  Collection<int, array<string, mixed>>  $book
     * @return array{0: array<int, array{book: array<string, mixed>, statement: BankStatementLine, type: string}>, 1: array<int, array<string, mixed>>, 2: array<int, BankStatementLine>}
     */
    public function match(Collection $book, Collection $statement): array
    {
        $pairs = [];
        $bookTaken = [];
        $statementTaken = [];

        $remainingBook = $book->values();

        // Pass 1 — the reference the operator wrote down. This is how a money
        // receipt and a deposit line find each other even a fortnight apart.
        foreach ($statement as $index => $line) {
            $reference = $this->normalise($line->reference);

            if ($reference === '') {
                continue;
            }

            foreach ($remainingBook as $bookIndex => $candidate) {
                if (isset($bookTaken[$bookIndex]) || bccomp($candidate['amount'], $line->signedAmount(), 4) !== 0) {
                    continue;
                }

                if (! in_array($reference, [$this->normalise($candidate['reference']), $this->normalise($candidate['document_reference'])], true)) {
                    continue;
                }

                if ($this->daysApart($candidate['movement_date'], $line->value_date?->toDateString() ?? '') > self::REFERENCE_WINDOW_DAYS) {
                    continue;
                }

                $pairs[] = ['book' => $candidate, 'statement' => $line, 'type' => BankStatementLine::MATCH_AUTO];
                $bookTaken[$bookIndex] = true;
                $statementTaken[$index] = true;

                continue 2;
            }
        }

        // Pass 2 — same amount, close in time, both still free.
        foreach ($statement as $index => $line) {
            if (isset($statementTaken[$index])) {
                continue;
            }

            $best = null;
            $bestGap = PHP_INT_MAX;

            foreach ($remainingBook as $bookIndex => $candidate) {
                if (isset($bookTaken[$bookIndex]) || bccomp($candidate['amount'], $line->signedAmount(), 4) !== 0) {
                    continue;
                }

                $gap = $this->daysApart($candidate['movement_date'], $line->value_date?->toDateString() ?? '');

                if ($gap <= self::DATE_WINDOW_DAYS && $gap < $bestGap) {
                    $best = $bookIndex;
                    $bestGap = $gap;
                }
            }

            if ($best !== null) {
                $pairs[] = ['book' => $remainingBook[$best], 'statement' => $line, 'type' => BankStatementLine::MATCH_AUTO];
                $bookTaken[$best] = true;
                $statementTaken[$index] = true;
            }
        }

        $unmatchedBook = [];

        foreach ($remainingBook as $bookIndex => $candidate) {
            if (! isset($bookTaken[$bookIndex])) {
                $unmatchedBook[] = $candidate;
            }
        }

        $unmatchedStatement = [];

        foreach ($statement as $index => $line) {
            if (! isset($statementTaken[$index])) {
                $unmatchedStatement[] = $line;
            }
        }

        return [$pairs, $unmatchedBook, $unmatchedStatement];
    }

    /**
     * Match two lines by hand: this statement line *is* that book line.
     *
     * Only equal amounts may be paired, and that refusal is the point. A match
     * between two different amounts would make the proof add up to something
     * that never happened; when the amounts differ, one of the two documents is
     * wrong, and the desk says which list to look in rather than hiding it.
     */
    public function matchByHand(BankReconciliation $reconciliation, ReconciliationLine $bookLine, ReconciliationLine $statementLine, User $actor): BankReconciliation
    {
        $this->assertOpen($reconciliation, 'matched by hand');

        if ($bookLine->reconciliation_id !== $reconciliation->id || $statementLine->reconciliation_id !== $reconciliation->id) {
            throw new RuntimeException('Both lines have to belong to this reconciliation.');
        }

        if (! $bookLine->isBookSide() || $bookLine->isMatched()) {
            throw new RuntimeException('Pick an unmatched line from the books to pair with an unmatched line from the statement.');
        }

        if ($statementLine->isBookSide() || $statementLine->isMatched()) {
            throw new RuntimeException('Pick an unmatched line from the statement to pair with an unmatched line from the books.');
        }

        if (bccomp((string) $bookLine->amount, (string) $statementLine->amount, 4) !== 0) {
            throw new RuntimeException(
                'Those two lines are not the same movement: '.number_format((float) $bookLine->amount, 2)
                .' in the books against '.number_format((float) $statementLine->amount, 2)
                .' on the statement. One of the two is wrong — find it there rather than pairing them here.',
            );
        }

        $bookLine->forceFill(['state' => ReconciliationLine::STATE_MATCHED, 'match_type' => BankStatementLine::MATCH_MANUAL])->save();
        $statementLine->forceFill(['state' => ReconciliationLine::STATE_MATCHED, 'match_type' => BankStatementLine::MATCH_MANUAL])->save();

        $this->markStatementLine($statementLine, $bookLine, BankStatementLine::MATCH_MANUAL);

        $this->audit->record([
            'action' => 'cash_bank.reconciliation_matched',
            'entity_type' => 'bank_reconciliation',
            'entity_id' => $reconciliation->id,
            'actor_id' => $actor->id,
            'branch_id' => $reconciliation->branch_id,
            'after' => [
                'book_line' => $bookLine->id,
                'statement_line' => $statementLine->id,
                'amount' => (string) $bookLine->amount,
            ],
        ]);

        return $this->recompute($reconciliation);
    }

    /** Undo a hand-made match. A match the rule made is not undone here: re-open the period instead. */
    public function unmatch(BankReconciliation $reconciliation, ReconciliationLine $line, User $actor): BankReconciliation
    {
        $this->assertOpen($reconciliation, 'un-matched');

        if ($line->reconciliation_id !== $reconciliation->id) {
            throw new RuntimeException('That line belongs to another reconciliation.');
        }

        if (! $line->isMatched()) {
            throw new RuntimeException('That line is not matched.');
        }

        if ($line->match_type !== BankStatementLine::MATCH_MANUAL) {
            throw new RuntimeException(
                'That pair was matched by the amount-and-date rule, not by hand. Re-open the period with a wider date range instead of un-picking it line by line.',
            );
        }

        $statementLine = $line->isBookSide() ? $this->partner($reconciliation, $line) : $line;
        $bookLine = $line->isBookSide() ? $line : $this->partner($reconciliation, $line);

        foreach ([$bookLine, $statementLine] as $frozen) {
            $frozen?->forceFill(['state' => ReconciliationLine::STATE_UNMATCHED, 'match_type' => null])->save();
        }

        if ($statementLine?->statement_line_id !== null) {
            BankStatementLine::query()->whereKey($statementLine->statement_line_id)->update([
                'matched_journal_line_id' => null,
                'matched_at' => null,
                'match_type' => null,
            ]);
        }

        $this->audit->record([
            'action' => 'cash_bank.reconciliation_unmatched',
            'entity_type' => 'bank_reconciliation',
            'entity_id' => $reconciliation->id,
            'actor_id' => $actor->id,
            'branch_id' => $reconciliation->branch_id,
            'before' => ['line' => $line->id, 'amount' => (string) $line->amount],
            'after' => ['line' => $line->id, 'state' => ReconciliationLine::STATE_UNMATCHED],
        ]);

        return $this->recompute($reconciliation);
    }

    /**
     * Sign the reconciliation off.
     *
     * Two refusals, and both are the reason the document exists: it may not be
     * signed while anything is unexplained, and the person who prepared it may
     * not be the person who signs it — that is what makes the signature worth
     * something when somebody asks who checked the bank.
     */
    public function signOff(BankReconciliation $reconciliation, User $actor): BankReconciliation
    {
        if ($reconciliation->isSignedOff()) {
            throw new RuntimeException('This reconciliation was signed off already.');
        }

        if (! $reconciliation->isProved()) {
            $difference = (float) $reconciliation->difference;

            throw new RuntimeException(
                'This reconciliation does not add up: '.number_format(abs($difference), 2)
                .' of it is unexplained. Signing it off would turn a question into a fact — match or enter the lines that explain it first.',
            );
        }

        if ((int) $reconciliation->prepared_by === (int) $actor->id) {
            throw new RuntimeException('The person who prepared a reconciliation cannot sign it off. Somebody else has to look at it — that is the point of the signature.');
        }

        $reconciliation->forceFill([
            'status' => BankReconciliation::STATUS_SIGNED_OFF,
            'signed_off_by' => $actor->id,
            'signed_off_at' => now(),
        ])->save();

        $this->audit->record([
            'action' => 'cash_bank.reconciliation_signed_off',
            'entity_type' => 'bank_reconciliation',
            'entity_id' => $reconciliation->id,
            'actor_id' => $actor->id,
            'branch_id' => $reconciliation->branch_id,
            'after' => [
                'period' => $reconciliation->label(),
                'statement_closing' => (string) $reconciliation->statement_closing,
                'book_balance' => (string) $reconciliation->book_balance,
                'matched' => $reconciliation->matched_count,
                'unmatched_statement' => $reconciliation->unmatched_statement_count,
                'unmatched_book' => $reconciliation->unmatched_book_count,
            ],
        ]);

        return $reconciliation->refresh();
    }

    /** The reconciliations already made for an account, newest first. */
    public function recent(Account $account, int $limit = 8): Collection
    {
        return BankReconciliation::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->with(['preparedBy:id,name', 'signedOffBy:id,name'])
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** The settled facts of a reconciliation: what matched, what is left on each side. */
    public function findings(BankReconciliation $reconciliation): array
    {
        $lines = $reconciliation->lines()->orderBy('movement_date')->orderBy('id')->get();

        return [
            'matched' => $lines->where('state', ReconciliationLine::STATE_MATCHED)->values(),
            'unmatched_book' => $lines->where('state', ReconciliationLine::STATE_UNMATCHED)
                ->where('side', ReconciliationLine::SIDE_BOOK)->values(),
            'unmatched_statement' => $lines->where('state', ReconciliationLine::STATE_UNMATCHED)
                ->where('side', ReconciliationLine::SIDE_STATEMENT)->values(),
        ];
    }

    /**
     * Write the reconciliation down: the figures, the frozen lines, and which
     * statement lines found a counterpart.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array{book: array<string, mixed>, statement: BankStatementLine, type: string}>  $pairs
     * @param  array<int, array<string, mixed>>  $unmatchedBook
     * @param  array<int, BankStatementLine>  $unmatchedStatement
     */
    protected function persist(
        Account $account,
        User $actor,
        array $header,
        string $bookBalance,
        string $bookOpening,
        string $statementClosing,
        string|float|null $statementOpening,
        Collection $statement,
        array $pairs,
        array $unmatchedBook,
        array $unmatchedStatement,
    ): BankReconciliation {
        $unmatchedStatementTotal = '0.0000';

        foreach ($unmatchedStatement as $line) {
            $unmatchedStatementTotal = bcadd($unmatchedStatementTotal, $line->signedAmount(), 4);
        }

        $unmatchedBookTotal = '0.0000';

        foreach ($unmatchedBook as $line) {
            $unmatchedBookTotal = bcadd($unmatchedBookTotal, (string) $line['amount'], 4);
        }

        // What the bank said we had on the closing date, less what it says it
        // moved; the desk shows this beside the books' own opening figure.
        $derivedOpening = $statementOpening !== null
            ? number_format((float) $statementOpening, 4, '.', '')
            : bcsub($statementClosing, $statement->reduce(
                fn (string $carry, BankStatementLine $line) => bcadd($carry, $line->signedAmount(), 4),
                '0.0000',
            ), 4);

        $difference = bcsub(
            bcsub($statementClosing, $bookBalance, 4),
            bcsub($unmatchedStatementTotal, $unmatchedBookTotal, 4),
            4,
        );

        $reconciliation = BankReconciliation::query()->create([
            'company_id' => $account->company_id,
            'branch_id' => $header['branch_id'],
            'account_id' => $account->id,
            'period_start' => $header['period_start'],
            'period_end' => $header['period_end'],
            'statement_opening' => $derivedOpening,
            'statement_closing' => $statementClosing,
            'book_opening' => $bookOpening,
            'book_balance' => $bookBalance,
            'unmatched_statement_total' => $unmatchedStatementTotal,
            'unmatched_book_total' => $unmatchedBookTotal,
            'difference' => $difference,
            'matched_count' => count($pairs),
            'unmatched_statement_count' => count($unmatchedStatement),
            'unmatched_book_count' => count($unmatchedBook),
            'status' => bccomp($difference, '0.0000', 4) === 0
                ? BankReconciliation::STATUS_BALANCED
                : BankReconciliation::STATUS_OPEN,
            'notes' => $header['notes'],
            'prepared_by' => $actor->id,
        ]);

        foreach ($pairs as $pair) {
            $this->freeze($reconciliation, $pair['book'], ReconciliationLine::STATE_MATCHED, $pair['type']);
            $this->freeze($reconciliation, $pair['statement'], ReconciliationLine::STATE_MATCHED, $pair['type']);
            $this->markStatementLine($pair['statement'], $pair['book'], $pair['type']);
        }

        foreach ($unmatchedBook as $line) {
            $this->freeze($reconciliation, $line, ReconciliationLine::STATE_UNMATCHED, null);
        }

        foreach ($unmatchedStatement as $line) {
            $this->freeze($reconciliation, $line, ReconciliationLine::STATE_UNMATCHED, null);
        }

        $this->audit->record([
            'action' => 'cash_bank.reconciliation_opened',
            'entity_type' => 'bank_reconciliation',
            'entity_id' => $reconciliation->id,
            'actor_id' => $actor->id,
            'branch_id' => $reconciliation->branch_id,
            'after' => [
                'account' => $account->code,
                'period' => $reconciliation->label(),
                'matched' => $reconciliation->matched_count,
                'unmatched_statement' => $reconciliation->unmatched_statement_count,
                'unmatched_book' => $reconciliation->unmatched_book_count,
                'difference' => $difference,
            ],
        ]);

        return $reconciliation->refresh();
    }

    /** One frozen line: a book line or a statement line, as it stood when it was compared. */
    protected function freeze(
        BankReconciliation $reconciliation,
        array|BankStatementLine $line,
        string $state,
        ?string $matchType,
    ): ReconciliationLine {
        $isBook = is_array($line);

        return ReconciliationLine::query()->create([
            'company_id' => $reconciliation->company_id,
            'reconciliation_id' => $reconciliation->id,
            'side' => $isBook ? ReconciliationLine::SIDE_BOOK : ReconciliationLine::SIDE_STATEMENT,
            'journal_line_id' => $isBook ? $line['journal_line_id'] : null,
            'statement_line_id' => $isBook ? null : $line->id,
            'movement_date' => $isBook ? $line['movement_date'] : $line->value_date?->toDateString(),
            'reference' => $isBook ? $line['reference'] : $line->reference,
            'description' => $isBook ? $line['description'] : $line->description,
            'amount' => $isBook ? $line['amount'] : $line->signedAmount(),
            'state' => $state,
            'match_type' => $matchType,
        ]);
    }

    /** The statement line remembers which book line it found, so the same line is never matched twice. */
    protected function markStatementLine(BankStatementLine $statementLine, array|ReconciliationLine $bookLine, string $type): void
    {
        $statementLine->forceFill([
            'matched_journal_line_id' => is_array($bookLine) ? $bookLine['journal_line_id'] : $bookLine->journal_line_id,
            'matched_at' => now(),
            'match_type' => $type,
        ])->save();
    }

    /** Restate the figures after a hand-made match or an un-match. */
    protected function recompute(BankReconciliation $reconciliation): BankReconciliation
    {
        $book = $reconciliation->lines()->where('side', ReconciliationLine::SIDE_BOOK)->get();
        $statement = $reconciliation->lines()->where('side', ReconciliationLine::SIDE_STATEMENT)->get();

        $unmatchedStatementTotal = $statement->where('state', ReconciliationLine::STATE_UNMATCHED)
            ->reduce(fn (string $carry, ReconciliationLine $line) => bcadd($carry, (string) $line->amount, 4), '0.0000');

        $unmatchedBookTotal = $book->where('state', ReconciliationLine::STATE_UNMATCHED)
            ->reduce(fn (string $carry, ReconciliationLine $line) => bcadd($carry, (string) $line->amount, 4), '0.0000');

        $difference = bcsub(
            bcsub((string) $reconciliation->statement_closing, (string) $reconciliation->book_balance, 4),
            bcsub($unmatchedStatementTotal, $unmatchedBookTotal, 4),
            4,
        );

        $reconciliation->forceFill([
            'unmatched_statement_total' => $unmatchedStatementTotal,
            'unmatched_book_total' => $unmatchedBookTotal,
            'difference' => $difference,
            'matched_count' => $book->where('state', ReconciliationLine::STATE_MATCHED)->count(),
            'unmatched_statement_count' => $statement->where('state', ReconciliationLine::STATE_UNMATCHED)->count(),
            'unmatched_book_count' => $book->where('state', ReconciliationLine::STATE_UNMATCHED)->count(),
            'status' => bccomp($difference, '0.0000', 4) === 0
                ? BankReconciliation::STATUS_BALANCED
                : BankReconciliation::STATUS_OPEN,
        ])->save();

        return $reconciliation->refresh();
    }

    /** The line on the other side of a hand-made pair, resolved from the statement line's own record. */
    protected function partner(BankReconciliation $reconciliation, ReconciliationLine $line): ?ReconciliationLine
    {
        if ($line->isBookSide()) {
            $statementIds = $reconciliation->lines()
                ->where('side', ReconciliationLine::SIDE_STATEMENT)
                ->where('match_type', BankStatementLine::MATCH_MANUAL)
                ->pluck('statement_line_id')
                ->filter()
                ->all();

            $partners = BankStatementLine::query()
                ->whereIn('id', $statementIds)
                ->where('matched_journal_line_id', $line->journal_line_id)
                ->pluck('id')
                ->all();

            return $partners === [] ? null : $reconciliation->lines()
                ->where('side', ReconciliationLine::SIDE_STATEMENT)
                ->whereIn('statement_line_id', $partners)
                ->first();
        }

        $journalLineId = BankStatementLine::query()
            ->whereKey($line->statement_line_id)
            ->value('matched_journal_line_id');

        return $journalLineId === null ? null : $reconciliation->lines()
            ->where('side', ReconciliationLine::SIDE_BOOK)
            ->where('journal_line_id', $journalLineId)
            ->first();
    }

    protected function assertMoneyAccount(Account $account): void
    {
        if ((int) $account->company_id !== (int) ($this->context->companyId() ?? 0)) {
            throw new RuntimeException('That account belongs to another company.');
        }

        if (! $this->accounts->isMoney($account)) {
            throw new RuntimeException('Only a cash, bank or wallet account can be reconciled against a statement.');
        }
    }

    protected function assertOpen(BankReconciliation $reconciliation, string $verb): void
    {
        if ($reconciliation->isFrozen()) {
            throw new RuntimeException(
                'This reconciliation was signed off on '.$reconciliation->signed_off_at?->toDayDateTimeString()
                .' — a signed reconciliation is history and cannot be '.$verb.' afterwards.',
            );
        }
    }

    protected function normalise(?string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $value) ?? '');
    }

    protected function daysApart(?string $left, ?string $right): int
    {
        if ($left === null || $right === null || $left === '' || $right === '') {
            return PHP_INT_MAX;
        }

        return (int) abs(Carbon::parse($left)->diffInDays(Carbon::parse($right)));
    }
}
