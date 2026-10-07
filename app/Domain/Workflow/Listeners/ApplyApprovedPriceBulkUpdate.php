<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Masters\Actions\BulkPriceUpdate;
use App\Domain\Masters\Jobs\ApplyBulkPriceUpdate;
use App\Domain\Masters\PriceBulkUpdate;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowEvent;

/**
 * When a price bulk update approval is granted, re-queue the batch so it
 * applies under the approved request (idempotent: only batches still
 * holding in pending_approval are re-dispatched).
 */
class ApplyApprovedPriceBulkUpdate
{
    public function handle(WorkflowEvent $event): void
    {
        if (! $event instanceof WorkflowApproved) {
            return;
        }

        $approval = ApprovalRequest::query()->find($event->requestId);

        if ($approval === null
            || $approval->entity_type !== BulkPriceUpdate::ENTITY_TYPE
            || $approval->action !== BulkPriceUpdate::APPROVAL_ACTION
            || $approval->status !== 'approved') {
            return;
        }

        $batch = PriceBulkUpdate::query()->find($approval->entity_id);

        if ($batch === null || $batch->status !== PriceBulkUpdate::STATUS_PENDING) {
            return;
        }

        ApplyBulkPriceUpdate::dispatch($batch->id);
    }
}
