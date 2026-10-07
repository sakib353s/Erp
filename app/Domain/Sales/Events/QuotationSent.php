<?php

namespace App\Domain\Sales\Events;

use App\Domain\Outbox\Contracts\ArrayPayload;

/** A quotation was handed to a delivery channel through the outbox. */
class QuotationSent implements ArrayPayload
{
    public function __construct(
        public readonly int $companyId,
        public readonly ?int $branchId,
        public readonly int $quotationId,
        public readonly string $quoteNo,
        public readonly int $revision,
        public readonly string $channel,
        public readonly string $to,
        public readonly ?int $customerId,
        public readonly string $subject,
        public readonly ?string $shareUrl = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $payload): static
    {
        return new static(
            companyId: (int) $payload['companyId'],
            branchId: $payload['branchId'] !== null ? (int) $payload['branchId'] : null,
            quotationId: (int) $payload['quotationId'],
            quoteNo: (string) $payload['quoteNo'],
            revision: (int) $payload['revision'],
            channel: (string) $payload['channel'],
            to: (string) $payload['to'],
            customerId: $payload['customerId'] !== null ? (int) $payload['customerId'] : null,
            subject: (string) $payload['subject'],
            shareUrl: isset($payload['shareUrl']) && $payload['shareUrl'] !== null
                ? (string) $payload['shareUrl']
                : null,
        );
    }
}
