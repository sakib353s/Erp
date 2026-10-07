<?php

namespace App\Domain\Sales\Listeners;

use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Sales\Events\QuotationSent;
use App\Domain\Sales\Quotation;

/**
 * Outbox-driven in-app trace of a quotation delivery: the creator sees the
 * channel and recipient that were actually used (retry-safe via dedupe key).
 */
class NotifyCreatorOnQuotationSent
{
    public function __construct(protected NotificationCenter $notifications) {}

    public function handle(QuotationSent $event): void
    {
        $quotation = Quotation::query()->find($event->quotationId);

        if ($quotation === null || $quotation->created_by === null) {
            return;
        }

        $creator = User::query()->find($quotation->created_by);

        if ($creator === null || ! $creator->isActive()) {
            return;
        }

        $this->notifications->notify(
            $creator,
            'sales.quotation_sent',
            "{$event->quoteNo} sent via {$event->channel}",
            trim(
                "Addressed to {$event->to}."
                .($event->shareUrl !== null ? " Share link: {$event->shareUrl}" : '')
            ),
            [
                'action_url' => '/app/sales/quotations?status=sent',
                'dedupe_key' => "quotation.{$event->quotationId}.sent.{$event->revision}.{$event->channel}.{$event->to}",
                'data' => [
                    'quotation_id' => $event->quotationId,
                    'quote_no' => $event->quoteNo,
                    'channel' => $event->channel,
                    'share_url' => $event->shareUrl,
                ],
            ],
        );
    }
}
