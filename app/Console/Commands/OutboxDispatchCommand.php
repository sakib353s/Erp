<?php

namespace App\Console\Commands;

use App\Domain\Outbox\Jobs\ProcessOutboxEvent;
use App\Domain\Outbox\OutboxEvent;
use Illuminate\Console\Command;

/**
 * Cron fallback dispatcher for the transactional outbox (decision D13):
 * picks rows that are pending, due and still have attempts left, and
 * runs them synchronously. The queue worker (erp:outbox:dispatch via
 * queue) does the same thing in a web/worker deployment — this command
 * exists so NOTHING depends on a queue being configured (Rule 18).
 */
class OutboxDispatchCommand extends Command
{
    protected $signature = 'erp:outbox:dispatch {--limit=100 : Maximum rows to process}';

    protected $description = 'Dispatch due pending outbox events';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $ids = OutboxEvent::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                ProcessOutboxEvent::dispatchSync($id);
                $dispatched++;
            } catch (\Throwable $e) {
                $failed++; // job records its own attempt/last_error truthfully
                $this->line("  ! outbox #{$id}: {$e->getMessage()}");
            }
        }

        $this->components->twoColumnDetail('Dispatched', (string) $dispatched);
        $this->components->twoColumnDetail('Failed this run', (string) $failed);

        return $failed > 0 && $dispatched === 0 ? self::FAILURE : self::SUCCESS;
    }
}
