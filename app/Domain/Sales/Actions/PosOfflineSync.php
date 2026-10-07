<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\PosTransaction;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * PosOfflineSync (02-46). Batch commit of offline-captured POS sales.
 * Each sale must carry a client_uuid; re-submitting the same UUID is
 * idempotent (returns existing). Same UUID with a different grand total
 * is an explicit conflict — never silently overwritten. Server pricing,
 * stock, and GL run through CommitPosSale at sync time only.
 */
class PosOfflineSync
{
    public function __construct(
        protected CommitPosSale $commitPosSale,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{sales: array<int, array<string, mixed>>}  $payload
     * @return array{results: array<int, array<string, mixed>>, committed: int, already_committed: int, conflicts: int, failed: int}
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $sales = $payload['sales'] ?? [];

        if (! is_array($sales) || $sales === []) {
            throw new RuntimeException('Offline sync requires at least one sale.');
        }

        $results = [];
        $committed = 0;
        $alreadyCommitted = 0;
        $conflicts = 0;
        $failed = 0;

        foreach ($sales as $index => $sale) {
            $clientUuid = trim((string) ($sale['client_uuid'] ?? ''));
            if ($clientUuid === '') {
                $failed++;
                $results[] = [
                    'index' => $index,
                    'client_uuid' => null,
                    'status' => 'failed',
                    'error' => 'client_uuid is required for offline sync.',
                ];

                continue;
            }

            $existing = PosTransaction::query()
                ->where('company_id', $companyId)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing !== null) {
                $incomingTotal = $this->expectedTotal($sale);
                if ($incomingTotal !== null && abs((float) $existing->total - $incomingTotal) > 0.0001) {
                    $conflicts++;
                    $results[] = [
                        'index' => $index,
                        'client_uuid' => $clientUuid,
                        'status' => 'conflict',
                        'transaction_id' => $existing->id,
                        'server_total' => (float) $existing->total,
                        'client_total' => $incomingTotal,
                        'error' => 'Same client_uuid already committed with a different total.',
                    ];

                    continue;
                }

                $alreadyCommitted++;
                $results[] = [
                    'index' => $index,
                    'client_uuid' => $clientUuid,
                    'status' => 'already_committed',
                    'transaction_id' => $existing->id,
                    'invoice_no' => $existing->invoice?->invoice_no,
                    'total' => (float) $existing->total,
                ];

                continue;
            }

            try {
                $transaction = $this->commitPosSale->handle($sale, $request);
                $committed++;
                $results[] = [
                    'index' => $index,
                    'client_uuid' => $clientUuid,
                    'status' => 'committed',
                    'transaction_id' => $transaction->id,
                    'invoice_no' => $transaction->invoice?->invoice_no,
                    'total' => (float) $transaction->total,
                ];
            } catch (RuntimeException $e) {
                $failed++;
                $results[] = [
                    'index' => $index,
                    'client_uuid' => $clientUuid,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $summary = [
            'committed' => $committed,
            'already_committed' => $alreadyCommitted,
            'conflicts' => $conflicts,
            'failed' => $failed,
        ];

        $this->audit->record([
            'action' => 'pos.offline_sync',
            'entity_type' => 'pos_transaction',
            'entity_id' => null,
            'actor_id' => $request->user()->id,
            'after' => $summary + ['batch_size' => count($sales)],
        ]);

        return ['results' => $results] + $summary;
    }

    /** Best-effort total for conflict detection (client may omit). */
    protected function expectedTotal(array $sale): ?float
    {
        if (isset($sale['client_total']) && is_numeric($sale['client_total'])) {
            return round((float) $sale['client_total'], 4);
        }

        return null;
    }
}
