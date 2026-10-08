<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\AuditArchive;
use App\Domain\Audit\AuditEvent;

/**
 * Verifies the per-company audit hash chain (decision D14).
 * Used by `php artisan erp:chain-verify` and by tests.
 */
class AuditChainVerifier
{
    /**
     * @return array{ok:bool, checked:int, broken_at:?int, reason:?string}
     */
    public function verify(int $companyId): array
    {
        $prevHash = null;
        $expectedSeq = 1;
        $checked = 0;

        $query = AuditEvent::query()
            ->where('company_id', $companyId)
            ->orderBy('seq');

        foreach ($query->cursor() as $event) {
            $row = $event->toArray();

            if ((int) $event->seq !== $expectedSeq) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->seq, 'reason' => 'sequence_gap'];
            }

            if (($event->prev_hash ?? null) !== $prevHash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->seq, 'reason' => 'prev_hash_mismatch'];
            }

            $computed = AuditRecorder::canonicalHash($row);

            if (! hash_equals($computed, (string) $event->row_hash)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->seq, 'reason' => 'row_hash_mismatch'];
            }

            $prevHash = $event->row_hash;
            $expectedSeq++;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /**
     * §16-34 — verify a sealed period against the events that are still there.
     *
     * Sealing writes down what the chain looked like when the period closed:
     * how many events it held, the first and last row hashes, and a checksum
     * over those facts. Verifying re-reads the segment and asks four questions:
     * is it still the same size, are the ends still the same rows, does every
     * row still hash to itself, and does the seal's own checksum still match the
     * facts it was computed from. A row deleted inside a sealed period fails the
     * first question; an edited row fails the third; a rewritten seal fails the
     * fourth.
     *
     * @return array{ok:bool, checked:int, broken_at:?int, reason:?string}
     */
    public function verifyArchive(AuditArchive $archive): array
    {
        $events = AuditEvent::query()
            ->where('company_id', $archive->company_id)
            ->whereBetween('seq', [(int) $archive->seq_from, (int) $archive->seq_to])
            ->orderBy('seq')
            ->get();

        if ($events->count() !== (int) $archive->event_count) {
            return [
                'ok' => false,
                'checked' => $events->count(),
                'broken_at' => (int) $archive->seq_from,
                'reason' => 'archive_count_mismatch',
            ];
        }

        $first = $events->first();
        $last = $events->last();

        if ($first !== null && ! hash_equals((string) $archive->chain_start_hash, (string) $first->row_hash)) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => (int) $first->seq, 'reason' => 'archive_start_hash_mismatch'];
        }

        if ($last !== null && ! hash_equals((string) $archive->chain_end_hash, (string) $last->row_hash)) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => (int) $last->seq, 'reason' => 'archive_end_hash_mismatch'];
        }

        if (! hash_equals(self::sealChecksum($archive->toArray()), (string) $archive->checksum)) {
            return ['ok' => false, 'checked' => 0, 'broken_at' => (int) $archive->seq_from, 'reason' => 'archive_checksum_mismatch'];
        }

        $checked = 0;

        foreach ($events as $event) {
            $computed = AuditRecorder::canonicalHash($event->toArray());

            if (! hash_equals($computed, (string) $event->row_hash)) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $event->seq, 'reason' => 'archive_row_hash_mismatch'];
            }

            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    /**
     * Every sealed period of one company.
     *
     * @return array{ok:bool, checked:int, archives:int, failures:array<int, array<string, mixed>>}
     */
    public function verifyArchives(int $companyId): array
    {
        $failures = [];
        $checked = 0;
        $archives = 0;

        foreach (AuditArchive::query()->where('company_id', $companyId)->orderBy('seq_from')->get() as $archive) {
            $archives++;
            $result = $this->verifyArchive($archive);

            if (! $result['ok']) {
                $failures[] = [
                    'period' => $archive->period,
                    'seq_from' => (int) $archive->seq_from,
                    'seq_to' => (int) $archive->seq_to,
                    'reason' => $result['reason'],
                    'broken_at' => $result['broken_at'],
                ];

                continue;
            }

            $checked += $result['checked'];
        }

        return [
            'ok' => $failures === [],
            'checked' => $checked,
            'archives' => $archives,
            'failures' => $failures,
        ];
    }

    /**
     * The checksum a seal is computed from — shared by the sealing command and
     * the verifier, so a seal can never be checked against a different recipe
     * than the one that wrote it.
     *
     * @param  array<string, mixed>  $seal
     */
    public static function sealChecksum(array $seal): string
    {
        $canonical = [
            'company_id' => (int) ($seal['company_id'] ?? 0),
            'period' => (string) ($seal['period'] ?? ''),
            'seq_from' => (int) ($seal['seq_from'] ?? 0),
            'seq_to' => (int) ($seal['seq_to'] ?? 0),
            'event_count' => (int) ($seal['event_count'] ?? 0),
            'chain_start_hash' => $seal['chain_start_hash'] ?? null,
            'chain_end_hash' => $seal['chain_end_hash'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
