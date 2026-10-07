<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\FiscalPeriod;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE sole code path that creates journal_entries (§7.1).
 *
 * Invariants enforced before insert:
 *  - at least two lines, each with a postable account;
 *  - Σdebits = Σcredits asserted with BCMath (exact decimal math);
 *  - target fiscal period is OPEN and contains the entry date;
 *  - checksum stored on the header for later integrity verification.
 *
 * The fiscal_periods row is locked inside the transaction so concurrent
 * postings into the same period serialise and a closed period can never
 * slip a row through (DOUBLE-ENTRY-01..04).
 */
class JournalPostingService
{
    public function __construct(
        protected TenantContext $context,
        protected NumberingService $numbering,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{
     *   entry_date: string,
     *   description: string,
     *   narration?: string|null,
     *   journal_type?: string,
     *   source_type?: string|null,
     *   source_id?: int|null,
     *   source_event?: string|null,
     *   branch_id?: int|null,
     *   lines: array<int, array{
     *     account_id: int,
     *     dc: string,
     *     amount: int|float|string,
     *     party_type?: string|null,
     *     party_id?: int|null,
     *     cost_center_id?: int|null,
     *     narration?: string|null,
     *   }>,
     * } $payload
     */
    public function post(array $payload, ?User $actor = null): JournalEntry
    {
        $companyId = $this->context->companyId()
            ?? $actor?->company_id
            ?? abort(500, 'No company context for journal posting.');

        $branchId = $payload['branch_id'] ?? $this->context->branchId();
        $entryDate = Carbon::parse($payload['entry_date']);
        $lines = $payload['lines'] ?? [];

        $this->assertLinesShape($lines, $companyId);

        [$totalDebit, $totalCredit] = $this->assertBalanced($lines);

        $checksum = $this->checksum($companyId, $entryDate, $lines);

        return DB::transaction(function () use (
            $payload, $lines, $companyId, $branchId, $entryDate,
            $totalDebit, $totalCredit, $checksum, $actor
        ) {
            $period = $this->lockOpenPeriod($companyId, $entryDate);

            $entryType = $payload['journal_type'] ?? 'manual';
            $documentTypeId = DocumentType::query()->where('code', 'journal_voucher')->value('id')
                ?? abort(500, 'journal_voucher document type is not seeded.');

            $entryNo = $this->numbering->allocate($documentTypeId, $branchId);

            $entry = JournalEntry::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'fiscal_period_id' => $period->id,
                'entry_no' => $entryNo,
                'entry_date' => $entryDate->toDateString(),
                'journal_type' => $entryType,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'source_event' => $payload['source_event'] ?? null,
                'description' => (string) $payload['description'],
                'narration' => $payload['narration'] ?? null,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'checksum' => $checksum,
                'posting_state' => JournalEntry::STATE_POSTED,
                'posted_by' => $actor?->id ?? $this->context->user()?->id,
                'posted_at' => now(),
                'created_by' => $actor?->id ?? $this->context->user()?->id,
            ]);

            foreach (array_values($lines) as $i => $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'company_id' => $companyId,
                    'account_id' => (int) $line['account_id'],
                    'dc' => $line['dc'],
                    'amount' => $this->normalizeAmount($line['amount']),
                    'currency' => $line['currency'] ?? 'BDT',
                    'party_type' => $line['party_type'] ?? null,
                    'party_id' => $line['party_id'] ?? null,
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                    'narration' => $line['narration'] ?? null,
                    'line_no' => $i + 1,
                ]);
            }

            $this->audit->record([
                'action' => 'accounting.journal_posted',
                'entity_type' => 'journal_entry',
                'entity_id' => $entry->id,
                'actor_id' => $actor?->id,
                'branch_id' => $branchId,
                'amount' => $totalDebit,
                'after' => [
                    'entry_no' => $entry->entry_no,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'journal_type' => $entry->journal_type,
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'line_count' => count($lines),
                    'checksum' => $checksum,
                ],
            ]);

            return $entry->load(['lines.account', 'fiscalPeriod']);
        });
    }

    /**
     * Compensating mirror in an OPEN period. Original stays untouched.
     * Reason is mandatory (§7.3 / 09-10).
     */
    public function reverse(JournalEntry $entry, string $reason, ?User $actor = null): JournalEntry
    {
        if ($entry->isReversed()
            || $entry->reversals()->where('posting_state', JournalEntry::STATE_POSTED)->exists()) {
            throw new RuntimeException('This journal entry has already been reversed.');
        }

        if (! $entry->isPosted()) {
            throw new RuntimeException('Only posted journal entries can be reversed.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reversal reason is required.');
        }

        $lines = $entry->lines()->get()->map(fn (JournalLine $line) => [
            'account_id' => $line->account_id,
            'dc' => $line->dc === JournalLine::DEBIT ? JournalLine::CREDIT : JournalLine::DEBIT,
            'amount' => $line->amount,
            'currency' => $line->currency,
            'party_type' => $line->party_type,
            'party_id' => $line->party_id,
            'cost_center_id' => $line->cost_center_id,
            'narration' => 'Reversal of '.$entry->entry_no.': '.$reason,
        ])->all();

        $reversal = $this->post([
            'entry_date' => now()->toDateString(),
            'description' => 'Reversal of '.$entry->entry_no.' — '.$reason,
            'narration' => $reason,
            'journal_type' => 'reversal',
            'source_type' => 'journal_entry',
            'source_id' => $entry->id,
            'branch_id' => $entry->branch_id,
            'lines' => $lines,
        ], $actor);

        $reversal->forceFill(['reversal_of_id' => $entry->id])->save();

        $entry->update(['posting_state' => JournalEntry::STATE_REVERSED]);

        $this->audit->record([
            'action' => 'accounting.journal_reversed',
            'entity_type' => 'journal_entry',
            'entity_id' => $entry->id,
            'actor_id' => $actor?->id,
            'amount' => $entry->total_debit,
            'reason' => $reason,
            'before' => ['posting_state' => JournalEntry::STATE_POSTED],
            'after' => [
                'posting_state' => JournalEntry::STATE_REVERSED,
                'reversal_entry_id' => $reversal->id,
                'reversal_entry_no' => $reversal->entry_no,
            ],
        ]);

        return $reversal;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function assertLinesShape(array $lines, int $companyId): void
    {
        if (count($lines) < 2) {
            throw new RuntimeException('A journal entry requires at least two lines.');
        }

        foreach ($lines as $line) {
            $dc = $line['dc'] ?? null;

            if (! in_array($dc, [JournalLine::DEBIT, JournalLine::CREDIT], true)) {
                throw new RuntimeException('Each journal line must be debit or credit.');
            }

            if ($this->normalizeAmount($line['amount'] ?? 0) <= 0) {
                throw new RuntimeException('Journal line amounts must be greater than zero.');
            }

            $account = Account::query()
                ->where('company_id', $companyId)
                ->whereKey($line['account_id'] ?? 0)
                ->first();

            if ($account === null) {
                throw new RuntimeException('Unknown account on journal line.');
            }

            if ($account->is_group || ! $account->is_active) {
                throw new RuntimeException(sprintf(
                    'Account %s is not postable (group or inactive).',
                    $account->code,
                ));
            }
        }
    }

    /**
     * Σdebits = Σcredits asserted with BCMath before insert.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: string, 1: string} total debit, total credit (normalized strings)
     */
    protected function assertBalanced(array $lines): array
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($lines as $line) {
            $amount = $this->normalizeAmount($line['amount'] ?? 0);

            if ($line['dc'] === JournalLine::DEBIT) {
                $debit = bcadd($debit, $amount, 4);
            } else {
                $credit = bcadd($credit, $amount, 4);
            }
        }

        if (bccomp($debit, $credit, 4) !== 0) {
            throw new RuntimeException(sprintf(
                'Unbalanced journal: debits %s ≠ credits %s.',
                $debit,
                $credit,
            ));
        }

        if (bccomp($debit, '0.0000', 4) <= 0) {
            throw new RuntimeException('Journal total must be greater than zero.');
        }

        return [$debit, $credit];
    }

    /**
     * Lock the fiscal period containing entry_date; reject closed periods.
     * Serialises same-period postings (SELECT FOR UPDATE, §6.4).
     */
    protected function lockOpenPeriod(int $companyId, Carbon $entryDate): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate)
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            throw new RuntimeException('No fiscal period covers the journal entry date.');
        }

        if ($period->isClosed()) {
            throw new RuntimeException(sprintf(
                'Fiscal period %s is closed — posting is not allowed.',
                $period->code,
            ));
        }

        return $period;
    }

    protected function checksum(int $companyId, Carbon $entryDate, array $lines): string
    {
        $parts = [
            (string) $companyId,
            $entryDate->toDateString(),
        ];

        foreach ($lines as $line) {
            $parts[] = implode('|', [
                (string) ($line['account_id'] ?? ''),
                (string) ($line['dc'] ?? ''),
                $this->normalizeAmount($line['amount'] ?? 0),
            ]);
        }

        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    protected function normalizeAmount(int|float|string $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }
}
