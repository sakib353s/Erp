<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Tamper-evident audit row (decision D14): per-company `seq` +
 * SHA-256 hash chain (prev_hash → row_hash). The model refuses any
 * update or delete at the application layer; the hash chain makes
 * out-of-band tampering detectable via `erp:chain-verify`.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'seq', 'action', 'actor_type', 'actor_id', 'actor_label',
        'entity_type', 'entity_id', 'branch_id', 'ip', 'user_agent', 'correlation_id',
        'before', 'after', 'amount', 'currency', 'result', 'reason',
        'prev_hash', 'row_hash', 'created_at',
    ];

    protected $casts = [
        'seq' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'amount' => 'decimal:4',
    ];

    /* ------------------------- immutability ------------------------- */

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        throw new RuntimeException('Audit events are immutable.');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Audit events are immutable.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Audit events are immutable.');
    }
}
