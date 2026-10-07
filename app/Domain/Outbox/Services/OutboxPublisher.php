<?php

namespace App\Domain\Outbox\Services;

use App\Domain\Outbox\Contracts\ArrayPayload;
use App\Domain\Outbox\Jobs\ProcessOutboxEvent;
use App\Domain\Outbox\OutboxEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Outbox publisher (decision D13): the event row is written in the SAME
 * transaction as the business change; dispatch to listeners happens
 * strictly after commit via a queued job with retry/backoff.
 */
class OutboxPublisher
{
    /**
     * @param  array{aggregate_type?:?string, aggregate_id?:?int, idempotency_key?:?string, company_id?:?int}  $options
     */
    public function publish(ArrayPayload $event, array $options = []): OutboxEvent
    {
        $companyId = $options['company_id'] ?? app(\App\Domain\Foundation\Services\TenantContext::class)->companyId();

        $row = new OutboxEvent([
            'company_id' => $companyId,
            'aggregate_type' => $options['aggregate_type'] ?? null,
            'aggregate_id' => $options['aggregate_id'] ?? null,
            'event_type' => $event::class,
            'payload' => $event->toArray(),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => (int) config('erp.outbox.max_attempts', 5),
            'available_at' => now(),
            'idempotency_key' => $options['idempotency_key'] ?? null,
        ]);

        try {
            $row->save();
        } catch (QueryException $e) {
            // Duplicate idempotency key = the event was already published
            // in a prior (possibly retried) attempt — keep truth, skip dup.
            $message = $e->getMessage();
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate entry')) {
                return OutboxEvent::query()
                    ->where('idempotency_key', $options['idempotency_key'])
                    ->firstOrFail();
            }

            throw $e;
        }

        // Strictly post-commit: with the sync driver this runs right after
        // the transaction commits; with the database driver a worker picks
        // it up (a cron fallback exists: `php artisan erp:outbox:dispatch`).
        DB::afterCommit(fn () => ProcessOutboxEvent::dispatch($row->id));

        return $row;
    }
}
