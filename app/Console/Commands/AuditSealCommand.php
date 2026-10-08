<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditArchive;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\Services\AuditChainVerifier;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * §16-34 — seal a closed period of the audit chain.
 *
 * A hash chain proves that nothing *inside* it was rewritten. A seal adds the
 * other half: what the chain looked like when a period closed — how many events
 * it held, where it started and ended — written down once and checked later. A
 * row deleted from an old month cannot be hidden by the chain alone (the chain
 * is about rows that are there, not rows that are gone); it can be caught by a
 * seal, because the segment no longer has the size or the ends it was sealed
 * with.
 *
 * The period is sealed **only if the chain verifies first**: sealing a broken
 * chain would put an official stamp on a lie. Re-sealing is refused unless
 * `--force` is given, and a re-seal is itself audited, because a seal that can
 * be quietly replaced proves nothing.
 */
class AuditSealCommand extends Command
{
    protected $signature = 'erp:audit:seal
        {--company= : Company id (defaults to THE company)}
        {--period= : Period as YYYY-MM (defaults to the last completed month)}
        {--force : Re-seal a period that already has a seal}';

    protected $description = 'Seal a closed period of the tamper-evident audit chain';

    public function handle(AuditChainVerifier $verifier, AuditRecorder $recorder): int
    {
        $companyId = (int) ($this->option('company') ?: Company::current()?->id);

        if ($companyId === 0) {
            $this->error('No company found — nothing to seal.');

            return self::FAILURE;
        }

        $period = (string) ($this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $this->error("Period [{$period}] is not a month — use YYYY-MM.");

            return self::FAILURE;
        }

        $start = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        if ($end->isFuture()) {
            $this->error("Period [{$period}] has not finished — a period is sealed after it closes.");

            return self::FAILURE;
        }

        // 1. The chain must be intact before anything is stamped.
        $chain = $verifier->verify($companyId);

        if (! $chain['ok']) {
            $this->error(sprintf(
                'Refusing to seal: the chain is BROKEN for company %d (%s at seq %s). Fix the chain first.',
                $companyId,
                $chain['reason'],
                $chain['broken_at'] ?? '?',
            ));

            return self::FAILURE;
        }

        // 2. The segment to seal.
        $events = AuditEvent::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->orderBy('seq')
            ->get(['id', 'seq', 'row_hash']);

        if ($events->isEmpty()) {
            $this->info("Nothing to seal for {$period} — the period holds no events. No empty seal was written.");

            return self::SUCCESS;
        }

        $seal = [
            'company_id' => $companyId,
            'period' => $period,
            'seq_from' => (int) $events->first()->seq,
            'seq_to' => (int) $events->last()->seq,
            'event_count' => $events->count(),
            'chain_start_hash' => (string) $events->first()->row_hash,
            'chain_end_hash' => (string) $events->last()->row_hash,
        ];

        $existing = AuditArchive::query()
            ->where('company_id', $companyId)
            ->where('period', $period)
            ->first();

        if ($existing !== null && ! $this->option('force')) {
            $result = $verifier->verifyArchive($existing);

            $this->line($result['ok']
                ? "Period {$period} is already sealed ({$existing->event_count} events) and verifies: {$existing->checksum}."
                : "Period {$period} is already sealed but DOES NOT VERIFY: {$result['reason']} at seq {$result['broken_at']}.");

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $seal['checksum'] = AuditChainVerifier::sealChecksum($seal);
        $seal['archived_by'] = null; // a clock, not a person

        if ($existing !== null) {
            $existing->forceFill($seal)->save();
            $archive = $existing->fresh();
        } else {
            $archive = AuditArchive::query()->create($seal);

            // Sealing is itself a fact worth keeping: the audit trail should be
            // able to answer "who sealed March, and when".
            $recorder->record([
                'company_id' => $companyId,
                'action' => 'security.audit_period_sealed',
                'entity_type' => 'audit_archive',
                'entity_id' => $archive->id,
                'actor_type' => 'system',
                'after' => [
                    'period' => $period,
                    'seq_from' => $seal['seq_from'],
                    'seq_to' => $seal['seq_to'],
                    'event_count' => $seal['event_count'],
                    'checksum' => $seal['checksum'],
                ],
            ]);
        }

        $result = $verifier->verifyArchive($archive);

        if (! $result['ok']) {
            $this->error(sprintf(
                'Sealed %s but it does not verify: %s at seq %s.',
                $period,
                $result['reason'],
                $result['broken_at'] ?? '?',
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sealed %s for company %d: %d events, seq %d–%d, checksum %s — verified.',
            $period,
            $companyId,
            $seal['event_count'],
            $seal['seq_from'],
            $seal['seq_to'],
            substr($seal['checksum'], 0, 16).'…',
        ));

        return self::SUCCESS;
    }
}
