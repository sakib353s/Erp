<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident audit recorder (Rule 16, decision D14).
 *
 * Chain construction (inside the caller's transaction):
 *   1. lock the company singleton row (serialises chain writers per company),
 *   2. read last seq + row_hash,
 *   3. compute row_hash = sha256(canonical(payload) + prev_hash),
 *   4. insert.
 *
 * All before/after payloads pass through AuditRedactor — secrets,
 * tokens and credentials never reach the table.
 */
class AuditRecorder
{
    public function __construct(protected TenantContext $context) {}

    /** @param array<string, mixed> $attrs */
    public function record(array $attrs): ?AuditEvent
    {
        $companyId = $attrs['company_id'] ?? $this->context->companyId();

        if ($companyId === null) {
            return null; // nothing exists yet to audit against (pre-setup)
        }

        $before = isset($attrs['before']) ? AuditRedactor::redact($attrs['before']) : null;
        $after = isset($attrs['after']) ? AuditRedactor::redact($attrs['after']) : null;

        $user = $attrs['actor'] ?? auth()->user();
        $request = request();

        return DB::transaction(function () use (
            $companyId, $attrs, $before, $after, $user, $request
        ) {
            // Serialise chain writers per company (deadlock-ordered: companies first).
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            $last = AuditEvent::query()
                ->where('company_id', $companyId)
                ->orderByDesc('seq')
                ->first(['seq', 'row_hash']);

            $seq = ($last?->seq ?? 0) + 1;
            $prevHash = $last?->row_hash;

            $createdAt = now()->format('Y-m-d H:i:s');

            $payload = [
                'company_id' => $companyId,
                'seq' => $seq,
                'action' => (string) ($attrs['action'] ?? 'unknown'),
                'actor_type' => (string) ($attrs['actor_type'] ?? ($user !== null ? 'user' : 'system')),
                'actor_id' => $attrs['actor_id'] ?? $user?->id,
                'actor_label' => $attrs['actor_label'] ?? $user?->name,
                'entity_type' => $attrs['entity_type'] ?? null,
                'entity_id' => $attrs['entity_id'] ?? null,
                'branch_id' => $attrs['branch_id'] ?? $this->context->branchId(),
                'ip' => $attrs['ip'] ?? ($request?->ip() ?? null),
                'user_agent' => $attrs['user_agent'] ?? mb_substr((string) ($request?->userAgent() ?? ''), 0, 191),
                'correlation_id' => $attrs['correlation_id']
                    ?? $request?->attributes->get('correlation_id'),
                'before' => $before,
                'after' => $after,
                'amount' => $attrs['amount'] ?? null,
                'currency' => $attrs['currency'] ?? null,
                'result' => (string) ($attrs['result'] ?? 'success'),
                'reason' => $attrs['reason'] ?? null,
                'created_at' => $createdAt,
                'prev_hash' => $prevHash,
            ];

            $payload['row_hash'] = self::canonicalHash($payload);

            return AuditEvent::query()->create($payload);
        });
    }

    /** Convenience wrapper used by the Auditable model trait. */
    public function recordModel(string $action, Model $model, ?array $before, ?array $after): ?AuditEvent
    {
        $companyId = $model->getAttribute('company_id')
            ?? ($model instanceof \App\Domain\Foundation\Company ? $model->id : null)
            ?? $this->context->companyId();

        return $this->record([
            'company_id' => $companyId,
            'action' => $action,
            'entity_type' => $model::class,
            'entity_id' => $model->getKey(),
            'branch_id' => $model->getAttribute('branch_id') ?? null,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * Deterministic canonical hash shared with AuditChainVerifier.
     * $row must contain every payload field + prev_hash + row_hash excluded.
     */
    public static function canonicalHash(array $row): string
    {
        $canonical = [
            'company_id' => (int) ($row['company_id'] ?? 0),
            'seq' => (int) ($row['seq'] ?? 0),
            'action' => (string) ($row['action'] ?? ''),
            'actor_type' => (string) ($row['actor_type'] ?? ''),
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'actor_label' => $row['actor_label'] ?? null,
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            'branch_id' => $row['branch_id'] === null ? null : (int) $row['branch_id'],
            'ip' => $row['ip'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'correlation_id' => $row['correlation_id'] ?? null,
            'before' => $row['before'] ?? null,
            'after' => $row['after'] ?? null,
            'amount' => $row['amount'] === null ? null : number_format((float) $row['amount'], 4, '.', ''),
            'currency' => $row['currency'] ?? null,
            'result' => (string) ($row['result'] ?? ''),
            'reason' => $row['reason'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'prev_hash' => $row['prev_hash'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
