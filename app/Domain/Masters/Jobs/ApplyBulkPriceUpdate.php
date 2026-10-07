<?php

namespace App\Domain\Masters\Jobs;

use App\Domain\Masters\Actions\BulkPriceUpdate;
use App\Domain\Masters\PriceBulkUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Applies one bulk price update batch. Failures park the batch as
 * `failed` with the error recorded — never a fake success.
 */
class ApplyBulkPriceUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $bulkUpdateId) {}

    public function handle(): void
    {
        $batch = PriceBulkUpdate::query()->find($this->bulkUpdateId);

        if ($batch === null || $batch->status === PriceBulkUpdate::STATUS_APPLIED) {
            return;
        }

        try {
            app(BulkPriceUpdate::class)->execute($batch);
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => PriceBulkUpdate::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();
        }
    }
}
