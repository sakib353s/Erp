<?php

namespace App\Domain\Audit\Services;

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
}
