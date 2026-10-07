<?php

namespace App\Domain\Outbox\Jobs;

use App\Domain\Outbox\Contracts\ArrayPayload;
use App\Domain\Outbox\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches one outbox row to its domain listeners after commit.
 *
 * Truthful lifecycle (Rule I / D13): pending → processing → dispatched |
 * failed. Failures increment attempts and schedule a delayed retry with
 * exponential backoff until max_attempts, then park as `failed` with the
 * error recorded — never a fake success. Listeners must be idempotent
 * (notification fan-out uses dedupe keys).
 */
class ProcessOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // retries are managed explicitly via the outbox row

    public function __construct(public int $outboxId) {}

    public function handle(): void
    {
        $row = OutboxEvent::query()->find($this->outboxId);

        if ($row === null || $row->status === 'dispatched' || $row->status === 'discarded') {
            return;
        }

        if ($row->available_at->isFuture()) {
            return; // not yet due (a scheduled dispatcher will pick it up)
        }

        $row->update(['status' => 'processing', 'attempts' => $row->attempts + 1]);

        try {
            /** @var class-string<ArrayPayload> $class */
            $class = $row->event_type;

            if (! is_subclass_of($class, ArrayPayload::class)) {
                throw new \RuntimeException("Event class {$class} does not implement ArrayPayload.");
            }

            Event::dispatch($class::fromArray($row->payload));

            $row->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $e) {
            $this->handleFailure($row, $e);
        }
    }

    protected function handleFailure(OutboxEvent $row, Throwable $e): void
    {
        $error = mb_substr($e->getMessage(), 0, 500);

        if ($row->attempts >= $row->max_attempts) {
            $row->update(['status' => 'failed', 'last_error' => $error]);

            Log::channel('security')->error('Outbox event exhausted retries', [
                'outbox_id' => $row->id,
                'event_type' => $row->event_type,
                'attempts' => $row->attempts,
                'error' => $error,
            ]);

            return;
        }

        $backoff = config('erp.outbox.backoff_seconds', [60, 300, 1800, 7200, 21600]);
        $seconds = $backoff[min($row->attempts - 1, count($backoff) - 1)];

        $row->update([
            'status' => 'pending',
            'available_at' => now()->addSeconds($seconds),
            'last_error' => $error,
        ]);

        // Schedule the retry (sync driver executes immediately only when due;
        // delayed retries wait for the queue worker / cron dispatcher).
        static::dispatch($row->id)->delay(now()->addSeconds($seconds));
    }
}
