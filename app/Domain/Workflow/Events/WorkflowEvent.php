<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Outbox\Contracts\ArrayPayload;

abstract class WorkflowEvent implements ArrayPayload
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $branchId,
        public readonly int $requestId,
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $action,
        public readonly string $subject,
        public readonly ?float $amount,
        public readonly int $submittedBy,
        public readonly ?int $actorId = null,
        public readonly ?string $comment = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $payload): static
    {
        return new static(
            companyId: (int) $payload['companyId'],
            branchId: (int) $payload['branchId'],
            requestId: (int) $payload['requestId'],
            entityType: (string) $payload['entityType'],
            entityId: (int) $payload['entityId'],
            action: (string) $payload['action'],
            subject: (string) $payload['subject'],
            amount: $payload['amount'] !== null ? (float) $payload['amount'] : null,
            submittedBy: (int) $payload['submittedBy'],
            actorId: $payload['actorId'] !== null ? (int) $payload['actorId'] : null,
            comment: $payload['comment'],
        );
    }
}
